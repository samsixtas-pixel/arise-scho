<?php
class BackupManager
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function latestBackupTimestamp(): int
    {
        $timestamp = $this->db->query(
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM backup_history'
        )->fetchColumn();

        return $timestamp === false || $timestamp === null ? 0 : (int) $timestamp;
    }

    public function recordsSinceLastBackup(): int
    {
        $recordCount = 0;
        $tables = $this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if ($table === 'backup_history') {
                continue;
            }

            $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
            $recordCount += (int) $this->db
                ->query("SELECT COUNT(*) FROM {$quotedTable}")
                ->fetchColumn();
        }

        return $recordCount;
    }

    public function recordBackup(string $filename, int $fileSize, string $reason, int $recordCount): void
    {
        $allowedReasons = ['manual', 'auto_14day', 'auto_first', 'auto_checked'];
        if (!in_array($reason, $allowedReasons, true)) {
            throw new InvalidArgumentException('Invalid backup trigger reason.');
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $this->db->prepare(
            'INSERT INTO backup_history
                (filename, file_size, trigger_reason, user_id, records_at_backup, last_check_at)
             VALUES
                (:filename, :file_size, :trigger_reason, :user_id, :records_at_backup, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'filename' => $filename,
            'file_size' => $fileSize,
            'trigger_reason' => $reason,
            'user_id' => $userId,
            'records_at_backup' => $recordCount,
        ]);
    }
}
