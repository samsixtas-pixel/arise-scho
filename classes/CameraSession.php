<?php
/**
 * CameraSession.php
 * -------------------------------------------------------
 * Temporary, high-entropy capture sessions that let a phone
 * act as a remote camera for the signed-in officer's PC.
 *
 * Only a SHA-256 hash of the token is stored. The token grants
 * access to one upload slot — never to customer records or to
 * the officer's session.
 * -------------------------------------------------------
 */

class CameraSession extends Model
{
    public const TTL_SECONDS   = 900;     // 15 minutes
    public const ASSET_TTL     = 600;     // signed preview link
    public const MAX_ASSETS    = 10;      // retakes per session

    public const PURPOSES = [
        'portrait' => 'Customer portrait',
        'passport' => 'Passport identity page',
        'nida'     => 'NIDA card',
        'document' => 'Supporting document',
    ];

    protected function getTable(): string
    {
        return 'camera_sessions';
    }

    /**
     * Create a session and return the one-time token + capture URL.
     *
     * @return array{id:int,uuid:string,token:string,url:string,expires_at:string}
     */
    public function create(int $officerId, string $purpose, ?string $draftId = null, int $ttl = self::TTL_SECONDS): array
    {
        if ($officerId <= 0) {
            throw new RuntimeException('A signed-in officer is required.');
        }

        $purpose = array_key_exists($purpose, self::PURPOSES) ? $purpose : 'portrait';
        $ttl     = max(120, min(1800, $ttl));
        $token   = bin2hex(random_bytes(32));
        $uuid    = self::uuid();
        $draftId = $draftId !== null ? preg_replace('/[^A-Za-z0-9_\-]/', '', $draftId) : null;

        $expires = date('Y-m-d H:i:s', time() + $ttl);

        $stmt = $this->db->prepare(
            "INSERT INTO camera_sessions
                (session_uuid, token_hash, officer_id, registration_draft_id,
                 capture_purpose, status, expires_at)
             VALUES
                (:uuid, :hash, :officer, :draft, :purpose, 'pending', :expires)"
        );

        $stmt->execute([
            'uuid'    => $uuid,
            'hash'    => hash('sha256', $token),
            'officer' => $officerId,
            'draft'   => $draftId !== '' ? $draftId : null,
            'purpose' => $purpose,
            'expires' => $expires,
        ]);

        $id = (int) $this->lastInsertId();

        AuditLog::record('camera_session.create', 'camera_sessions', $id,
            'Created ' . $purpose . ' capture session');

        return [
            'id'         => $id,
            'uuid'       => $uuid,
            'token'      => $token,
            'url'        => self::captureUrl($token),
            'expires_at' => $expires,
            'purpose'    => $purpose,
        ];
    }

