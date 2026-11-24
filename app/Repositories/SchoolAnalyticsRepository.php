<?php
declare(strict_types=1);

namespace School\Repositories;

use DateTimeImmutable;
use PDO;
use School\Core\SchoolDatabase;

/**
 * Read-only analytics for the school insights workspace.
 *
 * Every query is scoped to the signed-in account: parents see linked children,
 * learners see their own records, and teachers see the classes they are
 * assigned to. Leadership and finance roles see school-wide aggregates.
 */
final class SchoolAnalyticsRepository
{
    public function filterOptions(array $user, string $role): array
    {
        $pdo = SchoolDatabase::connection();
        $years = $pdo->query('SELECT id,name,status,starts_on,ends_on FROM academic_years ORDER BY starts_on DESC')->fetchAll();
        $terms = $pdo->query('SELECT t.id,t.name,t.status,t.academic_year_id,y.name year_name FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY y.starts_on DESC,t.starts_on DESC')->fetchAll();
        $classes = $pdo->query('SELECT c.id,c.name,c.academic_year_id,y.name year_name FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id ORDER BY y.starts_on DESC,c.name')->fetchAll();
        return ['years' => $years, 'terms' => $terms, 'classes' => $classes];
    }

    /**
     * @param array{year_id?:int,term_id?:int,class_id?:int,from?:string,to?:string} $input
     */
    public function data(array $user, string $role, array $input): array
    {
        $pdo = SchoolDatabase::connection();
        $today = new DateTimeImmutable('today');
        $options = $this->filterOptions($user, $role);

        $yearId = (int) ($input['year_id'] ?? 0);
        if ($yearId <= 0) {
            foreach ($options['years'] as $year) {
                if ($year['status'] === 'active') { $yearId = (int) $year['id']; break; }
            }
            $yearId = $yearId ?: (int) ($options['years'][0]['id'] ?? 0);
        }
        $termId = (int) ($input['term_id'] ?? 0);
        $classId = (int) ($input['class_id'] ?? 0);
        $from = $this->validDate((string) ($input['from'] ?? '')) ?? $today->modify('-89 days')->format('Y-m-d');
        $to = $this->validDate((string) ($input['to'] ?? '')) ?? $today->format('Y-m-d');
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $year = null;
        foreach ($options['years'] as $candidate) if ((int) $candidate['id'] === $yearId) $year = $candidate;
        $term = null;
        foreach ($options['terms'] as $candidate) if ((int) $candidate['id'] === $termId) $term = $candidate;
        $class = null;
        foreach ($options['classes'] as $candidate) if ((int) $candidate['id'] === $classId) $class = $candidate;

        $scope = $this->studentScope($user, $role, 'st');
        $scopeSql = $scope['sql'];
        $scopeParams = $scope['params'];

        $classSql = $classId > 0 ? ' AND en.class_group_id = :filter_class' : '';
        $classParams = $classId > 0 ? ['filter_class' => $classId] : [];
        $yearSql = $yearId > 0 ? ' AND en.academic_year_id = :filter_year' : '';
        $yearParams = $yearId > 0 ? ['filter_year' => $yearId] : [];

        $baseParams = array_merge($scopeParams, $classParams, $yearParams);
        $enrollmentFrom = ' FROM enrollments en JOIN students st ON st.id=en.student_id WHERE en.status=\'active\' AND st.status=\'active\'' . $yearSql . $classSql . $scopeSql;

        $students = $this->scalar($pdo, 'SELECT COUNT(DISTINCT en.student_id)' . $enrollmentFrom, $baseParams);
        $classes = $this->scalar($pdo, 'SELECT COUNT(DISTINCT en.class_group_id)' . $enrollmentFrom, $baseParams);

        $staffParams = $scopeParams;
        $staffSql = "SELECT COUNT(*) FROM staff sf WHERE sf.employment_status='active'";
        if (in_array($role, ['class_teacher','subject_teacher'], true)) {
            $staffSql = "SELECT COUNT(DISTINCT sf.id) FROM staff sf JOIN teacher_assignments ta ON ta.staff_id=sf.id WHERE sf.employment_status='active' AND ta.ended_at IS NULL AND sf.user_id=:scope_user";
        }
        $staff = $this->scalar($pdo, $staffSql, $staffParams);
        $teachers = $this->scalar($pdo, "SELECT COUNT(DISTINCT ta.staff_id) FROM teacher_assignments ta WHERE ta.ended_at IS NULL", []);

        // Gender split.
        $genderQuery = $pdo->prepare('SELECT st.gender, COUNT(DISTINCT st.id) total' . $enrollmentFrom . ' GROUP BY st.gender');
        $genderQuery->execute($baseParams);
        $gender = ['female' => 0, 'male' => 0, 'other' => 0, 'undisclosed' => 0];
        foreach ($genderQuery->fetchAll() as $row) $gender[$row['gender']] = (int) $row['total'];

        // Class distribution.
        $classQuery = $pdo->prepare('SELECT cg.name AS label, COUNT(DISTINCT en.student_id) AS value FROM enrollments en JOIN students st ON st.id=en.student_id JOIN class_groups cg ON cg.id=en.class_group_id WHERE en.status=\'active\' AND st.status=\'active\'' . $yearSql . $classSql . $scopeSql . ' GROUP BY cg.id,cg.name ORDER BY value DESC,cg.name');
        $classQuery->execute($baseParams);
        $classDistribution = array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => (int) $row['value']], $classQuery->fetchAll());

        // Admission trend for the 12 months ending at the filter end date.
        $endMonth = new DateTimeImmutable($to);
        $startMonth = $endMonth->modify('-11 months')->modify('first day of this month');
        $admissionQuery = $pdo->prepare("SELECT DATE_FORMAT(st.admission_date,'%Y-%m') month_key, COUNT(DISTINCT st.id) total FROM students st WHERE st.admission_date BETWEEN :start AND :end" . $scopeSql . ' GROUP BY month_key');
        $admissionQuery->execute(array_merge(['start' => $startMonth->format('Y-m-d'), 'end' => $to], $scopeParams));
        $admissionMap = [];
        foreach ($admissionQuery->fetchAll() as $row) $admissionMap[$row['month_key']] = (int) $row['total'];
        $admissionTrend = [];
        for ($month = $startMonth; $month <= $endMonth; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $admissionTrend[] = ['label' => $month->format('M y'), 'value' => $admissionMap[$key] ?? 0];
        }

        // Attendance within the date window.
        $attendanceFilter = ' WHERE sess.session_date BETWEEN :from AND :to' . $scopeSql;
        $attendanceParams = array_merge(['from' => $from, 'to' => $to], $scopeParams);
        if ($classId > 0) {
            $attendanceFilter .= ' AND EXISTS (SELECT 1 FROM enrollments enx WHERE enx.student_id=st.id AND enx.class_group_id=:filter_class AND enx.status=\'active\')';
            $attendanceParams['filter_class'] = $classId;
        }

        $attendanceTrendQuery = $pdo->prepare("SELECT sess.session_date day, SUM(ar.state='present') present_count, COUNT(*) marked_count FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id" . $attendanceFilter . ' GROUP BY sess.session_date ORDER BY sess.session_date');
        $attendanceTrendQuery->execute($attendanceParams);
        $attendanceTrend = [];
        $presentTotal = 0;
        $markedTotal = 0;
        foreach ($attendanceTrendQuery->fetchAll() as $row) {
            $rate = (int) $row['marked_count'] > 0 ? round((int) $row['present_count'] * 100 / (int) $row['marked_count'], 1) : 0;
            $attendanceTrend[] = ['label' => date('d M', strtotime((string) $row['day'])), 'value' => $rate];
            $presentTotal += (int) $row['present_count'];
            $markedTotal += (int) $row['marked_count'];
        }

        $statusQuery = $pdo->prepare("SELECT ar.state, COUNT(*) total FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id" . $attendanceFilter . ' GROUP BY ar.state');
        $statusQuery->execute($attendanceParams);
        $statusColors = ['present' => '#169E6B', 'absent' => '#DC3545', 'late' => '#C98012', 'excused' => '#4F81BD', 'early_departure' => '#8064A2'];
        $attendanceStatus = [];
        $statusTotals = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'early_departure' => 0];
        foreach ($statusQuery->fetchAll() as $row) {
            $statusTotals[$row['state']] = (int) $row['total'];
        }
        foreach ($statusTotals as $state => $value) {
            $attendanceStatus[] = ['label' => ucwords(str_replace('_', ' ', $state)), 'value' => $value, 'color' => $statusColors[$state]];
        }
        $attendanceRate = $markedTotal > 0 ? round($presentTotal * 100 / $markedTotal, 1) : 0.0;

        // Attendance by class for the window.
        $attendanceByClassQuery = $pdo->prepare("SELECT cg.name label, SUM(ar.state='present') present_count, SUM(ar.state='absent') absent_count, SUM(ar.state='late') late_count, COUNT(*) total FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id JOIN students st ON st.id=ar.student_id JOIN enrollments en ON en.student_id=st.id AND en.status='active' JOIN class_groups cg ON cg.id=en.class_group_id" . $attendanceFilter . ($yearId > 0 ? ' AND en.academic_year_id=:filter_year' : '') . ($classId > 0 ? ' AND en.class_group_id=:filter_class2' : '') . ' GROUP BY cg.id,cg.name ORDER BY cg.name');
        $attendanceClassParams = $attendanceParams;
        if ($yearId > 0) $attendanceClassParams['filter_year'] = $yearId;
        if ($classId > 0) $attendanceClassParams['filter_class2'] = $classId;
        $attendanceByClassQuery->execute($attendanceClassParams);
        $attendanceClasses = [];
        $attendancePresentSeries = [];
        $attendanceAbsentSeries = [];
        $attendanceLateSeries = [];
        foreach ($attendanceByClassQuery->fetchAll() as $row) {
            $attendanceClasses[] = $row['label'];
            $attendancePresentSeries[] = (int) $row['present_count'];
            $attendanceAbsentSeries[] = (int) $row['absent_count'];
            $attendanceLateSeries[] = (int) $row['late_count'];
        }

        // Finance.
        $canFinance = in_array($role, ['school_owner','company_owner','school_manager','accountant','finance_director','chief_accountant','senior_accountant','general_accountant','accounts_receivable_officer','accounts_payable_officer','payroll_officer','tax_accountant','cashier','procurement_officer','internal_auditor','external_auditor','readonly_manager','parent_guardian'], true);
        $finance = [
            'charged' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0,
            'collection_rate' => 0.0, 'invoice_count' => 0, 'paid_invoices' => 0,
            'monthly' => ['labels' => [], 'collected' => [], 'outstanding' => []],
            'methods' => [], 'status' => [], 'top_debtors' => [], 'recent_payments' => [],
        ];
        if ($canFinance) {
            $invoiceFilter = ' WHERE i.status <> \'void\'' . $scopeSql;
            $invoiceParams = $scopeParams;
            if ($yearId > 0) {
                $invoiceFilter .= ' AND i.term_id IN (SELECT t.id FROM terms t WHERE t.academic_year_id=:filter_year)';
                $invoiceParams['filter_year'] = $yearId;
            }
            if ($termId > 0) {
                $invoiceFilter .= ' AND i.term_id=:filter_term';
                $invoiceParams['filter_term'] = $termId;
            }
            if ($classId > 0) {
                $invoiceFilter .= ' AND EXISTS (SELECT 1 FROM enrollments enx WHERE enx.student_id=i.student_id AND enx.class_group_id=:filter_class AND enx.status=\'active\')';
                $invoiceParams['filter_class'] = $classId;
            }
            $invoiceQuery = $pdo->prepare('SELECT COUNT(*) invoice_count, COALESCE(SUM(inv.charged),0) charged, COALESCE(SUM(inv.paid),0) paid, COALESCE(SUM(CASE WHEN inv.due_at < :today THEN GREATEST(inv.charged-inv.paid,0) ELSE 0 END),0) overdue, SUM(inv.charged>0 AND inv.paid>=inv.charged) paid_invoices FROM (SELECT i.id,i.due_at, COALESCE((SELECT SUM(ii.amount-ii.discount) FROM invoice_items ii WHERE ii.invoice_id=i.id),0) charged, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=i.id AND p.status=\'succeeded\'),0) paid FROM invoices i JOIN students st ON st.id=i.student_id' . $invoiceFilter . ') inv');
            $invoiceQuery->execute(array_merge(['today' => $to], $invoiceParams));
            $invoiceRow = $invoiceQuery->fetch() ?: [];
            $finance['charged'] = (float) ($invoiceRow['charged'] ?? 0);
            $finance['paid'] = (float) ($invoiceRow['paid'] ?? 0);
            $finance['overdue'] = (float) ($invoiceRow['overdue'] ?? 0);
            $finance['outstanding'] = max(0, $finance['charged'] - $finance['paid']);
            $finance['invoice_count'] = (int) ($invoiceRow['invoice_count'] ?? 0);
            $finance['paid_invoices'] = (int) ($invoiceRow['paid_invoices'] ?? 0);
            $finance['collection_rate'] = $finance['charged'] > 0 ? round($finance['paid'] * 100 / $finance['charged'], 1) : 0.0;

            // 12-month collections vs outstanding balance.
            $paymentFilter = ' WHERE p.status=\'succeeded\' AND p.created_at BETWEEN :start AND :end' . $scopeSql;
            $paymentParams = array_merge(['start' => $startMonth->format('Y-m-d 00:00:00'), 'end' => $to . ' 23:59:59'], $scopeParams);
            if ($classId > 0) {
                $paymentFilter .= ' AND EXISTS (SELECT 1 FROM enrollments enx WHERE enx.student_id=i.student_id AND enx.class_group_id=:filter_class AND enx.status=\'active\')';
                $paymentParams['filter_class'] = $classId;
            }
            $monthlyQuery = $pdo->prepare("SELECT DATE_FORMAT(p.created_at,'%Y-%m') month_key, COALESCE(SUM(pa.amount),0) total FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id JOIN invoices i ON i.id=pa.invoice_id JOIN students st ON st.id=i.student_id" . $paymentFilter . ' GROUP BY month_key');
            $monthlyQuery->execute($paymentParams);
            $collectedMap = [];
            foreach ($monthlyQuery->fetchAll() as $row) $collectedMap[$row['month_key']] = (float) $row['total'];
            $finance['monthly'] = ['labels' => [], 'collected' => [], 'outstanding' => []];
            for ($month = $startMonth; $month <= $endMonth; $month = $month->modify('+1 month')) {
                $key = $month->format('Y-m');
                $finance['monthly']['labels'][] = $month->format('M y');
                $finance['monthly']['collected'][] = round($collectedMap[$key] ?? 0, 2);
                // Outstanding is the current balance, repeated as context for the final month.
                $finance['monthly']['outstanding'][] = $key === $endMonth->format('Y-m') ? round($finance['outstanding'], 2) : 0.0;
            }

            $methodQuery = $pdo->prepare("SELECT p.method label, COUNT(*) value, COALESCE(SUM(p.amount),0) amount FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.id JOIN invoices i ON i.id=pa.invoice_id JOIN students st ON st.id=i.student_id WHERE p.status='succeeded'" . $scopeSql . ' GROUP BY p.method ORDER BY value DESC');
            $methodQuery->execute($scopeParams);
            $methodColors = ['cash' => '#169E6B', 'bank' => '#4F81BD', 'mobile' => '#C98012', 'card' => '#8064A2', 'cheque' => '#4BACC6'];
            foreach ($methodQuery->fetchAll() as $row) {
                $finance['methods'][] = [
                    'label' => ucwords((string) $row['label']),
                    'value' => (int) $row['value'],
                    'amount' => (float) $row['amount'],
                    'color' => $methodColors[strtolower((string) $row['label'])] ?? '#7A8C85',
                ];
            }

            $statusQuery = $pdo->prepare("SELECT i.status label, COUNT(*) value FROM invoices i JOIN students st ON st.id=i.student_id WHERE i.status <> 'void'" . $scopeSql . ' GROUP BY i.status');
            $statusQuery->execute($scopeParams);
            $statusColors = ['draft' => '#B9C6C0', 'issued' => '#C98012', 'part_paid' => '#4BACC6', 'paid' => '#169E6B'];
            foreach ($statusQuery->fetchAll() as $row) {
                $finance['status'][] = ['label' => ucwords(str_replace('_', ' ', (string) $row['label'])), 'value' => (int) $row['value'], 'color' => $statusColors[$row['label']] ?? '#7A8C85'];
            }

            $debtorQuery = $pdo->prepare('SELECT st.id, st.full_name, st.admission_number, cg.name class_name, COALESCE(SUM(inv.charged-inv.paid),0) balance FROM (SELECT i.id,i.student_id, COALESCE((SELECT SUM(ii.amount-ii.discount) FROM invoice_items ii WHERE ii.invoice_id=i.id),0) charged, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=i.id AND p.status=\'succeeded\'),0) paid FROM invoices i WHERE i.status <> \'void\') inv JOIN students st ON st.id=inv.student_id LEFT JOIN enrollments en ON en.student_id=st.id AND en.status=\'active\' LEFT JOIN class_groups cg ON cg.id=en.class_group_id WHERE inv.charged > inv.paid' . $scopeSql . ' GROUP BY st.id,st.full_name,st.admission_number,cg.name ORDER BY balance DESC LIMIT 8');
            $debtorQuery->execute($scopeParams);
            foreach ($debtorQuery->fetchAll() as $row) {
                $finance['top_debtors'][] = ['id' => (int) $row['id'], 'name' => $row['full_name'], 'admission' => $row['admission_number'], 'class' => $row['class_name'], 'balance' => (float) $row['balance']];
            }

            $recentQuery = $pdo->prepare("SELECT p.id,p.amount,p.method,p.reference,p.created_at,st.full_name,st.admission_number FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id=p.id LEFT JOIN invoices i ON i.id=pa.invoice_id LEFT JOIN students st ON st.id=i.student_id WHERE p.status='succeeded'" . $scopeSql . ' ORDER BY p.created_at DESC LIMIT 6');
            $recentQuery->execute($scopeParams);
            $finance['recent_payments'] = $recentQuery->fetchAll();
        }

        // Academic performance.
        $academicFilter = ' WHERE r.status IN (\'approved\',\'published\')' . $scopeSql;
        $academicParams = $scopeParams;
        if ($yearId > 0) { $academicFilter .= ' AND t.academic_year_id=:filter_year'; $academicParams['filter_year'] = $yearId; }
        if ($termId > 0) { $academicFilter .= ' AND t.id=:filter_term'; $academicParams['filter_term'] = $termId; }
        if ($classId > 0) { $academicFilter .= ' AND a.class_group_id=:filter_class'; $academicParams['filter_class'] = $classId; }
        $academicFrom = ' FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN examinations e ON e.id=a.examination_id JOIN terms t ON t.id=e.term_id JOIN subjects sub ON sub.id=a.subject_id JOIN students st ON st.id=r.student_id';

        $summaryQuery = $pdo->prepare('SELECT COUNT(*) total, COALESCE(AVG(r.mark*100/a.max_mark),0) average, COALESCE(SUM(r.mark*100/a.max_mark >= 50),0) passed' . $academicFrom . $academicFilter);
        $summaryQuery->execute($academicParams);
        $academicSummary = $summaryQuery->fetch() ?: ['total' => 0, 'average' => 0, 'passed' => 0];
        $averageMark = round((float) $academicSummary['average'], 1);
        $passRate = (int) $academicSummary['total'] > 0 ? round((int) $academicSummary['passed'] * 100 / (int) $academicSummary['total'], 1) : 0.0;

        $subjectQuery = $pdo->prepare('SELECT sub.name label, ROUND(AVG(r.mark*100/a.max_mark),1) average, COUNT(*) entries, SUM(r.mark*100/a.max_mark >= 50) passed' . $academicFrom . $academicFilter . ' GROUP BY sub.id,sub.name ORDER BY average DESC');
        $subjectQuery->execute($academicParams);
        $subjectPerformance = [];
        foreach ($subjectQuery->fetchAll() as $row) {
            $subjectPerformance[] = [
                'label' => $row['label'],
                'value' => (float) $row['average'],
                'entries' => (int) $row['entries'],
                'pass_rate' => (int) $row['entries'] > 0 ? round((int) $row['passed'] * 100 / (int) $row['entries'], 1) : 0.0,
            ];
        }

        $gradeQuery = $pdo->prepare('SELECT CASE WHEN r.mark*100/a.max_mark >= 80 THEN \'A\' WHEN r.mark*100/a.max_mark >= 70 THEN \'B\' WHEN r.mark*100/a.max_mark >= 60 THEN \'C\' WHEN r.mark*100/a.max_mark >= 50 THEN \'D\' WHEN r.mark*100/a.max_mark >= 40 THEN \'E\' ELSE \'F\' END grade, COUNT(*) total' . $academicFrom . $academicFilter . ' GROUP BY grade ORDER BY grade');
        $gradeQuery->execute($academicParams);
        $gradeMap = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0];
        foreach ($gradeQuery->fetchAll() as $row) $gradeMap[$row['grade']] = (int) $row['total'];
        $gradeColors = ['A' => '#169E6B', 'B' => '#4F81BD', 'C' => '#4BACC6', 'D' => '#C98012', 'E' => '#E08A3C', 'F' => '#C0392B'];
        $gradeDistribution = [];
        foreach ($gradeMap as $grade => $total) $gradeDistribution[] = ['label' => 'Grade ' . $grade, 'value' => $total, 'color' => $gradeColors[$grade]];

        $topQuery = $pdo->prepare('SELECT st.id,st.full_name,st.admission_number,cg.name class_name, ROUND(AVG(r.mark*100/a.max_mark),1) average, COUNT(*) subjects' . $academicFrom . ' LEFT JOIN enrollments en ON en.student_id=st.id AND en.status=\'active\' LEFT JOIN class_groups cg ON cg.id=en.class_group_id' . $academicFilter . ' GROUP BY st.id,st.full_name,st.admission_number,cg.name HAVING subjects >= 1 ORDER BY average DESC LIMIT 8');
        $topQuery->execute($academicParams);
        $topStudents = $topQuery->fetchAll();

        // Exam-by-term trend.
        $examTrendQuery = $pdo->prepare("SELECT y.name year_name, t.name term_name, ROUND(AVG(r.mark*100/a.max_mark),1) average" . $academicFrom . ' JOIN academic_years y ON y.id=t.academic_year_id' . $academicFilter . ' GROUP BY t.id,t.name,y.name,y.starts_on ORDER BY y.starts_on,t.starts_on');
        $examTrendQuery->execute($academicParams);
        $examTrend = [];
        foreach ($examTrendQuery->fetchAll() as $row) $examTrend[] = ['label' => $row['year_name'] . ' · ' . $row['term_name'], 'value' => (float) $row['average']];

        // At-risk learners: low attendance or an outstanding balance.
        $riskQuery = $pdo->prepare("SELECT st.id,st.full_name,st.admission_number,cg.name class_name, COALESCE(att.rate,100) attendance_rate, COALESCE(fin.balance,0) balance FROM students st LEFT JOIN enrollments en ON en.student_id=st.id AND en.status='active' LEFT JOIN class_groups cg ON cg.id=en.class_group_id LEFT JOIN (SELECT ar.student_id, ROUND(SUM(ar.state='present')*100/NULLIF(COUNT(*),0),1) rate FROM attendance_records ar JOIN attendance_sessions sess ON sess.id=ar.session_id WHERE sess.session_date BETWEEN :from AND :to GROUP BY ar.student_id) att ON att.student_id=st.id LEFT JOIN (SELECT inv.student_id, SUM(inv.charged-inv.paid) balance FROM (SELECT i.id,i.student_id, COALESCE((SELECT SUM(ii.amount-ii.discount) FROM invoice_items ii WHERE ii.invoice_id=i.id),0) charged, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=i.id AND p.status='succeeded'),0) paid FROM invoices i WHERE i.status <> 'void') inv GROUP BY inv.student_id) fin ON fin.student_id=st.id WHERE st.status='active'" . $scopeSql . ($classId > 0 ? ' AND en.class_group_id=:filter_class' : '') . ' AND (COALESCE(att.rate,100) < 75 OR COALESCE(fin.balance,0) > 0) ORDER BY attendance_rate ASC, balance DESC LIMIT 10');
        $riskParams = array_merge(['from' => $from, 'to' => $to], $scopeParams);
        if ($classId > 0) $riskParams['filter_class'] = $classId;
        $riskQuery->execute($riskParams);
        $atRisk = $riskQuery->fetchAll();

        // Recent audit activity for leadership.
        $activity = [];
        if (in_array($role, ['school_owner','school_manager','head_teacher','academic_admin','accountant','system_admin','school_administrator','internal_auditor','readonly_manager'], true)) {
            $activity = $pdo->query("SELECT a.action,a.subject_type,a.subject_id,a.created_at,u.full_name actor FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 8")->fetchAll();
        }

        return [
            'context' => [
                'school' => $pdo->query('SELECT name,motto,address,phone,email,website,currency FROM school_profile ORDER BY id LIMIT 1')->fetch() ?: [],
                'year' => $year, 'term' => $term, 'class' => $class,
                'from' => $from, 'to' => $to, 'role' => $role,
                'generated_at' => gmdate('c'),
            ],
            'options' => $options,
            'kpis' => [
                'students' => $students, 'classes' => $classes, 'staff' => $staff, 'teachers' => $teachers,
                'attendance_rate' => $attendanceRate, 'present_total' => $presentTotal, 'marked_total' => $markedTotal,
                'average_mark' => $averageMark, 'pass_rate' => $passRate, 'result_entries' => (int) $academicSummary['total'],
            ],
            'gender' => $gender,
            'class_distribution' => $classDistribution,
            'admission_trend' => $admissionTrend,
            'attendance_trend' => $attendanceTrend,
            'attendance_status' => $attendanceStatus,
            'attendance_by_class' => [
                'labels' => $attendanceClasses,
                'present' => $attendancePresentSeries,
                'absent' => $attendanceAbsentSeries,
                'late' => $attendanceLateSeries,
            ],
            'finance' => $finance,
            'academic' => [
                'average_mark' => $averageMark,
                'pass_rate' => $passRate,
                'subjects' => $subjectPerformance,
                'grades' => $gradeDistribution,
                'top_students' => $topStudents,
                'exam_trend' => $examTrend,
            ],
            'at_risk' => $atRisk,
            'activity' => $activity,
        ];
    }

    private function validDate(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d', $time);
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

    private function scalar(PDO $pdo, string $sql, array $params): int
    {
        $query = $pdo->prepare($sql);
        $query->execute($params);
        return (int) $query->fetchColumn();
    }
}
