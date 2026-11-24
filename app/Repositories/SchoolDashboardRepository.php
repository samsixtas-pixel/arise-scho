<?php
declare(strict_types=1);

namespace School\Repositories;

use DateTimeImmutable;
use School\Core\SchoolDatabase;

final class SchoolDashboardRepository
{
    public function dataFor(array $user, string $role): array
    {
        $pdo = SchoolDatabase::connection();
        $today = new DateTimeImmutable('today');
        $since = $today->modify('-13 days')->format('Y-m-d');
        $monthStart = $today->modify('first day of this month')->format('Y-m-d');
        $scope = $this->studentScope($user, $role, 'st');

        $school = $pdo->query('SELECT name, motto FROM school_profile ORDER BY id LIMIT 1')->fetch() ?: [];
        $year = $pdo->query("SELECT id,name,starts_on,ends_on FROM academic_years WHERE status='active' ORDER BY starts_on DESC LIMIT 1")->fetch() ?: null;
        $yearId = $year ? (int) $year['id'] : 0;

        $currentYearFilter = $yearId > 0 ? ' AND en.academic_year_id=' . $yearId : ' AND 1=0';
        $studentCount = $this->scalar($pdo,
            "SELECT COUNT(DISTINCT en.student_id) FROM enrollments en JOIN students st ON st.id=en.student_id WHERE en.status='active' AND st.status='active'" . $currentYearFilter . $scope['sql'],
            $scope['params']);
        $classCount = $this->scalar($pdo, "SELECT COUNT(DISTINCT en.class_group_id) FROM enrollments en JOIN students st ON st.id=en.student_id WHERE en.status='active'" . $currentYearFilter . $scope['sql'], $scope['params']);

        $attendanceScope = $this->studentScope($user, $role, 'st');
        $trendQuery = $pdo->prepare("SELECT DATE_FORMAT(sess.session_date,'%Y-%m-%d') AS day, SUM(ar.state='present') AS present_count, COUNT(*) AS marked_count FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id WHERE sess.session_date BETWEEN :since AND :today" . $attendanceScope['sql'] . ' GROUP BY sess.session_date ORDER BY sess.session_date');
        $trendQuery->execute(array_merge(['since' => $since, 'today' => $today->format('Y-m-d')], $attendanceScope['params']));
        $trendRows = [];
        foreach ($trendQuery->fetchAll() as $row) $trendRows[$row['day']] = (int) $row['marked_count'] > 0 ? round((int) $row['present_count'] * 100 / (int) $row['marked_count'], 1) : 0;
        $attendanceTrend = [];
        for ($day = $today->modify('-13 days'); $day <= $today; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $attendanceTrend[] = ['label' => $day->format('d M'), 'value' => $trendRows[$key] ?? 0];
        }

        $statusQuery = $pdo->prepare("SELECT ar.state, COUNT(*) AS total FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id WHERE sess.session_date BETWEEN :since AND :today" . $attendanceScope['sql'] . ' GROUP BY ar.state');
        $statusQuery->execute(array_merge(['since' => $since, 'today' => $today->format('Y-m-d')], $attendanceScope['params']));
        $attendanceStatus = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'early_departure' => 0];
        foreach ($statusQuery->fetchAll() as $row) $attendanceStatus[$row['state']] = (int) $row['total'];
        $palette = ['present' => '#169E6B', 'absent' => '#DC3545', 'late' => '#C98012', 'excused' => '#4F81BD', 'early_departure' => '#8064A2'];
        $attendanceSeries = [];
        foreach ($attendanceStatus as $state => $value) $attendanceSeries[] = ['label' => ucwords(str_replace('_', ' ', $state)), 'value' => $value, 'color' => $palette[$state]];
        $attendanceTotal = array_sum($attendanceStatus);
        $presentTotal = $attendanceStatus['present'];
        $todayQuery = $pdo->prepare("SELECT SUM(ar.state='present') AS present_count, COUNT(*) AS marked_count FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id WHERE sess.session_date=:today" . $attendanceScope['sql']);
        $todayQuery->execute(array_merge(['today' => $today->format('Y-m-d')], $attendanceScope['params']));
        $todayAttendance = $todayQuery->fetch() ?: ['present_count' => 0, 'marked_count' => 0];

