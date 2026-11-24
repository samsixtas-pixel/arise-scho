<?php
declare(strict_types=1);

namespace School\Services;

use PDO;
use RuntimeException;
use School\Core\SchoolDatabase;
use School\Repositories\SchoolAnalyticsRepository;

require_once dirname(__DIR__, 2) . '/school/includes/document.php';

/**
 * Builds the HTML documents that the PDF renderer turns into downloadable
 * files. Every builder returns ['html' => string, 'filename' => string,
 * 'landscape' => bool].
 */
final class SchoolDocumentBuilder
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = SchoolDatabase::connection();
    }

    // ---------------------------------------------------------------- report cards

    public function reportCard(int $studentId, int $examId): array
    {
        $snapshot = $this->pdo->prepare('SELECT rc.report_number, rc.published_at, snap.payload_json, snap.branding_snapshot_json, snap.checksum FROM report_cards rc JOIN report_card_snapshots snap ON snap.report_card_id=rc.id WHERE rc.student_id=:student AND rc.examination_id=:exam AND rc.published_at IS NOT NULL LIMIT 1');
        $snapshot->execute(['student' => $studentId, 'exam' => $examId]);
        $saved = $snapshot->fetch();

        if ($saved) {
            if (!hash_equals((string) $saved['checksum'], hash('sha256', (string) $saved['payload_json'] . (string) $saved['branding_snapshot_json']))) {
                throw new RuntimeException('The saved report snapshot failed its integrity check.');
            }
            $payload = json_decode((string) $saved['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $reportNumber = (string) $saved['report_number'];
            $publishedAt = (string) $saved['published_at'];
        } else {
            $payload = $this->buildReportPayload($studentId, $examId);
            $reportNumber = 'DRAFT-RC-' . $examId . '-' . $studentId;
            $publishedAt = '';
        }

        $body = $this->reportCardBody($payload, $reportNumber, $publishedAt);
        $student = $payload['student'] ?? [];
        $exam = $payload['examination'] ?? [];
        $html = school_document_shell($body, [
            'title' => 'Academic report card',
            'subtitle' => trim((string) (($payload['academic_year']['name'] ?? '') . ' · ' . ($payload['term']['name'] ?? '') . ' · ' . ($exam['name'] ?? '')), ' ·'),
            'document_no' => $reportNumber,
            'watermark' => $publishedAt === '' ? 'DRAFT' : '',
            'footer_note' => $publishedAt !== '' ? 'Published ' . formatDate(substr($publishedAt, 0, 10)) : 'Draft — not yet published',
            'signatures' => ['Class teacher', 'Head teacher'],
        ]);

        return [
            'html' => $html,
            'filename' => 'report-card-' . ($student['admission_number'] ?? $studentId) . '-exam-' . $examId . '.pdf',
            'landscape' => false,
        ];
    }

    public function classReportCards(int $examId, int $classId): array
    {
        $students = $this->pdo->prepare("SELECT DISTINCT r.student_id FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN students st ON st.id=r.student_id WHERE a.examination_id=:exam AND a.class_group_id=:class AND r.status IN ('approved','published') AND st.status='active' ORDER BY st.full_name");
        $students->execute(['exam' => $examId, 'class' => $classId]);
        $ids = array_map('intval', array_column($students->fetchAll(), 'student_id'));
        if ($ids === []) {
            throw new RuntimeException('No approved or published results exist for that class and assessment.');
        }
        $documents = [];
        foreach ($ids as $studentId) {
            $snapshot = $this->pdo->prepare('SELECT rc.report_number, rc.published_at, snap.payload_json, snap.checksum, snap.branding_snapshot_json FROM report_cards rc JOIN report_card_snapshots snap ON snap.report_card_id=rc.id WHERE rc.student_id=:student AND rc.examination_id=:exam AND rc.published_at IS NOT NULL LIMIT 1');
            $snapshot->execute(['student' => $studentId, 'exam' => $examId]);
            $saved = $snapshot->fetch();
            if ($saved && hash_equals((string) $saved['checksum'], hash('sha256', (string) $saved['payload_json'] . (string) $saved['branding_snapshot_json']))) {
                $payload = json_decode((string) $saved['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $reportNumber = (string) $saved['report_number'];
                $publishedAt = (string) $saved['published_at'];
            } else {
                $payload = $this->buildReportPayload($studentId, $examId);
                $reportNumber = 'DRAFT-RC-' . $examId . '-' . $studentId;
                $publishedAt = '';
            }
            $documents[] = [
                'body' => $this->reportCardBody($payload, $reportNumber, $publishedAt),
                'title' => 'Academic report card',
                'subtitle' => trim((string) (($payload['academic_year']['name'] ?? '') . ' · ' . ($payload['term']['name'] ?? '') . ' · ' . ($payload['examination']['name'] ?? '')), ' ·'),
                'document_no' => $reportNumber,
                'watermark' => $publishedAt === '' ? 'DRAFT' : '',
                'footer_note' => $publishedAt !== '' ? 'Published ' . formatDate(substr($publishedAt, 0, 10)) : 'Draft — not yet published',
            ];
        }
        $class = $this->classInfo($classId);
        return [
            'html' => school_document_bundle($documents, ['title' => 'Report cards · ' . ($class['name'] ?? 'Class')]),
            'filename' => 'report-cards-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($class['name'] ?? 'class')) . '-exam-' . $examId . '.pdf',
            'landscape' => false,
        ];
    }

    private function reportCardBody(array $payload, string $reportNumber, string $publishedAt): string
    {
        $student = $payload['student'] ?? [];
        $items = $payload['results'] ?? [];
        $total = (float) ($payload['total_mark'] ?? 0);
        $max = (float) ($payload['maximum_mark'] ?? 0);
        $average = $payload['average_percentage'] ?? ($max > 0 ? round($total * 100 / $max, 2) : null);

        $html = school_document_meta([
            'Student name' => $student['name'] ?? '',
            'Admission number' => $student['admission_number'] ?? '',
            'Class' => $student['class'] ?? '',
            'Academic year' => $payload['academic_year']['name'] ?? '',
            'Term' => $payload['term']['name'] ?? '',
            'Assessment' => $payload['examination']['name'] ?? '',
        ]);

        $html .= '<h3 class="section">Subject performance</h3><table class="data"><thead><tr><th>Subject</th><th>Code</th><th class="num">Mark</th><th class="num">Maximum</th><th class="num">Percent</th><th>Grade</th><th>Comment</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $itemMax = (float) ($item['max_mark'] ?? 0);
            $percent = $itemMax > 0 ? (float) ($item['mark'] ?? 0) * 100 / $itemMax : 0;
            $html .= '<tr><td>' . e((string) ($item['subject_name'] ?? '')) . '</td><td>' . e((string) ($item['code'] ?? '')) . '</td>'
                . '<td class="num">' . e(num($item['mark'] ?? 0)) . '</td><td class="num">' . e(num($item['max_mark'] ?? 0)) . '</td>'
                . '<td class="num">' . e(number_format($percent, 1)) . '%</td><td>' . e((string) (($item['grade'] ?? '') ?: $this->gradeFor($percent))) . '</td>'
                . '<td>' . e((string) ($item['comments'] ?? '')) . '</td></tr>';
        }
        if ($items === []) {
            $html .= '<tr><td colspan="7" class="center">No results recorded for this assessment.</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="totals"><div class="row"><span>Total marks</span><strong>' . e(num($total)) . ' / ' . e(num($max)) . '</strong></div>'
            . '<div class="row"><span>Subjects assessed</span><strong>' . count($items) . '</strong></div>'
            . '<div class="row grand"><span>Average</span><strong>' . ($average === null ? '—' : e(number_format((float) $average, 1)) . '%') . '</strong></div></div>';

        $html .= '<div class="note">This report reflects the results approved and published by the school. Published report cards are stored as immutable snapshots. Contact the school office for corrections or clarification.</div>';
        return $html;
    }

    // ---------------------------------------------------------------- finance

    public function invoice(int $invoiceId): array
    {
        $query = $this->pdo->prepare('SELECT i.id,i.invoice_number,i.status,i.issued_at,i.due_at,s.id student_id,s.admission_number,s.full_name,t.name term_name,y.name year_name FROM invoices i JOIN students s ON s.id=i.student_id JOIN terms t ON t.id=i.term_id JOIN academic_years y ON y.id=t.academic_year_id WHERE i.id=:id');
        $query->execute(['id' => $invoiceId]);
        $invoice = $query->fetch();
        if (!$invoice) throw new RuntimeException('Invoice not found.');

        $items = $this->pdo->prepare('SELECT description,amount,discount FROM invoice_items WHERE invoice_id=:id ORDER BY id');
        $items->execute(['id' => $invoiceId]);
        $lines = $items->fetchAll();
        $payments = $this->pdo->prepare("SELECT p.id,p.amount,p.method,p.reference,p.created_at,pa.amount allocated FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=:id AND p.status='succeeded' ORDER BY p.created_at,p.id");
        $payments->execute(['id' => $invoiceId]);
        $paidRows = $payments->fetchAll();

        $total = 0.0; $paid = 0.0;
        foreach ($lines as $line) $total += (float) $line['amount'] - (float) $line['discount'];
        foreach ($paidRows as $payment) $paid += (float) $payment['allocated'];
        $balance = max(0, $total - $paid);

        $body = school_document_meta([
            'Billed to' => $invoice['full_name'], 'Admission number' => $invoice['admission_number'],
            'Academic period' => $invoice['year_name'] . ' · ' . $invoice['term_name'],
            'Issued' => formatDate(substr((string) $invoice['issued_at'], 0, 10)),
            'Due' => formatDate($invoice['due_at']), 'Status' => ucwords(str_replace('_', ' ', (string) $invoice['status'])),
        ]);
        $body .= '<h3 class="section">Invoice lines</h3><table class="data"><thead><tr><th>Description</th><th class="num">Amount</th><th class="num">Discount</th><th class="num">Line total</th></tr></thead><tbody>';
        foreach ($lines as $line) {
            $body .= '<tr><td>' . e((string) $line['description']) . '</td><td class="num">' . e(money($line['amount'])) . '</td>'
                . '<td class="num">' . e(money($line['discount'])) . '</td><td class="num">' . e(money((float) $line['amount'] - (float) $line['discount'])) . '</td></tr>';
        }
        if ($lines === []) $body .= '<tr><td colspan="4" class="center">No invoice lines.</td></tr>';
        $body .= '</tbody></table>';
        $body .= '<div class="totals"><div class="row"><span>Invoice total</span><strong>' . e(money($total)) . '</strong></div>'
            . '<div class="row"><span>Payments received</span><strong>' . e(money($paid)) . '</strong></div>'
            . '<div class="row grand"><span>Balance due</span><strong>' . e(money($balance)) . '</strong></div></div>';
        $body .= '<h3 class="section">Payment history</h3><table class="data"><thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach ($paidRows as $payment) {
            $body .= '<tr><td>RCPT-' . (int) $payment['id'] . '</td><td>' . e(formatDate(substr((string) $payment['created_at'], 0, 10))) . '</td>'
                . '<td>' . e(ucwords(str_replace('_', ' ', (string) $payment['method']))) . '</td><td>' . e((string) ($payment['reference'] ?: '—')) . '</td>'
                . '<td class="num">' . e(money($payment['allocated'])) . '</td></tr>';
        }
        if ($paidRows === []) $body .= '<tr><td colspan="5" class="center">No payments recorded.</td></tr>';
        $body .= '</tbody></table><div class="note">Please quote invoice number ' . e((string) $invoice['invoice_number']) . ' with every payment. This document reflects school records as of ' . e(date('d/m/Y H:i')) . '.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Fee invoice', 'subtitle' => $invoice['year_name'] . ' · ' . $invoice['term_name'],
                'document_no' => (string) $invoice['invoice_number'], 'watermark' => 'INVOICE',
                'signatures' => [], 'footer_note' => 'Official school invoice',
            ]),
            'filename' => 'invoice-' . $invoice['invoice_number'] . '.pdf',
            'landscape' => false,
        ];
    }

    public function receipt(int $paymentId): array
    {
        $query = $this->pdo->prepare("SELECT p.id,p.amount,p.currency,p.method,p.reference,p.created_at,p.status, u.full_name payer FROM payments p LEFT JOIN users u ON u.id=p.payer_user_id WHERE p.id=:id AND p.status='succeeded'");
        $query->execute(['id' => $paymentId]);
        $payment = $query->fetch();
        if (!$payment) throw new RuntimeException('Receipt not found.');

        $allocations = $this->pdo->prepare('SELECT pa.amount, i.invoice_number, i.id invoice_id, s.full_name, s.admission_number, t.name term_name, y.name year_name FROM payment_allocations pa JOIN invoices i ON i.id=pa.invoice_id JOIN students s ON s.id=i.student_id JOIN terms t ON t.id=i.term_id JOIN academic_years y ON y.id=t.academic_year_id WHERE pa.payment_id=:id ORDER BY i.id');
        $allocations->execute(['id' => $paymentId]);
        $rows = $allocations->fetchAll();

        $body = school_document_meta([
            'Received from' => $payment['payer'] ?? ($rows[0]['full_name'] ?? '—'),
            'Payment date' => formatDate(substr((string) $payment['created_at'], 0, 10)),
            'Method' => ucwords(str_replace('_', ' ', (string) $payment['method'])),
            'Reference' => (string) ($payment['reference'] ?: '—'),
            'Currency' => (string) $payment['currency'],
            'Status' => ucfirst((string) $payment['status']),
        ]);
        $body .= '<h3 class="section">Amount received</h3><div class="summary-grid"><div class="summary-box"><div class="k">Total received</div><div class="v">' . e(money($payment['amount'])) . '</div><div class="h">Receipt RCPT-' . (int) $payment['id'] . '</div></div></div>';
        $body .= '<h3 class="section">Allocated to invoices</h3><table class="data"><thead><tr><th>Invoice</th><th>Learner</th><th>Admission</th><th>Period</th><th class="num">Allocated</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . e((string) $row['invoice_number']) . '</td><td>' . e((string) $row['full_name']) . '</td><td>' . e((string) $row['admission_number']) . '</td>'
                . '<td>' . e((string) ($row['year_name'] . ' · ' . $row['term_name'])) . '</td><td class="num">' . e(money($row['amount'])) . '</td></tr>';
        }
        if ($rows === []) $body .= '<tr><td colspan="5" class="center">This payment has not been allocated to an invoice yet.</td></tr>';
        $body .= '</tbody></table><div class="note">This is an official acknowledgement of payment. Keep it for your records. Payments are applied to the oldest outstanding invoices first unless the school advises otherwise.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Official payment receipt', 'subtitle' => 'School fees',
                'document_no' => 'RCPT-' . (int) $payment['id'], 'watermark' => 'RECEIPT',
                'signatures' => ['Received by', 'School stamp'], 'footer_note' => 'Official payment acknowledgement',
            ]),
            'filename' => 'receipt-RCPT-' . (int) $payment['id'] . '.pdf',
            'landscape' => false,
        ];
    }

    public function feeStatement(int $studentId, int $yearId = 0): array
    {
        $student = $this->student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');

        $filter = ' AND i.status <> \'void\'';
        $params = ['student' => $studentId];
        if ($yearId > 0) { $filter .= ' AND t.academic_year_id=:year'; $params['year'] = $yearId; }
        $query = $this->pdo->prepare('SELECT i.id,i.invoice_number,i.status,i.issued_at,i.due_at,t.name term_name,y.name year_name, COALESCE((SELECT SUM(ii.amount-ii.discount) FROM invoice_items ii WHERE ii.invoice_id=i.id),0) charged, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=i.id AND p.status=\'succeeded\'),0) paid FROM invoices i JOIN terms t ON t.id=i.term_id JOIN academic_years y ON y.id=t.academic_year_id WHERE i.student_id=:student' . $filter . ' ORDER BY y.starts_on,t.starts_on,i.id');
        $query->execute($params);
        $invoices = $query->fetchAll();

        $payQuery = $this->pdo->prepare("SELECT p.id,p.amount,p.method,p.reference,p.created_at, COALESCE(SUM(pa.amount),0) allocated FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.id JOIN invoices i ON i.id=pa.invoice_id WHERE i.student_id=:student AND p.status='succeeded' GROUP BY p.id,p.amount,p.method,p.reference,p.created_at ORDER BY p.created_at DESC LIMIT 40");
        $payQuery->execute(['student' => $studentId]);
        $payments = $payQuery->fetchAll();

        $charged = 0.0; $paid = 0.0;
        foreach ($invoices as $invoice) { $charged += (float) $invoice['charged']; $paid += (float) $invoice['paid']; }
        $balance = max(0, $charged - $paid);

        $body = school_document_meta([
            'Student' => $student['full_name'], 'Admission number' => $student['admission_number'],
            'Class' => $student['class_name'] ?? '', 'Academic year' => $student['year_name'] ?? '',
            'Guardian' => $student['guardian_name'] ?? '', 'Guardian phone' => $student['guardian_phone'] ?? '',
        ]);
        $body .= school_document_summary([
            ['label' => 'Charged', 'value' => money($charged), 'hint' => count($invoices) . ' invoices'],
            ['label' => 'Paid', 'value' => money($paid), 'hint' => percent($paid, $charged) . '% settled'],
            ['label' => 'Balance', 'value' => money($balance), 'hint' => $balance > 0 ? 'Outstanding' : 'Fully settled'],
        ]);
        $body .= '<h3 class="section">Invoice ledger</h3><table class="data"><thead><tr><th>Invoice</th><th>Period</th><th>Issued</th><th>Due</th><th class="num">Charged</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>';
        foreach ($invoices as $invoice) {
            $invoiceBalance = max(0, (float) $invoice['charged'] - (float) $invoice['paid']);
            $body .= '<tr><td>' . e((string) $invoice['invoice_number']) . '</td><td>' . e((string) ($invoice['year_name'] . ' · ' . $invoice['term_name'])) . '</td>'
                . '<td>' . e(formatDate(substr((string) $invoice['issued_at'], 0, 10))) . '</td><td>' . e(formatDate($invoice['due_at'])) . '</td>'
                . '<td class="num">' . e(money($invoice['charged'])) . '</td><td class="num">' . e(money($invoice['paid'])) . '</td>'
                . '<td class="num">' . e(money($invoiceBalance)) . '</td><td>' . e(ucwords(str_replace('_', ' ', (string) $invoice['status']))) . '</td></tr>';
        }
        if ($invoices === []) $body .= '<tr><td colspan="8" class="center">No invoices recorded.</td></tr>';
        $body .= '</tbody><tfoot><tr><td colspan="4">Totals</td><td class="num">' . e(money($charged)) . '</td><td class="num">' . e(money($paid)) . '</td><td class="num">' . e(money($balance)) . '</td><td></td></tr></tfoot></table>';
        $body .= '<h3 class="section">Recent payments</h3><table class="data"><thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach ($payments as $payment) {
            $body .= '<tr><td>RCPT-' . (int) $payment['id'] . '</td><td>' . e(formatDate(substr((string) $payment['created_at'], 0, 10))) . '</td>'
                . '<td>' . e(ucwords(str_replace('_', ' ', (string) $payment['method']))) . '</td><td>' . e((string) ($payment['reference'] ?: '—')) . '</td>'
                . '<td class="num">' . e(money($payment['allocated'])) . '</td></tr>';
        }
        if ($payments === []) $body .= '<tr><td colspan="5" class="center">No payments recorded.</td></tr>';
        $body .= '</tbody></table><div class="note">This statement is issued for information and does not replace official receipts. Please report any discrepancy to the bursar within 14 days.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Fee statement', 'subtitle' => ($student['year_name'] ?? 'All years') . ' · ' . $student['full_name'],
                'document_no' => 'FS-' . $studentId . '-' . date('Ymd'), 'watermark' => 'STATEMENT',
                'signatures' => ['Bursar / Accountant', 'Parent / Guardian'], 'footer_note' => 'Fee statement',
            ]),
            'filename' => 'fee-statement-' . $student['admission_number'] . '.pdf',
            'landscape' => false,
        ];
    }

    // ---------------------------------------------------------------- registers

    public function classList(int $classId): array
    {
        $class = $this->classInfo($classId);
        if (!$class) throw new RuntimeException('Class not found.');
        $students = $this->pdo->prepare("SELECT s.admission_number,s.full_name,s.gender,s.date_of_birth,s.admission_date,s.status,st.name stream_name, (SELECT GROUP_CONCAT(CONCAT(u.full_name,' (',COALESCE(sg.relationship,'guardian'),') ',COALESCE(u.phone,'')) SEPARATOR '; ') FROM student_guardians sg JOIN guardians g ON g.id=sg.guardian_id JOIN users u ON u.id=g.user_id WHERE sg.student_id=s.id AND sg.ended_at IS NULL) guardians FROM students s JOIN enrollments en ON en.student_id=s.id AND en.status='active' AND en.academic_year_id=:year AND en.class_group_id=:class LEFT JOIN streams st ON st.id=en.stream_id WHERE s.status='active' ORDER BY s.full_name");
        $students->execute(['year' => $class['academic_year_id'], 'class' => $classId]);
        $rows = $students->fetchAll();

        $body = school_document_meta([
            'Class' => $class['name'], 'Academic year' => $class['year_name'],
            'Level' => ucfirst((string) $class['level']), 'Learners' => count($rows),
            'Class teacher' => $class['teacher_name'] ?? '—', 'Generated' => date('d/m/Y H:i'),
        ]);
        $body .= '<h3 class="section">Class register</h3><table class="data"><thead><tr><th>#</th><th>Admission</th><th>Full name</th><th>Gender</th><th>Date of birth</th><th>Stream</th><th>Guardian contact</th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $body .= '<tr><td>' . ($index + 1) . '</td><td>' . e((string) $row['admission_number']) . '</td><td>' . e((string) $row['full_name']) . '</td>'
                . '<td>' . e(ucfirst((string) $row['gender'])) . '</td><td>' . e(formatDate($row['date_of_birth'])) . '</td>'
                . '<td>' . e((string) ($row['stream_name'] ?? '—')) . '</td><td>' . e((string) ($row['guardians'] ?? '—')) . '</td></tr>';
        }
        if ($rows === []) $body .= '<tr><td colspan="7" class="center">No active learners in this class.</td></tr>';
        $body .= '</tbody></table><div class="note">Confidential school register. Handle in line with the school data-protection policy.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Class register', 'subtitle' => $class['year_name'] . ' · ' . $class['name'],
                'document_no' => 'CL-' . $classId . '-' . date('Ymd'), 'orientation' => 'landscape',
                'signatures' => ['Class teacher', 'Head teacher'], 'footer_note' => 'Class register',
            ]),
            'filename' => 'class-register-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $class['name']) . '.pdf',
            'landscape' => true,
        ];
    }

    public function attendanceReport(int $classId, string $from, string $to): array
    {
        $class = $this->classInfo($classId);
        if (!$class) throw new RuntimeException('Class not found.');
        $query = $this->pdo->prepare("SELECT s.id,s.admission_number,s.full_name, COUNT(ar.id) marked, SUM(ar.state='present') present_count, SUM(ar.state='absent') absent_count, SUM(ar.state='late') late_count, SUM(ar.state='excused') excused_count FROM students s JOIN enrollments en ON en.student_id=s.id AND en.status='active' AND en.academic_year_id=:year AND en.class_group_id=:class LEFT JOIN attendance_records ar ON ar.student_id=s.id LEFT JOIN attendance_sessions sess ON sess.id=ar.session_id AND sess.session_date BETWEEN :from AND :to WHERE s.status='active' GROUP BY s.id,s.admission_number,s.full_name ORDER BY s.full_name");
        $query->execute(['year' => $class['academic_year_id'], 'class' => $classId, 'from' => $from, 'to' => $to]);
        $rows = $query->fetchAll();

        $totalPresent = 0; $totalMarked = 0;
        foreach ($rows as $row) { $totalPresent += (int) $row['present_count']; $totalMarked += (int) $row['marked']; }
        $rate = $totalMarked > 0 ? round($totalPresent * 100 / $totalMarked, 1) : 0;

        $body = school_document_meta([
            'Class' => $class['name'], 'Academic year' => $class['year_name'],
            'From' => formatDate($from), 'To' => formatDate($to),
            'Learners' => count($rows), 'Attendance rate' => $rate . '%',
        ]);
        $body .= '<h3 class="section">Attendance summary</h3><table class="data"><thead><tr><th>#</th><th>Admission</th><th>Learner</th><th class="num">Sessions</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Late</th><th class="num">Excused</th><th class="num">Rate</th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $rowRate = (int) $row['marked'] > 0 ? round((int) $row['present_count'] * 100 / (int) $row['marked'], 1) : 0;
            $body .= '<tr><td>' . ($index + 1) . '</td><td>' . e((string) $row['admission_number']) . '</td><td>' . e((string) $row['full_name']) . '</td>'
                . '<td class="num">' . (int) $row['marked'] . '</td><td class="num">' . (int) $row['present_count'] . '</td>'
                . '<td class="num">' . (int) $row['absent_count'] . '</td><td class="num">' . (int) $row['late_count'] . '</td>'
                . '<td class="num">' . (int) $row['excused_count'] . '</td><td class="num">' . e(number_format($rowRate, 1)) . '%</td></tr>';
        }
        if ($rows === []) $body .= '<tr><td colspan="9" class="center">No learners in this class.</td></tr>';
        $body .= '</tbody><tfoot><tr><td colspan="3">Class total</td><td class="num">' . $totalMarked . '</td><td class="num">' . $totalPresent . '</td><td colspan="3"></td><td class="num">' . e(number_format($rate, 1)) . '%</td></tr></tfoot></table>';
        $body .= '<div class="note">Attendance is calculated from marked sessions in the selected period. Blank sessions are excluded from the rate.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Attendance report', 'subtitle' => $class['year_name'] . ' · ' . $class['name'],
                'document_no' => 'ATT-' . $classId . '-' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to),
                'orientation' => 'landscape', 'signatures' => ['Class teacher', 'Head teacher'], 'footer_note' => 'Attendance report',
            ]),
            'filename' => 'attendance-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $class['name']) . '-' . $from . '-to-' . $to . '.pdf',
            'landscape' => true,
        ];
    }

    // ---------------------------------------------------------------- student documents

    public function transcript(int $studentId): array
    {
        $student = $this->student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');
        $query = $this->pdo->prepare("SELECT y.name year_name,t.name term_name,e.name exam_name,sub.code,sub.name subject_name,r.mark,a.max_mark,r.status,rv.grade FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN subjects sub ON sub.id=a.subject_id JOIN examinations e ON e.id=a.examination_id JOIN terms t ON t.id=e.term_id JOIN academic_years y ON y.id=t.academic_year_id LEFT JOIN result_versions rv ON rv.result_id=r.id AND rv.version=r.current_version WHERE r.student_id=:student AND r.status IN ('approved','published') ORDER BY y.starts_on,t.starts_on,sub.name");
        $query->execute(['student' => $studentId]);
        $rows = $query->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['year_name'] . '|' . $row['term_name'] . '|' . $row['exam_name'];
            $grouped[$key][] = $row;
        }

        $body = school_document_meta([
            'Student' => $student['full_name'], 'Admission number' => $student['admission_number'],
            'Current class' => $student['class_name'] ?? '', 'Academic year' => $student['year_name'] ?? '',
            'Date of birth' => formatDate($student['date_of_birth']), 'Admission date' => formatDate($student['admission_date']),
        ]);
        foreach ($grouped as $key => $items) {
            [$year, $term, $exam] = explode('|', $key);
            $total = 0.0; $max = 0.0;
            $body .= '<h3 class="section">' . e($year . ' · ' . $term . ' · ' . $exam) . '</h3><table class="data"><thead><tr><th>Subject</th><th>Code</th><th class="num">Mark</th><th class="num">Maximum</th><th class="num">Percent</th><th>Grade</th></tr></thead><tbody>';
            foreach ($items as $item) {
                $itemMax = (float) $item['max_mark'];
                $percent = $itemMax > 0 ? (float) $item['mark'] * 100 / $itemMax : 0;
                $total += (float) $item['mark']; $max += $itemMax;
                $body .= '<tr><td>' . e((string) $item['subject_name']) . '</td><td>' . e((string) $item['code']) . '</td>'
                    . '<td class="num">' . e(num($item['mark'])) . '</td><td class="num">' . e(num($item['max_mark'])) . '</td>'
                    . '<td class="num">' . e(number_format($percent, 1)) . '%</td><td>' . e((string) (($item['grade'] ?? '') ?: $this->gradeFor($percent))) . '</td></tr>';
            }
            $body .= '</tbody><tfoot><tr><td colspan="2">Average</td><td class="num">' . e(num($total)) . '</td><td class="num">' . e(num($max)) . '</td><td class="num">' . e($max > 0 ? number_format($total * 100 / $max, 1) : '0.0') . '%</td><td></td></tr></tfoot></table>';
        }
        if ($grouped === []) $body .= '<p class="small">No approved results are available for this learner yet.</p>';
        $body .= '<div class="note">This transcript lists approved and published results only. It is not valid without the school stamp and signature.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Academic transcript', 'subtitle' => $student['full_name'] . ' · ' . $student['admission_number'],
                'document_no' => 'TR-' . $studentId . '-' . date('Ymd'), 'watermark' => 'TRANSCRIPT',
                'signatures' => ['Academic registrar', 'Head teacher'], 'footer_note' => 'Academic transcript',
            ]),
            'filename' => 'transcript-' . $student['admission_number'] . '.pdf',
            'landscape' => false,
        ];
    }

    public function admissionLetter(int $studentId): array
    {
        $student = $this->student($studentId);
        if (!$student) throw new RuntimeException('Student not found.');
        $body = '<p>Dear ' . e((string) ($student['guardian_name'] ?: 'Parent / Guardian')) . ',</p>'
            . '<p><strong>RE: OFFER OF ADMISSION — ' . e(mb_strtoupper((string) $student['full_name'])) . '</strong></p>'
            . '<p>Following your application, we are pleased to offer admission to <strong>' . e((string) $student['full_name']) . '</strong> '
            . '(admission number <strong>' . e((string) $student['admission_number']) . '</strong>) in <strong>' . e((string) ($student['class_name'] ?? 'the school')) . '</strong> '
            . 'for the <strong>' . e((string) ($student['year_name'] ?? 'current academic year')) . '</strong> academic year.</p>';
        $body .= school_document_meta([
            'Admission number' => $student['admission_number'], 'Class' => $student['class_name'] ?? '',
            'Academic year' => $student['year_name'] ?? '', 'Reporting date' => formatDate($student['year_starts_on'] ?? null),
            'Admission date' => formatDate($student['admission_date']), 'Status' => ucfirst((string) $student['status']),
        ]);
        $body .= '<h3 class="section">Next steps</h3><ol class="small" style="padding-left:18px;line-height:1.7">'
            . '<li>Report to the school office on the reporting date with this letter and the learner\'s birth certificate.</li>'
            . '<li>Complete the medical and emergency-contact forms before the first day of classes.</li>'
            . '<li>Settle the term fee invoice or agree a payment plan with the bursar.</li>'
            . '<li>Collect the school uniform, timetable and learner handbook from the admissions desk.</li></ol>';
        $body .= '<p>We look forward to welcoming ' . e(explode(' ', (string) $student['full_name'])[0]) . ' to our school community.</p>'
            . '<p>Yours faithfully,</p>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Offer of admission', 'subtitle' => 'Admissions office',
                'document_no' => 'ADM-' . $student['admission_number'], 'watermark' => 'ADMISSION',
                'signatures' => ['Admissions officer', 'Head teacher'], 'footer_note' => 'Official admission letter',
            ]),
            'filename' => 'admission-letter-' . $student['admission_number'] . '.pdf',
            'landscape' => false,
        ];
    }

    public function idCards(int $studentId, int $classId = 0): array
    {
        if ($studentId > 0) {
            $students = [$this->student($studentId)];
        } else {
            $class = $this->classInfo($classId);
            if (!$class) throw new RuntimeException('Class not found.');
            $query = $this->pdo->prepare("SELECT s.id,s.admission_number,s.full_name,s.gender,s.date_of_birth,s.admission_date,s.status,en.class_group_id FROM students s JOIN enrollments en ON en.student_id=s.id AND en.status='active' AND en.academic_year_id=:year AND en.class_group_id=:class WHERE s.status='active' ORDER BY s.full_name");
            $query->execute(['year' => $class['academic_year_id'], 'class' => $classId]);
            $students = [];
            foreach ($query->fetchAll() as $row) $students[] = $this->student((int) $row['id']);
        }
        $students = array_values(array_filter($students));
        if ($students === []) throw new RuntimeException('No learners found for ID cards.');

        $brand = school_document_brand();
        $logo = school_logo_data_uri($brand['logo_path'] ?? null);
        $body = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6mm">';
        foreach ($students as $student) {
            $body .= '<div class="id-card avoid-break"><div class="stripe"></div><div class="body">'
                . '<div class="school">' . ($logo ? '<img src="' . e($logo) . '" style="width:18px;height:18px;vertical-align:-4px;margin-right:5px">' : '') . e((string) ($brand['name'] ?? 'School')) . '</div>'
                . '<div class="name">' . e((string) $student['full_name']) . '</div>'
                . '<div class="meta-line">Admission: <strong>' . e((string) $student['admission_number']) . '</strong></div>'
                . '<div class="meta-line">Class: ' . e((string) ($student['class_name'] ?? '—')) . ' · ' . e(ucfirst((string) $student['gender'])) . '</div>'
                . '<div class="meta-line">Valid: ' . e((string) ($student['year_name'] ?? date('Y'))) . '</div>'
                . '</div><div class="foot"><span>Learner identity card</span><span>' . e((string) ($brand['phone'] ?? '')) . '</span></div></div>';
        }
        $body .= '</div><div class="note">Cut along the card borders. If a card is lost, report it to the school office immediately so it can be cancelled and replaced.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Learner identity cards', 'subtitle' => count($students) . ' card(s)',
                'document_no' => 'ID-' . date('Ymd'), 'signatures' => [], 'footer_note' => 'Learner identity cards',
            ]),
            'filename' => 'learner-id-cards-' . date('Ymd') . '.pdf',
            'landscape' => false,
        ];
    }

    // ---------------------------------------------------------------- management report

    public function managementReport(array $filters, array $user, string $role): array
    {
        $data = (new SchoolAnalyticsRepository())->data($user, $role, $filters);
        $context = $data['context'];
        $kpis = $data['kpis'];
        $finance = $data['finance'];
        $academic = $data['academic'];

        $body = school_document_meta([
            'Academic year' => $context['year']['name'] ?? 'All years',
            'Term' => $context['term']['name'] ?? 'All terms',
            'Class' => $context['class']['name'] ?? 'All classes',
            'Period' => formatDate($context['from']) . ' – ' . formatDate($context['to']),
            'Prepared by' => $user['full_name'] . ' (' . school_role_label($role) . ')',
            'Generated' => date('d/m/Y H:i'),
        ]);
        $body .= school_document_summary([
            ['label' => 'Active learners', 'value' => number_format($kpis['students']), 'hint' => $kpis['classes'] . ' classes'],
            ['label' => 'Attendance rate', 'value' => num($kpis['attendance_rate']) . '%', 'hint' => number_format($kpis['marked_total']) . ' records'],
            ['label' => 'Average mark', 'value' => num($kpis['average_mark']) . '%', 'hint' => num($kpis['pass_rate']) . '% pass rate'],
            ['label' => 'Fees collected', 'value' => money($finance['paid']), 'hint' => num($finance['collection_rate']) . '% of charges'],
            ['label' => 'Outstanding', 'value' => money($finance['outstanding']), 'hint' => money($finance['overdue']) . ' overdue'],
            ['label' => 'Active staff', 'value' => number_format($kpis['staff']), 'hint' => $kpis['teachers'] . ' teaching'],
        ]);

        $body .= '<h3 class="section">Attendance by class</h3><table class="data"><thead><tr><th>Class</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Late</th></tr></thead><tbody>';
        foreach ($data['attendance_by_class']['labels'] as $index => $label) {
            $body .= '<tr><td>' . e((string) $label) . '</td><td class="num">' . (int) ($data['attendance_by_class']['present'][$index] ?? 0) . '</td>'
                . '<td class="num">' . (int) ($data['attendance_by_class']['absent'][$index] ?? 0) . '</td><td class="num">' . (int) ($data['attendance_by_class']['late'][$index] ?? 0) . '</td></tr>';
        }
        if ($data['attendance_by_class']['labels'] === []) $body .= '<tr><td colspan="4" class="center">No attendance records in this period.</td></tr>';
        $body .= '</tbody></table>';

        $body .= '<h3 class="section">Finance summary</h3><table class="data"><thead><tr><th>Metric</th><th class="num">Value</th></tr></thead><tbody>';
        foreach ([
            'Invoices issued' => number_format($finance['invoice_count']),
            'Fully paid invoices' => number_format($finance['paid_invoices']),
            'Total charged' => money($finance['charged']),
            'Total collected' => money($finance['paid']),
            'Outstanding balance' => money($finance['outstanding']),
            'Overdue balance' => money($finance['overdue']),
            'Collection rate' => num($finance['collection_rate']) . '%',
        ] as $label => $value) $body .= '<tr><td>' . e($label) . '</td><td class="num">' . e($value) . '</td></tr>';
        $body .= '</tbody></table>';

        $body .= '<h3 class="section">Subject performance</h3><table class="data"><thead><tr><th>Subject</th><th class="num">Entries</th><th class="num">Average</th><th class="num">Pass rate</th></tr></thead><tbody>';
        foreach ($academic['subjects'] as $subject) {
            $body .= '<tr><td>' . e((string) $subject['label']) . '</td><td class="num">' . (int) $subject['entries'] . '</td>'
                . '<td class="num">' . e(num($subject['value'])) . '%</td><td class="num">' . e(num($subject['pass_rate'])) . '%</td></tr>';
        }
        if ($academic['subjects'] === []) $body .= '<tr><td colspan="4" class="center">No approved results in scope.</td></tr>';
        $body .= '</tbody></table>';

        $body .= '<h3 class="section">Learners needing support</h3><table class="data"><thead><tr><th>Learner</th><th>Admission</th><th>Class</th><th class="num">Attendance</th><th class="num">Fee balance</th></tr></thead><tbody>';
        foreach ($data['at_risk'] as $risk) {
            $body .= '<tr><td>' . e((string) $risk['full_name']) . '</td><td>' . e((string) $risk['admission_number']) . '</td><td>' . e((string) ($risk['class_name'] ?? '—')) . '</td>'
                . '<td class="num">' . e(num($risk['attendance_rate'])) . '%</td><td class="num">' . e(money($risk['balance'])) . '</td></tr>';
        }
        if ($data['at_risk'] === []) $body .= '<tr><td colspan="5" class="center">No learners currently cross the support threshold.</td></tr>';
        $body .= '</tbody></table><div class="note">Management report generated from live school records. Figures respect the prepared-by account scope at the time of generation.</div>';

        return [
            'html' => school_document_shell($body, [
                'title' => 'Management report', 'subtitle' => 'School performance and finance overview',
                'document_no' => 'MR-' . date('Ymd-His'), 'orientation' => 'landscape',
                'signatures' => ['Prepared by', 'Head teacher', 'School owner'], 'footer_note' => 'Management report',
            ]),
            'filename' => 'management-report-' . date('Ymd-His') . '.pdf',
            'landscape' => true,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function buildReportPayload(int $studentId, int $examId): array
    {
        $query = $this->pdo->prepare("SELECT st.id,st.full_name,st.admission_number,cg.id class_group_id,cg.name class_name,y.id year_id,y.name year_name,t.id term_id,t.name term_name,e.id exam_id,e.name exam_name,e.type exam_type FROM students st JOIN enrollments en ON en.student_id=st.id AND en.status='active' JOIN class_groups cg ON cg.id=en.class_group_id JOIN academic_years y ON y.id=en.academic_year_id JOIN examinations e ON e.id=:exam JOIN terms t ON t.id=e.term_id AND t.academic_year_id=y.id WHERE st.id=:student LIMIT 1");
        $query->execute(['exam' => $examId, 'student' => $studentId]);
        $header = $query->fetch();
        if (!$header) throw new RuntimeException('The learner is not enrolled in the assessment year.');

        $results = $this->pdo->prepare("SELECT sub.code,sub.name subject_name,r.mark,a.max_mark,rv.grade,rv.comments FROM results r JOIN assessments a ON a.id=r.assessment_id JOIN subjects sub ON sub.id=a.subject_id LEFT JOIN result_versions rv ON rv.result_id=r.id AND rv.version=r.current_version WHERE a.examination_id=:exam AND a.class_group_id=:class AND r.student_id=:student AND r.status IN ('approved','published') ORDER BY sub.name");
        $results->execute(['exam' => $examId, 'class' => (int) $header['class_group_id'], 'student' => $studentId]);
        $items = $results->fetchAll();
        $earned = 0.0; $possible = 0.0;
        foreach ($items as $item) { $earned += (float) $item['mark']; $possible += (float) $item['max_mark']; }

        return [
            'student' => ['id' => $studentId, 'name' => $header['full_name'], 'admission_number' => $header['admission_number'], 'class_group_id' => (int) $header['class_group_id'], 'class' => $header['class_name']],
            'academic_year' => ['id' => (int) $header['year_id'], 'name' => $header['year_name']],
            'term' => ['id' => (int) $header['term_id'], 'name' => $header['term_name']],
            'examination' => ['id' => $examId, 'name' => $header['exam_name'], 'type' => $header['exam_type']],
            'results' => $items,
            'total_mark' => $earned,
            'maximum_mark' => $possible,
            'average_percentage' => $possible > 0 ? round($earned * 100 / $possible, 2) : null,
        ];
    }

    private function student(int $studentId): ?array
    {
        $query = $this->pdo->prepare("SELECT s.id,s.admission_number,s.full_name,s.gender,s.date_of_birth,s.admission_date,s.status,cg.id class_group_id,cg.name class_name,cg.level,y.id year_id,y.name year_name,y.starts_on year_starts_on,st.name stream_name, (SELECT u.full_name FROM student_guardians sg JOIN guardians g ON g.id=sg.guardian_id JOIN users u ON u.id=g.user_id WHERE sg.student_id=s.id AND sg.ended_at IS NULL ORDER BY sg.verification_status='verified' DESC LIMIT 1) guardian_name, (SELECT u.phone FROM student_guardians sg JOIN guardians g ON g.id=sg.guardian_id JOIN users u ON u.id=g.user_id WHERE sg.student_id=s.id AND sg.ended_at IS NULL ORDER BY sg.verification_status='verified' DESC LIMIT 1) guardian_phone FROM students s LEFT JOIN enrollments en ON en.student_id=s.id AND en.status='active' LEFT JOIN class_groups cg ON cg.id=en.class_group_id LEFT JOIN academic_years y ON y.id=en.academic_year_id LEFT JOIN streams st ON st.id=en.stream_id WHERE s.id=:id LIMIT 1");
        $query->execute(['id' => $studentId]);
        return $query->fetch() ?: null;
    }

    private function classInfo(int $classId): ?array
    {
        $query = $this->pdo->prepare("SELECT c.id,c.name,c.level,c.academic_year_id,y.name year_name, (SELECT u.full_name FROM teacher_assignments ta JOIN staff sf ON sf.id=ta.staff_id JOIN users u ON u.id=sf.user_id WHERE ta.class_group_id=c.id AND ta.ended_at IS NULL GROUP BY u.id ORDER BY COUNT(*) DESC LIMIT 1) teacher_name FROM class_groups c JOIN academic_years y ON y.id=c.academic_year_id WHERE c.id=:id");
        $query->execute(['id' => $classId]);
        return $query->fetch() ?: null;
    }

    private function gradeFor(float $percent): string
    {
        return $percent >= 80 ? 'A' : ($percent >= 70 ? 'B' : ($percent >= 60 ? 'C' : ($percent >= 50 ? 'D' : ($percent >= 40 ? 'E' : 'F'))));
    }
}
