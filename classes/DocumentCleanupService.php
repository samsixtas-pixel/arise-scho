<?php
/**
 * DocumentCleanupService.php
 * -------------------------------------------------------
 * Maintenance operations for temporary capture files and the
 * Customer Digital File. Safe to run from cron/CLI; never
 * deletes documents that belong to saved customers.
 * -------------------------------------------------------
 */

class DocumentCleanupService
{
    private PDO $db;
    private CameraSession $sessions;

    public function __construct()
    {
        $this->db       = Database::getConnection();
        $this->sessions = new CameraSession();
    }

    /**
     * Expire stale sessions then remove old cancelled/expired/completed
     * sessions and their temporary files.
     *
     * @return array{expired:int,removed:int,dry_run:bool}
     */
    public function cleanupCameraSessions(int $olderThanMinutes = 120, bool $dryRun = false): array
    {
        $expired = $this->sessions->expireStale();

        if ($dryRun) {
            $cutoff = date('Y-m-d H:i:s', time() - max(15, $olderThanMinutes) * 60);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM camera_sessions
                  WHERE status IN ('cancelled','expired','completed')
                    AND COALESCE(completed_at, cancelled_at, expires_at) < :cutoff"
            );
            $stmt->execute(['cutoff' => $cutoff]);

            return ['expired' => $expired, 'removed' => (int) $stmt->fetchColumn(), 'dry_run' => true];
        }

        return [
            'expired' => $expired,
            'removed' => $this->sessions->cleanup($olderThanMinutes),
            'dry_run' => false,
        ];
    }

    /**
     * Delete files under private/temporary that no camera asset row
     * references and that are older than the retention window.
     *
     * @return array{scanned:int,removed:int,bytes:int,dry_run:bool}
     */
    public function cleanupOrphanTemporaryFiles(int $olderThanMinutes = 240, bool $dryRun = false): array
    {
        $root = realpath(PRIVATE_DIR . '/temporary');

        if ($root === false) {
            return ['scanned' => 0, 'removed' => 0, 'bytes' => 0, 'dry_run' => $dryRun];
        }

        $referenced = [];
        $rows = $this->db->query(
            "SELECT temporary_storage_key, thumbnail_key FROM camera_session_assets"
        )->fetchAll();

        foreach ($rows as $row) {
            foreach (['temporary_storage_key', 'thumbnail_key'] as $key) {
                if (!empty($row[$key])) {
                    $absolute = DocumentStorage::absolutePath($row[$key]);
                    if ($absolute !== null) {
                        $referenced[$absolute] = true;
                    }
                }
            }
        }

        $cutoff  = time() - max(30, $olderThanMinutes) * 60;
        $scanned = 0;
        $removed = 0;
        $bytes   = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!$dryRun && $item->getMTime() < $cutoff) {
                    @rmdir($item->getPathname());
                }
                continue;
            }

            $scanned++;
            $path = $item->getRealPath();

            if ($path === false || isset($referenced[$path]) || $item->getMTime() >= $cutoff) {
                continue;
            }

            $bytes += (int) $item->getSize();
            $removed++;

            if (!$dryRun) {
                @unlink($path);
            }
        }

        return ['scanned' => $scanned, 'removed' => $removed, 'bytes' => $bytes, 'dry_run' => $dryRun];
    }

    /** Storage usage and document counts. */
    public function storageReport(): array
    {
        $privateBytes = 0;
        $privateFiles = 0;
        $root = realpath(PRIVATE_DIR);

        if ($root !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $privateFiles++;
                    $privateBytes += (int) $item->getSize();
                }
            }
        }

        $docs = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(file_size_bytes), 0) AS bytes
               FROM customer_documents
              WHERE deleted_at IS NULL AND document_status = 'active'"
        )->fetch();

        $sessions = $this->db->query(
            "SELECT status, COUNT(*) AS n FROM camera_sessions GROUP BY status"
        )->fetchAll();

        return [
            'private_files'  => $privateFiles,
            'private_bytes'  => $privateBytes,
            'documents'      => (int) ($docs['total'] ?? 0),
            'document_bytes' => (int) ($docs['bytes'] ?? 0),
            'sessions'       => $sessions,
        ];
    }

    /**
     * Verify that active documents still have readable files and
     * that checksums match where recorded.
     *
     * @return array{checked:int,missing:int,mismatched:int,rows:array}
     */
    public function checkDocumentIntegrity(int $limit = 500): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, customer_id, storage_key, path, checksum, file_size_bytes
               FROM customer_documents
              WHERE deleted_at IS NULL AND document_status = 'active'
           ORDER BY id DESC LIMIT " . max(1, min(5000, $limit))
        );
        $stmt->execute();

        $checked = 0;
        $missing = 0;
        $mismatched = 0;
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $checked++;
            $key = $row['storage_key'] ?: $row['path'];
            $absolute = DocumentStorage::absolutePath($key);

            if ($absolute === null && !empty($row['path'])) {
                // Pre-migration rows may point at the old upload folders.
                $absolute = DocumentStorage::legacyAbsolutePath($row['path']);
            }

            if ($absolute === null) {
                $missing++;
                $rows[] = ['id' => (int) $row['id'], 'issue' => 'missing'];
                continue;
            }

            if (!empty($row['checksum'])) {
                $actual = hash_file('sha256', $absolute);
                if (!hash_equals((string) $row['checksum'], (string) $actual)) {
                    $mismatched++;
                    $rows[] = ['id' => (int) $row['id'], 'issue' => 'checksum'];
                }
            }
        }

        return ['checked' => $checked, 'missing' => $missing, 'mismatched' => $mismatched, 'rows' => $rows];
    }
}