        $feeData = ['total_charged' => 0.0, 'total_paid' => 0.0, 'total_outstanding' => 0.0, 'total_overdue' => 0.0];
        if (in_array($role, ['school_owner','school_manager','accountant','parent_guardian'], true)) {
            $invoiceScope = $this->studentScope($user, $role, 'st');
            $financeQuery = $pdo->prepare("SELECT COALESCE(SUM(inv.charged),0) total_charged, COALESCE(SUM(inv.paid),0) total_paid, COALESCE(SUM(CASE WHEN inv.due_at < :today THEN GREATEST(inv.charged-inv.paid,0) ELSE 0 END),0) total_overdue FROM (SELECT i.id,i.due_at, COALESCE((SELECT SUM(ii.amount-ii.discount) FROM invoice_items ii WHERE ii.invoice_id=i.id),0) charged, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=i.id AND p.status='succeeded'),0) paid FROM invoices i JOIN students st ON st.id=i.student_id WHERE i.status <> 'void'" . $invoiceScope['sql'] . ') inv');
            $financeQuery->execute(array_merge(['today' => $today->format('Y-m-d')], $invoiceScope['params']));
            $row = $financeQuery->fetch() ?: [];
            foreach ($feeData as $key => $_) $feeData[$key] = (float) ($row[$key] ?? 0);
            $feeData['total_outstanding'] = max(0, $feeData['total_charged'] - $feeData['total_paid']);
        }
        $feeSeries = [
            ['label' => 'Collected', 'value' => $feeData['total_paid'], 'color' => '#4F81BD'],
            ['label' => 'Outstanding', 'value' => $feeData['total_outstanding'], 'color' => '#C98012'],
            ['label' => 'Overdue', 'value' => $feeData['total_overdue'], 'color' => '#C0504D'],
        ];

        $academicSeries = [];
        $academicQuery = $pdo->prepare("SELECT sub.name AS label, ROUND(AVG(r.mark),1) AS value FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN subjects sub ON sub.id=a.subject_id JOIN students st ON st.id=r.student_id WHERE r.status IN ('approved','published')" . $scope['sql'] . ' GROUP BY sub.id,sub.name ORDER BY sub.name LIMIT 8');
        $academicQuery->execute($scope['params']);
        foreach ($academicQuery->fetchAll() as $row) $academicSeries[] = ['label' => $row['label'], 'value' => (float) $row['value']];

        $monthQuery = $pdo->prepare("SELECT DATE_FORMAT(st.admission_date,'%Y-%m') month_key, COUNT(*) total FROM students st WHERE st.admission_date >= :start AND st.admission_date <= :today" . $scope['sql'] . ' GROUP BY month_key');
        $startMonth = $today->modify('-11 months')->modify('first day of this month')->format('Y-m-d');
        $monthQuery->execute(array_merge(['start' => $startMonth, 'today' => $today->format('Y-m-d')], $scope['params']));
        $admissionMap = [];
        foreach ($monthQuery->fetchAll() as $row) $admissionMap[$row['month_key']] = (int) $row['total'];
        $admissionTrend = [];
        for ($month = $today->modify('-11 months')->modify('first day of this month'); $month <= $today; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $admissionTrend[] = ['label' => $month->format('M y'), 'value' => $admissionMap[$key] ?? 0];
        }

