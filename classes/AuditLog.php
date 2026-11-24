<?php
/**
 * AuditLog.php
 * -------------------------------------------------------
 * Append-only trail of every meaningful action. Written in
 * the same transaction as the change it describes wherever
 * a transaction is available.
 * -------------------------------------------------------
 */

class AuditLog extends Model
{
    protected function getTable(): string
    {
        return 'audit_log';
    }

    /**
     * Record an action.
     *
     * @param string      $action     machine name, e.g. payment.create
     * @param string|null $entityType customers|loans|payments|users|settings
     * @param int|null    $entityId
     * @param string|null $details    human readable summary
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $details = null
    ): void {
        try {
            $db = Database::getConnection();

            $stmt = $db->prepare(
                "INSERT INTO audit_log
                    (user_id, username, action, entity_type, entity_id, details, ip_address, user_agent)
                 VALUES
                    (:user_id, :username, :action, :entity_type, :entity_id, :details, :ip, :ua)"
            );

            $stmt->execute([
                'user_id'     => currentUserId() ?: null,
                'username'    => $_SESSION['username'] ?? null,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'details'     => $details,
                'ip'          => clientIp(),
                'ua'          => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog failed: ' . $e->getMessage());
        }
    }

    /** Recent entries with optional filters. */
    public function recent(int $limit = 100, ?string $action = null, ?string $entityType = null, ?string $search = null): array
    {
        $sql    = "SELECT * FROM audit_log WHERE 1=1";
        $params = [];

        if ($action) {
            $sql .= " AND action LIKE :action";
            $params['action'] = $action . '%';
        }

        if ($entityType) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $entityType;
        }

        if ($search) {
            $sql .= " AND (details LIKE :q OR username LIKE :q OR action LIKE :q)";
            $params['q'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY id DESC LIMIT " . max(1, min(500, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
