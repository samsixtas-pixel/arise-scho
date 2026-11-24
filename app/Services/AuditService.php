<?php
declare(strict_types=1);

namespace School\Services;

use School\Core\SchoolDatabase;

final class AuditService
{
    public function record(?int $actorId, string $action, string $subjectType, ?int $subjectId, array $metadata = []): void
    {
        $query = SchoolDatabase::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, metadata_json, created_at) VALUES (:actor, :action, :type, :subject, :metadata, UTC_TIMESTAMP())'
        );
        $query->execute([
            'actor' => $actorId,
            'action' => $action,
            'type' => $subjectType,
            'subject' => $subjectId,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }
}