        $classParams = $scope['params'];
        $classQuery = $pdo->prepare("SELECT cg.name AS label, COUNT(DISTINCT en.student_id) AS value FROM enrollments en JOIN class_groups cg ON cg.id=en.class_group_id JOIN students st ON st.id=en.student_id WHERE en.status='active' AND st.status='active'" . $currentYearFilter . $scope['sql'] . ' GROUP BY cg.id,cg.name ORDER BY cg.name LIMIT 10');
        $classQuery->execute($classParams);
        $classDistribution = array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => (int) $row['value']], $classQuery->fetchAll());

        $monthCharge = $pdo->prepare("SELECT COALESCE(SUM(ii.amount-ii.discount),0) FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id JOIN students st ON st.id=i.student_id WHERE i.issued_at >= :start AND i.issued_at < :end" . $scope['sql']);
        $monthCharge->execute(array_merge(['start' => $monthStart, 'end' => $today->modify('+1 day')->format('Y-m-d')], $scope['params']));
        $monthPaidQuery = $pdo->prepare("SELECT COALESCE(SUM(pa.amount),0) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id JOIN invoices i ON i.id=pa.invoice_id JOIN students st ON st.id=i.student_id WHERE p.status='succeeded' AND p.created_at >= :start AND p.created_at < :end" . $scope['sql']);
        $monthPaidQuery->execute(array_merge(['start' => $monthStart, 'end' => $today->modify('+1 day')->format('Y-m-d')], $scope['params']));
        $monthPaid = (float) $monthPaidQuery->fetchColumn();

        $todo = [];
        if ($yearId > 0 && in_array($role, ['school_owner','school_manager','head_teacher','academic_admin','class_teacher','subject_teacher'], true)) {
            $todoQuery = $pdo->prepare("SELECT COUNT(*) FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN examinations ex ON ex.id=a.examination_id JOIN terms t ON t.id=ex.term_id JOIN students st ON st.id=r.student_id WHERE t.academic_year_id=:year AND r.status IN ('draft','submitted','under_review')" . $scope['sql']);
            $todoQuery->execute(array_merge(['year' => $yearId], $scope['params']));
            $todo['results_pending'] = (int) $todoQuery->fetchColumn();
        }

        return [
            'school' => $school, 'year' => $year, 'role' => $role,
            'students' => $studentCount, 'classes' => $classCount,
            'attendance_trend' => $attendanceTrend, 'attendance_status' => $attendanceSeries,
            'attendance_total' => $attendanceTotal, 'present_total' => $presentTotal,
            'today_attendance' => (int) ($todayAttendance['marked_count'] ?? 0) > 0 ? round((int) $todayAttendance['present_count'] * 100 / (int) $todayAttendance['marked_count'], 1) : 0,
            'finance' => $feeData, 'finance_series' => $feeSeries, 'academic_series' => $academicSeries,
            'admission_trend' => $admissionTrend, 'class_distribution' => $classDistribution,
            'month_paid' => $monthPaid, 'todo' => $todo,
        ];
    }

    private function studentScope(array $user, string $role, string $alias): array
    {
        if ($role === 'parent_guardian') return [
            'sql' => " AND EXISTS (SELECT 1 FROM student_guardians sg JOIN guardians g ON g.id=sg.guardian_id WHERE sg.student_id={$alias}.id AND g.user_id=:scope_user AND sg.verification_status='verified' AND sg.ended_at IS NULL)",
            'params' => ['scope_user' => (int) $user['id']],
        ];
        if ($role === 'student') return ['sql' => " AND {$alias}.user_id=:scope_user", 'params' => ['scope_user' => (int) $user['id']]];
        if (in_array($role, ['class_teacher','subject_teacher'], true)) return [
            'sql' => " AND EXISTS (SELECT 1 FROM enrollments es JOIN teacher_assignments ta ON ta.class_group_id=es.class_group_id AND ta.academic_year_id=es.academic_year_id JOIN staff sf ON sf.id=ta.staff_id WHERE es.student_id={$alias}.id AND es.status='active' AND sf.user_id=:scope_user AND sf.employment_status='active' AND ta.ended_at IS NULL)",
            'params' => ['scope_user' => (int) $user['id']],
        ];
        return ['sql' => '', 'params' => []];
    }

    private function scalar(\PDO $pdo, string $sql, array $params): int
    {
        $query = $pdo->prepare($sql); $query->execute($params); return (int) $query->fetchColumn();
    }
}
