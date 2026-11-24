<?php
declare(strict_types=1);

namespace School\Policies;

use School\Core\SchoolDatabase;

final class AuthorizationPolicy
{
    public function hasPermission(array $user, string $permission): bool
    {
        $query = SchoolDatabase::connection()->prepare('SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=:user AND p.permission_key=:permission LIMIT 1');
        $query->execute(['user' => (int) $user['id'], 'permission' => $permission]);
        return (bool) $query->fetchColumn();
    }

    public function allows(array $user, string $permission, array $context = []): bool
    {
        $pdo = SchoolDatabase::connection();
        if (!$this->hasPermission($user, $permission)) return false;

        // Child and assignment policies require an explicit record context.
        $parentScoped = $this->isParent($user) && in_array($permission, ['portal.child','students.view','finance.view','attendance.view','results.view_published','homework.view'], true);
        if ($parentScoped && !isset($context['student_id'])) return false;
        if ($parentScoped && isset($context['student_id'])) {
            $query = $pdo->prepare("SELECT 1 FROM student_guardians sg JOIN guardians g ON g.id = sg.guardian_id WHERE g.user_id = :user AND sg.student_id = :student AND sg.verification_status = 'verified' AND sg.ended_at IS NULL LIMIT 1");
            $query->execute(['user' => (int) $user['id'], 'student' => (int) $context['student_id']]);
            return (bool) $query->fetchColumn();
        }
        $teacherScoped = in_array($permission, ['students.view','attendance.view','attendance.manage','homework.manage','results.marks.enter','results.review','analytics.assigned'], true);
        if ($this->isTeacher($user) && $teacherScoped && !isset($context['class_group_id'], $context['academic_year_id'])) return false;
        if ($this->isTeacher($user) && isset($context['class_group_id'])) {
            $query = $pdo->prepare("SELECT 1 FROM teacher_assignments ta JOIN staff s ON s.id = ta.staff_id WHERE s.user_id = :user AND ta.class_group_id = :class AND ta.academic_year_id = :year AND ta.ended_at IS NULL AND s.employment_status = 'active' LIMIT 1");
            $query->execute(['user' => (int) $user['id'], 'class' => (int) $context['class_group_id'], 'year' => (int) ($context['academic_year_id'] ?? 0)]);
            return (bool) $query->fetchColumn();
        }
        if ($this->hasRole($user, ['student']) && in_array($permission, ['portal.self','attendance.view','results.review','results.view_published','homework.view','finance.view'], true)) {
            if (!isset($context['student_id'])) return false;
            $query = $pdo->prepare('SELECT 1 FROM students WHERE id = :student AND user_id = :user LIMIT 1');
            $query->execute(['student' => (int) $context['student_id'], 'user' => (int) $user['id']]);
            return (bool) $query->fetchColumn();
        }
        return true;
    }

    private function hasRole(array $user, array $roles): bool
    {
        foreach ($user['roles'] ?? [] as $role) if (in_array($role['name'], $roles, true)) return true;
        return false;
    }
    private function isParent(array $user): bool { return $this->hasRole($user, ['parent_guardian']); }
    private function isTeacher(array $user): bool { return $this->hasRole($user, ['class_teacher', 'subject_teacher']); }
}