    /** Find by raw token (hashes before touching the database). */
    public function findByToken(string $token): array|false
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM camera_sessions WHERE token_hash = :hash LIMIT 1"
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);

        return $stmt->fetch();
    }

    /**
     * Token lookup for the phone page. Expired sessions are
     * marked expired and rejected.
     */
    public function activeByToken(string $token): array|false
    {
        $session = $this->findByToken($token);

        if (!$session) {
            return false;
        }

        if ($session['status'] === 'expired') {
            return false;
        }

        if (in_array($session['status'], ['pending', 'uploaded'], true)
            && strtotime($session['expires_at']) < time()) {
            $this->expire((int) $session['id']);
            return false;
        }

        return $session;
    }

    /** Session owned by this officer (PC endpoints). */
    public function findOwned(int $id, int $officerId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM camera_sessions
              WHERE id = :id AND officer_id = :officer
              LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'officer' => $officerId]);

        return $stmt->fetch();
    }

    /**
     * Status payload for PC polling.
     *
     * @return array{id:int,status:string,expires_at:string,asset:?array}
     */
    public function statusFor(int $id, int $officerId): array
    {
        $session = $this->findOwned($id, $officerId);

        if (!$session) {
            throw new RuntimeException('Capture session not found.');
        }

        if (in_array($session['status'], ['pending', 'uploaded'], true)
            && strtotime($session['expires_at']) < time()) {
            $this->expire($id);
            $session['status'] = 'expired';
        }

        $asset = $this->latestAsset($id);

        return [
            'id'         => (int) $session['id'],
            'status'     => $session['status'],
            'purpose'    => $session['capture_purpose'],
            'expires_at' => $session['expires_at'],
            'asset'      => $asset ? [
                'id'        => (int) $asset['id'],
                'width'     => (int) ($asset['width'] ?? 0),
                'height'    => (int) ($asset['height'] ?? 0),
                'bytes'     => (int) ($asset['file_size_bytes'] ?? 0),
                'created_at'=> $asset['created_at'],
                'url'       => self::assetUrl((int) $asset['id']),
            ] : null,
        ];
    }

    /**
     * Store (or replace) the phone's image for this session.
     *
     * @param array{storage_key:string,thumbnail_key:?string,width:int,height:int,bytes:int,checksum:string,mime:string} $image
     */
    public function saveAsset(int $sessionId, array $image): int
    {
        $session = $this->find($sessionId);

        if (!$session) {
            throw new RuntimeException('Capture session not found.');
        }

        if (!in_array($session['status'], ['pending', 'uploaded'], true)) {
            throw new RuntimeException('This capture session is no longer accepting images.');
        }

        $existing = $this->latestAsset($sessionId, ['uploaded', 'processed']);

        if ($existing) {
            DocumentStorage::deleteKey($existing['temporary_storage_key']);
            DocumentStorage::deleteKey($existing['thumbnail_key']);

            $this->db->prepare(
                "UPDATE camera_session_assets SET
                    temporary_storage_key = :key,
                    thumbnail_key = :thumb,
                    processing_status = 'processed',
                    mime_type = :mime,
                    file_size_bytes = :bytes,
                    width = :width,
                    height = :height,
                    checksum = :checksum,
                    error_message = NULL,
                    created_at = NOW()
                 WHERE id = :id"
            )->execute([
                'key'      => $image['storage_key'],
                'thumb'    => $image['thumbnail_key'],
                'mime'     => $image['mime'],
                'bytes'    => $image['bytes'],
                'width'    => $image['width'],
                'height'   => $image['height'],
                'checksum' => $image['checksum'],
                'id'       => (int) $existing['id'],
            ]);

            $assetId = (int) $existing['id'];
        } else {
            $this->db->prepare(
                "INSERT INTO camera_session_assets
                    (camera_session_id, temporary_storage_key, thumbnail_key, processing_status,
                     mime_type, file_size_bytes, width, height, checksum)
                 VALUES
                    (:session, :key, :thumb, 'processed', :mime, :bytes, :width, :height, :checksum)"
            )->execute([
                'session'  => $sessionId,
                'key'      => $image['storage_key'],
                'thumb'    => $image['thumbnail_key'],
                'mime'     => $image['mime'],
                'bytes'    => $image['bytes'],
                'width'    => $image['width'],
                'height'   => $image['height'],
                'checksum' => $image['checksum'],
            ]);

            $assetId = (int) $this->lastInsertId();
        }

        $this->db->prepare(
            "UPDATE camera_sessions SET status = 'uploaded' WHERE id = :id"
        )->execute(['id' => $sessionId]);

        AuditLog::record('camera_session.upload', 'camera_sessions', $sessionId,
            'Phone uploaded an image (' . $image['width'] . '×' . $image['height'] . ')');

        return $assetId;
    }

    /** Mark the session complete after the PC accepts the image. */
    public function complete(int $id, int $officerId): void
    {
        $session = $this->findOwned($id, $officerId);

        if (!$session) {
            throw new RuntimeException('Capture session not found.');
        }

        $this->db->prepare(
            "UPDATE camera_sessions
                SET status = 'completed', completed_at = NOW()
              WHERE id = :id AND status IN ('uploaded','pending')"
        )->execute(['id' => $id]);

        $this->db->prepare(
            "UPDATE camera_session_assets
                SET processing_status = 'accepted', accepted_at = NOW()
              WHERE camera_session_id = :id AND processing_status IN ('uploaded','processed')"
        )->execute(['id' => $id]);

        AuditLog::record('camera_session.complete', 'camera_sessions', $id,
            'Capture accepted on the office computer');
    }

    public function cancel(int $id, int $officerId): void
    {
        $session = $this->findOwned($id, $officerId);

        if (!$session) {
            throw new RuntimeException('Capture session not found.');
        }

        $this->db->prepare(
            "UPDATE camera_sessions
                SET status = 'cancelled', cancelled_at = NOW()
              WHERE id = :id AND status IN ('pending','uploaded')"
        )->execute(['id' => $id]);

        AuditLog::record('camera_session.cancel', 'camera_sessions', $id,
            'Capture session cancelled');
    }

    /** Renew the same token/QR — no new link needs to be scanned. */
    public function renew(int $id, int $officerId, int $ttl = self::TTL_SECONDS): string
    {
        $session = $this->findOwned($id, $officerId);

        if (!$session) {
            throw new RuntimeException('Capture session not found.');
        }

        $expires = date('Y-m-d H:i:s', time() + max(120, min(1800, $ttl)));

        $this->db->prepare(
            "UPDATE camera_sessions
                SET status = 'pending', expires_at = :expires, cancelled_at = NULL
              WHERE id = :id AND status IN ('expired','cancelled','pending','uploaded')"
        )->execute(['id' => $id, 'expires' => $expires]);

        AuditLog::record('camera_session.renew', 'camera_sessions', $id,
            'Capture session renewed');

        return $expires;
    }

    /** Expire stale sessions (safe to call before polling). */
    public function expireStale(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE camera_sessions
                SET status = 'expired'
              WHERE status IN ('pending','uploaded') AND expires_at < NOW()"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Remove sessions and temporary files past their retention.
     * Never touches completed sessions that were accepted into a
     * customer record (the final document copy lives elsewhere).
     */
    public function cleanup(int $olderThanMinutes = 120): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(15, $olderThanMinutes) * 60);

        $stmt = $this->db->prepare(
            "SELECT id FROM camera_sessions
              WHERE status IN ('cancelled','expired','completed')
                AND COALESCE(completed_at, cancelled_at, expires_at) < :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!$ids) {
            return 0;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        $assets = $this->db->prepare(
            "SELECT temporary_storage_key, thumbnail_key
               FROM camera_session_assets
              WHERE camera_session_id IN ($in)"
        );
        $assets->execute($ids);

        foreach ($assets->fetchAll() as $asset) {
            DocumentStorage::deleteKey($asset['temporary_storage_key']);
            DocumentStorage::deleteKey($asset['thumbnail_key']);
        }

        $this->db->prepare("DELETE FROM camera_session_assets WHERE camera_session_id IN ($in)")
                 ->execute($ids);

        $delete = $this->db->prepare("DELETE FROM camera_sessions WHERE id IN ($in)");
        $delete->execute($ids);

        return $delete->rowCount();
    }

    /** Latest image, optionally filtered by processing status. */
    public function latestAsset(int $sessionId, ?array $statuses = null): array|false
    {
        $sql    = "SELECT * FROM camera_session_assets WHERE camera_session_id = :id";
        $params = ['id' => $sessionId];

        if ($statuses) {
            $placeholders = [];
            foreach (array_values($statuses) as $i => $status) {
                $key = 's' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $sql .= ' AND processing_status IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch();
    }

    private function expire(int $id): void
    {
        $this->db->prepare(
            "UPDATE camera_sessions SET status = 'expired' WHERE id = :id AND status IN ('pending','uploaded')"
        )->execute(['id' => $id]);
    }

    /** Signed, expiring media URL for the PC preview. */
    public static function assetUrl(int $assetId, int $ttl = self::ASSET_TTL): string
    {
        $exp = time() + max(60, $ttl);
        $sig = hash_hmac('sha256', 'camera-asset:' . $assetId . ':' . $exp, SESSION_FINGERPRINT_SECRET);

        return BASE_URL . 'media.php?asset=' . $assetId . '&exp=' . $exp . '&sig=' . $sig;
    }

    public static function verifyAssetSignature(int $assetId, int $exp, string $sig): bool
    {
        if ($exp < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', 'camera-asset:' . $assetId . ':' . $exp, SESSION_FINGERPRINT_SECRET);

        return hash_equals($expected, $sig);
    }

    /** Absolute HTTPS/HTTP capture URL encoded in the QR code. */
    public static function captureUrl(string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' ? 'https' : 'http');

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . BASE_URL . 'capture.php?t=' . $token;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
