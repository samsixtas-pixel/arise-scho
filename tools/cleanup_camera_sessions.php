<?php
/**
 * tools/cleanup_camera_sessions.php
 * -------------------------------------------------------
 * CLI/cron maintenance for capture sessions and private files.
 *
 * Examples:
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --minutes=60 --dry-run
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --stats
 *   /opt/lampp/bin/php tools/cleanup_camera_sessions.php --integrity
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/autoload.php';

$options = getopt('', ['minutes::', 'dry-run', 'stats', 'integrity', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/cleanup_camera_sessions.php [--minutes=120] [--dry-run] [--stats] [--integrity]\n";
    exit(0);
}

$minutes = isset($options['minutes']) ? max(15, (int) $options['minutes']) : 120;
$dryRun  = array_key_exists('dry-run', $options);
$service = new DocumentCleanupService();

try {
    if (array_key_exists('stats', $options)) {
        $report = $service->storageReport();
        echo "Private storage\n";
        echo '  files:  ' . number_format($report['private_files']) . "\n";
        echo '  size:   ' . formatBytes($report['private_bytes']) . "\n";
        echo '  active documents: ' . number_format($report['documents'])
           . ' (' . formatBytes($report['document_bytes']) . ")\n";
        echo "Capture sessions\n";
        foreach ($report['sessions'] as $row) {
            echo '  ' . str_pad((string) $row['status'], 12) . $row['n'] . "\n";
        }
        exit(0);
    }

    if (array_key_exists('integrity', $options)) {
        $integrity = $service->checkDocumentIntegrity();
        echo 'Checked:     ' . $integrity['checked'] . "\n";
        echo 'Missing:     ' . $integrity['missing'] . "\n";
        echo 'Mismatched:  ' . $integrity['mismatched'] . "\n";

        foreach ($integrity['rows'] as $row) {
            echo '  document #' . $row['id'] . ' — ' . $row['issue'] . "\n";
        }

        exit($integrity['missing'] || $integrity['mismatched'] ? 1 : 0);
    }

    echo $dryRun ? "DRY RUN — nothing will be deleted\n" : "Cleaning up\n";

    $sessions = $service->cleanupCameraSessions($minutes, $dryRun);
    echo 'Expired sessions: ' . $sessions['expired'] . "\n";
    echo 'Sessions removed: ' . $sessions['removed'] . "\n";

    $orphans = $service->cleanupOrphanTemporaryFiles($minutes * 2, $dryRun);
    echo 'Temporary files scanned: ' . $orphans['scanned'] . "\n";
    echo 'Orphan files removed:    ' . $orphans['removed']
       . ' (' . formatBytes($orphans['bytes']) . ")\n";

    $report = $service->storageReport();
    echo 'Private storage now: ' . formatBytes($report['private_bytes']) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}
