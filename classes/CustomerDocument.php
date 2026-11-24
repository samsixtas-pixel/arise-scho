<?php
/**
 * CustomerDocument.php
 * -------------------------------------------------------
 * Customer Digital File metadata + private image storage.
 * One row per logical document; replacements add a version
 * instead of overwriting history.
 * -------------------------------------------------------
 */

class CustomerDocument extends Model
{
    public const CATEGORIES = [
        'portrait'        => 'Customer portrait',
        'passport'        => 'Passport',
        'nida'            => 'NIDA / national ID',
        'other_id'        => 'Other identification',
        'loan_application'=> 'Loan application',
        'guarantor'       => 'Guarantor documents',
        'agreement'       => 'Loan agreements',
        'supporting'      => 'Supporting evidence',
        'other'           => 'Other attachments',
    ];

    public const FOLDERS = [
        'portrait'         => 'portrait',
        'passport'         => 'identity',
        'nida'             => 'identity',
        'other_id'         => 'identity',
        'loan_application' => 'loan_documents',
        'guarantor'        => 'loan_documents',
        'agreement'        => 'loan_documents',
        'supporting'       => 'supporting_documents',
        'other'            => 'supporting_documents',
    ];

    protected function getTable(): string
    {
        return 'customer_documents';
    }

    /** Active documents for a customer, newest first within each category. */
    public function forCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, u.full_name AS uploaded_by_name,
                    (SELECT COUNT(*) FROM document_versions v WHERE v.document_id = d.id) AS version_count
               FROM customer_documents d
          LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.customer_id = :id
                AND d.deleted_at IS NULL
                AND d.document_status <> 'deleted'
           ORDER BY FIELD(d.document_category,
                    'portrait','passport','nida','other_id','loan_application',
                    'guarantor','agreement','supporting','other'), d.id DESC"
        );
        $stmt->execute(['id' => $customerId]);

        return $stmt->fetchAll();
    }

    /** One active document with its customer reference. */
    public function findDetailed(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, c.reference AS customer_reference, c.full_name AS customer_name,
                    u.full_name AS uploaded_by_name
               FROM customer_documents d
               JOIN customers c ON c.id = d.customer_id
          LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.id = :id AND d.deleted_at IS NULL
              LIMIT 1"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    /**
     * Upload a new document image.
     *
     * @param array $file $_FILES entry
     */
    public function store(
        int $customerId,
        array $file,
        string $category,
        ?string $issueDate = null,
        ?string $expiryDate = null,
        ?string $docType = null
    ): int {
        $customer = (new Customer())->find($customerId);

        if (!$customer) {
            throw new ValidationException([t('Customer not found.')]);
        }

        $category = array_key_exists($category, self::CATEGORIES) ? $category : 'supporting';
        $folder   = 'customers/' . $customer['reference'] . '/' . self::FOLDERS[$category];
        $maxEdge  = $category === 'portrait' ? 1200 : 2200;
        $image    = DocumentStorage::storeUpload($file, $folder, $maxEdge, $category === 'portrait' ? 88 : 86);

        return $this->insertDocument($customer, $image, $category, $issueDate, $expiryDate, $docType);
    }

    /**
     * Store a document captured with a camera (data URL). Used by the
     * smartphone capture page and by the PC QR pairing flow.
     */
    public function storeCaptured(
        int $customerId,
        string $dataUrl,
        string $category,
        ?string $issueDate = null,
        ?string $expiryDate = null,
        ?string $docType = null
    ): int {
        $customer = (new Customer())->find($customerId);

        if (!$customer) {
            throw new ValidationException([t('Customer not found.')]);
        }

        $category = array_key_exists($category, self::CATEGORIES) ? $category : 'supporting';
        $folder   = 'customers/' . $customer['reference'] . '/' . self::FOLDERS[$category];
        $maxEdge  = $category === 'portrait' ? 1200 : 2200;
        $image    = DocumentStorage::storeDataUrl($dataUrl, $folder, $maxEdge, $category === 'portrait' ? 88 : 86);

        return $this->insertDocument($customer, $image, $category, $issueDate, $expiryDate, $docType);
    }

    /**
     * @param array $customer customers row
     * @param array $image    DocumentStorage result
     */
    private function insertDocument(
        array $customer,
        array $image,
        string $category,
        ?string $issueDate,
        ?string $expiryDate,
        ?string $docType
    ): int {
        $customerId = (int) $customer['id'];
        $uuid       = CameraSession::uuid();
        $docType    = $docType !== null && $docType !== '' ? mb_substr($docType, 0, 40) : $category;

        try {
            $this->db->beginTransaction();

            if ($category === 'portrait') {
                $this->db->prepare(
                    "UPDATE customer_documents SET is_primary = 0
                      WHERE customer_id = :c AND document_category = 'portrait' AND is_primary = 1"
                )->execute(['c' => $customerId]);
            }

            $stmt = $this->db->prepare(
                "INSERT INTO customer_documents
                    (document_uuid, customer_id, doc_type, document_category, path, storage_key,
                     thumbnail_key, mime_type, file_size_bytes, width, height, checksum,
                     original_filename, document_status, version_number, is_primary,
                     issue_date, expiry_date, uploaded_by, uploaded_at)
                 VALUES
                    (:uuid, :customer, :doc_type, :category, :path, :storage_key,
                     :thumb, :mime, :bytes, :width, :height, :checksum,
                     :original, 'active', 1, :primary,
                     :issue, :expiry, :user, NOW())"
            );

            $stmt->execute([
                'uuid'       => $uuid,
                'customer'   => $customerId,
                'doc_type'   => $docType,
                'category'   => $category,
                'path'       => $image['storage_key'],
                'storage_key'=> $image['storage_key'],
                'thumb'      => $image['thumbnail_key'],
                'mime'       => $image['mime'],
                'bytes'      => $image['bytes'],
                'width'      => $image['width'],
                'height'     => $image['height'],
                'checksum'   => $image['checksum'],
                'original'   => $image['original_filename'] ?: null,
                'primary'    => $category === 'portrait' ? 1 : 0,
                'issue'      => $issueDate ?: null,
                'expiry'     => $expiryDate ?: null,
                'user'       => currentUserId() ?: null,
            ]);

            $id = (int) $this->lastInsertId();

            if ($category === 'portrait') {
                $this->db->prepare("UPDATE customers SET photo_path = :p WHERE id = :id")
                         ->execute(['p' => $image['storage_key'], 'id' => $customerId]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            DocumentStorage::deleteKey($image['storage_key']);
            DocumentStorage::deleteKey($image['thumbnail_key']);
            throw $e;
        }

        AuditLog::record('customer_document.upload', 'customer_documents', $id,
            'Uploaded ' . self::CATEGORIES[$category] . ' for ' . $customer['reference']);

        return $id;
    }

    /**
     * Replace the file behind a document and keep the old version.
     */
    public function replace(int $documentId, array $file, ?string $reason = null): int
    {
        $document = $this->findDetailed($documentId);

        if (!$document) {
            throw new ValidationException([t('Document not found.')]);
        }

        $category = array_key_exists((string) $document['document_category'], self::CATEGORIES)
            ? (string) $document['document_category'] : 'supporting';
        $folder  = 'customers/' . $document['customer_reference'] . '/' . self::FOLDERS[$category];
        $maxEdge = $category === 'portrait' ? 1200 : 2200;
        $image   = DocumentStorage::storeUpload($file, $folder, $maxEdge, $category === 'portrait' ? 88 : 86);

        try {
            $this->db->beginTransaction();

            $this->db->prepare(
                "INSERT INTO document_versions
                    (document_id, version_number, storage_key, thumbnail_key, mime_type,
                     file_size_bytes, checksum, replaced_by, replacement_reason)
                 VALUES
                    (:document, :version, :key, :thumb, :mime, :bytes, :checksum, :user, :reason)"
            )->execute([
                'document' => $documentId,
                'version'  => (int) $document['version_number'],
                'key'      => $document['storage_key'] ?: $document['path'],
                'thumb'    => $document['thumbnail_key'],
                'mime'     => $document['mime_type'] ?: DocumentStorage::mimeFor((string) $document['path']),
                'bytes'    => (int) ($document['file_size_bytes'] ?? 0),
                'checksum' => $document['checksum'],
                'user'     => currentUserId() ?: null,
                'reason'   => $reason ? mb_substr(trim($reason), 0, 255) : null,
            ]);

            $nextVersion = (int) $document['version_number'] + 1;

            $this->db->prepare(
                "UPDATE customer_documents SET
                    path = :path,
                    storage_key = :key,
                    thumbnail_key = :thumb,
                    mime_type = :mime,
                    file_size_bytes = :bytes,
                    width = :width,
                    height = :height,
                    checksum = :checksum,
                    original_filename = :original,
                    document_status = 'active',
                    version_number = :version,
                    uploaded_by = :user,
                    uploaded_at = NOW()
                 WHERE id = :id"
            )->execute([
                'path'     => $image['storage_key'],
                'key'      => $image['storage_key'],
                'thumb'    => $image['thumbnail_key'],
                'mime'     => $image['mime'],
                'bytes'    => $image['bytes'],
                'width'    => $image['width'],
                'height'   => $image['height'],
                'checksum' => $image['checksum'],
                'original' => $image['original_filename'] ?: null,
                'version'  => $nextVersion,
                'user'     => currentUserId() ?: null,
                'id'       => $documentId,
            ]);

            if ($category === 'portrait') {
                $this->db->prepare("UPDATE customers SET photo_path = :p WHERE id = :id")
                         ->execute(['p' => $image['storage_key'], 'id' => (int) $document['customer_id']]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            DocumentStorage::deleteKey($image['storage_key']);
            DocumentStorage::deleteKey($image['thumbnail_key']);
            throw $e;
        }

        AuditLog::record('customer_document.replace', 'customer_documents', $documentId,
            'Replaced ' . self::CATEGORIES[$category] . ' (v' . $nextVersion . ') for ' . $document['customer_reference']);

        return $nextVersion;
    }

    /** Soft-delete: files are retained for the audit/retention window. */
    public function softDelete(int $documentId): void
    {
        $document = $this->findDetailed($documentId);

        if (!$document) {
            return;
        }

        $this->db->prepare(
            "UPDATE customer_documents
                SET document_status = 'deleted', deleted_at = NOW(), is_primary = 0
              WHERE id = :id"
        )->execute(['id' => $documentId]);

        if ((string) $document['document_category'] === 'portrait') {
            $this->db->prepare(
                "UPDATE customers SET photo_path = NULL
                  WHERE id = :id AND photo_path = :path"
            )->execute([
                'id'   => (int) $document['customer_id'],
                'path' => $document['storage_key'] ?: $document['path'],
            ]);
        }

        AuditLog::record('customer_document.delete', 'customer_documents', $documentId,
            'Deleted ' . self::CATEGORIES[(string) $document['document_category']] . ' from ' . $document['customer_reference']);
    }

    /** Version history, oldest first. */
    public function versions(int $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, u.full_name AS replaced_by_name
               FROM document_versions v
          LEFT JOIN users u ON u.id = v.replaced_by
              WHERE v.document_id = :id
           ORDER BY v.version_number"
        );
        $stmt->execute(['id' => $documentId]);

        return $stmt->fetchAll();
    }

    public function findVersion(int $versionId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, d.customer_id, d.document_category, c.reference AS customer_reference
               FROM document_versions v
               JOIN customer_documents d ON d.id = v.document_id
               JOIN customers c ON c.id = d.customer_id
              WHERE v.id = :id
              LIMIT 1"
        );
        $stmt->execute(['id' => $versionId]);

        return $stmt->fetch();
    }

    /** Category counts + expiry warnings for the profile header. */
    public function summary(int $customerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT document_category, COUNT(*) AS n,
                    SUM(expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired,
                    SUM(expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS expiring
               FROM customer_documents
              WHERE customer_id = :id AND deleted_at IS NULL AND document_status = 'active'
           GROUP BY document_category"
        );
        $stmt->execute(['id' => $customerId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['document_category']] = [
                'count'    => (int) $row['n'],
                'expired'  => (int) $row['expired'],
                'expiring' => (int) $row['expiring'],
            ];
        }

        return $rows;
    }

    public static function url(int $documentId, string $size = 'thumb'): string
    {
        return BASE_URL . 'media.php?doc=' . $documentId . '&size=' . ($size === 'full' ? 'full' : 'thumb');
    }

    public static function downloadUrl(int $documentId): string
    {
        return BASE_URL . 'media.php?doc=' . $documentId . '&size=full&download=1';
    }

    public static function versionUrl(int $versionId): string
    {
        return BASE_URL . 'media.php?version=' . $versionId;
    }

    public static function label(?string $category): string
    {
        return t(self::CATEGORIES[$category ?? ''] ?? 'Other attachments');
    }

    public static function icon(?string $category): string
    {
        return match ($category) {
            'portrait'         => 'bi-person-badge',
            'passport'         => 'bi-journal-text',
            'nida'             => 'bi-person-vcard',
            'other_id'         => 'bi-card-text',
            'loan_application' => 'bi-file-earmark-text',
            'guarantor'        => 'bi-people',
            'agreement'        => 'bi-file-earmark-check',
            'supporting'       => 'bi-paperclip',
            default            => 'bi-file-earmark',
        };
    }
}
