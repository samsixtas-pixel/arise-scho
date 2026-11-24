<?php
/**
 * Loan.php
 * -------------------------------------------------------
 * Weekly flat loans. A loan knows its customer, schedule and
 * derived status (via v_loans_with_status), and owns the
 * rules for creation, listing and write-off.
 * -------------------------------------------------------
 */

class Loan extends Model
{
    protected function getTable(): string
    {
        return 'loans';
    }

    /** Next sequential loan reference (LN-0001, ...). */
    public function nextReference(): string
    {
        $max = (int) $this->db
            ->query("SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)), 0) FROM loans")
            ->fetchColumn();

        return 'LN-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Disburse a new loan and build its repayment schedule.
     *
     * @throws ValidationException
     */
    public function create(array $data): int
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $principal  = round((float) ($data['principal'] ?? 0), 2);
        $weekly     = round((float) ($data['weekly_amount'] ?? 0), 2);
        $weeks      = (int) ($data['number_of_weeks'] ?? Settings::defaultWeeks());
        $loanDate   = Security::safeDate($data['loan_date'] ?? date('Y-m-d'));

        $errors = [];

        $customer = $customerId > 0 ? (new Customer())->find($customerId) : false;

        if (!$customer || $customer['deleted_at'] !== null) {
            $errors[] = t('Select a valid customer.');
        } elseif ($customer['status'] === 'blacklisted') {
            $errors[] = t('This customer is blacklisted and cannot receive a new loan.');
        }

        if ($principal <= 0) {
            $errors[] = t('Principal must be greater than zero.');
        }

        if ($weekly <= 0) {
            $errors[] = t('Weekly amount must be greater than zero.');
        }

        if ($weeks < 1 || $weeks > 104) {
            $errors[] = t('Number of weeks must be between 1 and 104.');
        }

        if ($loanDate > date('Y-m-d')) {
            $errors[] = t('The loan date cannot be in the future.');
        }

        if (!$errors && $customer) {
            $open = $this->db->prepare(
                "SELECT COUNT(*) FROM v_loans_with_status
                  WHERE customer_id = :c AND loan_status IN ('ACTIVE','DUE','OVERDUE')
                    AND loan_id <> 0"
            );
            $open->execute(['c' => $customerId]);

            if ((int) $open->fetchColumn() > 0) {
                $errors[] = t('This customer already has an open loan. Settle it before issuing another.');
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $totalPayable = round($weekly * $weeks, 2);
        $firstPayment = addDays($loanDate, 7);
        $finalPayment = addDays($firstPayment, ($weeks - 1) * 7);

        try {
            $this->db->beginTransaction();

            $reference = $this->nextReference();

            $stmt = $this->db->prepare(
                "INSERT INTO loans
                    (reference, customer_id, product_id, principal, weekly_amount, number_of_weeks,
                     total_payable, loan_date, first_payment_date, final_payment_date, disbursed_at,
                     notes, created_by)
                 VALUES
                    (:reference, :customer_id, :product_id, :principal, :weekly, :weeks,
                     :total, :loan_date, :first_payment, :final_payment, NOW(),
                     :notes, :created_by)"
            );

            $stmt->execute([
                'reference'     => $reference,
                'customer_id'   => $customerId,
                'product_id'    => !empty($data['product_id']) ? (int) $data['product_id'] : null,
                'principal'     => $principal,
                'weekly'        => $weekly,
                'weeks'         => $weeks,
                'total'         => $totalPayable,
                'loan_date'     => $loanDate,
                'first_payment' => $firstPayment,
                'final_payment' => $finalPayment,
                'notes'         => $data['notes'] ?: null,
                'created_by'    => currentUserId() ?: null,
            ]);

            $loanId = (int) $this->lastInsertId();

            $this->db->prepare(
                "INSERT INTO disbursements (loan_id, amount, method, reference, disbursed_by, disbursed_at)
                 VALUES (:loan_id, :amount, :method, :reference, :user_id, NOW())"
            )->execute([
                'loan_id'  => $loanId,
                'amount'   => $principal,
                'method'   => in_array($data['method'] ?? 'cash', ['cash', 'mobile_money', 'bank_transfer', 'other'], true)
                                ? $data['method'] : 'cash',
                'reference'=> !empty($data['disbursement_reference']) ? $data['disbursement_reference'] : null,
                'user_id'  => currentUserId(),
            ]);

            (new RepaymentSchedule())->generate($loanId, $firstPayment, $weeks, $weekly);

            AuditLog::record('loan.create', 'loans', $loanId,
                sprintf('Issued %s to %s — principal %s, %d weeks @ %s',
                    $reference, $customer['full_name'], money($principal), $weeks, money($weekly)));

            $this->db->commit();

            return $loanId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Loan plus customer, balance, schedule summary. */
    public function findDetailed(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, c.first_name, c.middle_name, c.last_name, c.nida, c.emergency_contact,
                    c.emergency_phone, c.data_status, c.status AS customer_status, c.notes AS customer_notes,
                    l.notes, l.disbursed_at, l.created_at, l.write_off_reason,
                    u.full_name AS officer_name,
                    (SELECT COUNT(*) FROM repayment_schedules s WHERE s.loan_id = v.loan_id AND s.status = 'paid') AS installments_paid,
                    (SELECT COUNT(*) FROM repayment_schedules s WHERE s.loan_id = v.loan_id AND s.status = 'missed') AS installments_missed
               FROM v_loans_with_status v
               JOIN customers c ON c.id = v.customer_id
               JOIN loans l ON l.id = v.loan_id
          LEFT JOIN users u ON u.id = c.assigned_officer_id
              WHERE v.loan_id = :id
              LIMIT 1"
        );

        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    /**
     * Paginated loan book.
     *
     * @param array $filters q, status, officer, customer, from, to
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ["1=1"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = "(v.loan_reference LIKE :q OR v.customer_name LIKE :q OR v.phone_number LIKE :q)";
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'OPEN') {
                $where[] = "v.loan_status IN ('ACTIVE','DUE','OVERDUE')";
            } else {
                $where[] = "v.loan_status = :status";
                $params['status'] = strtoupper($filters['status']);
            }
        }

        if (!empty($filters['customer'])) {
            $where[] = "v.customer_id = :customer";
            $params['customer'] = (int) $filters['customer'];
        }

        if (!empty($filters['officer'])) {
            $where[] = "c.assigned_officer_id = :officer";
            $params['officer'] = (int) $filters['officer'];
        }

        if (!empty($filters['from'])) {
            $where[] = "v.loan_date >= :from";
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = "v.loan_date <= :to";
            $params['to'] = $filters['to'];
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM v_loans_with_status v
               JOIN customers c ON c.id = v.customer_id
              WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page   = max(1, $page);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT v.*, c.assigned_officer_id
                  FROM v_loans_with_status v
                  JOIN customers c ON c.id = v.customer_id
                 WHERE {$whereSql}
              ORDER BY v.loan_id DESC
                 LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    /** Every loan for one customer, newest first. */
    public function forCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM v_loans_with_status
              WHERE customer_id = :id
              ORDER BY loan_id DESC"
        );
        $stmt->execute(['id' => $customerId]);

        return $stmt->fetchAll();
    }

    /** Portfolio counters and money totals for the dashboard. */
    public function portfolio(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_loans,
                SUM(loan_status IN ('ACTIVE','DUE','OVERDUE')) AS open_loans,
                SUM(loan_status = 'ACTIVE')  AS active_loans,
                SUM(loan_status = 'DUE')     AS due_loans,
                SUM(loan_status = 'OVERDUE') AS overdue_loans,
                SUM(loan_status = 'PAID')    AS paid_loans,
                SUM(loan_status = 'OVERPAID') AS overpaid_loans,
                SUM(loan_status = 'WRITTEN OFF') AS written_off,
                COALESCE(SUM(principal_amount), 0)      AS total_principal,
                COALESCE(SUM(total_amount_payable), 0)  AS total_payable,
                COALESCE(SUM(amount_paid), 0)           AS total_collected,
                COALESCE(SUM(CASE WHEN loan_status NOT IN ('PAID','WRITTEN OFF')
                                  THEN remaining_balance ELSE 0 END), 0) AS total_outstanding,
                COALESCE(SUM(CASE WHEN loan_status = 'OVERDUE'
                                  THEN remaining_balance ELSE 0 END), 0) AS total_overdue
              FROM v_loans_with_status"
        )->fetch();

        foreach ($row as $key => $value) {
            $row[$key] = is_numeric($value) ? (float) $value : $value;
        }

        return $row;
    }

    /** Customer counters for the dashboard. */
    public function customerMetrics(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_customers,
                SUM(status = 'active') AS active_customers,
                SUM(data_status = 'incomplete') AS incomplete_customers,
                SUM(EXISTS (SELECT 1 FROM v_loans_with_status v
                             WHERE v.customer_id = c.id
                               AND v.loan_status IN ('ACTIVE','DUE','OVERDUE'))) AS borrowers
              FROM customers c
             WHERE c.deleted_at IS NULL"
        )->fetch();

        foreach ($row as $key => $value) {
            $row[$key] = (int) $value;
        }

        return $row;
    }

    /** Collections grouped by ISO week (for the dashboard bars). */
    public function weeklyCollections(int $weeks = 13): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_SUB(DATE(paid_at), INTERVAL WEEKDAY(paid_at) DAY) AS week_start,
                    SUM(amount) AS collected,
                    COUNT(*) AS payments
               FROM payments
              WHERE status = 'confirmed'
              GROUP BY week_start
              ORDER BY week_start DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':lim', $weeks, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }

    /** Write a loan off (admin action) — stops it counting as collectable. */
    public function writeOff(int $id, string $reason): void
    {
        $loan = $this->find($id);

        if (!$loan) {
            throw new RuntimeException('Loan not found.');
        }

        if ($reason === '') {
            throw new ValidationException([t('A reason is required to write off a loan.')]);
        }

        $this->db->prepare(
            "UPDATE loans
                SET is_written_off = 1, written_off_at = NOW(), write_off_reason = :reason
              WHERE id = :id"
        )->execute(['reason' => $reason, 'id' => $id]);

        AuditLog::record('loan.write_off', 'loans', $id,
            'Wrote off ' . $loan['reference'] . ': ' . $reason);
    }

    public function softDelete(int $id): void
    {
        $payments = (int) $this->db->query("SELECT COUNT(*) FROM payments WHERE loan_id = {$id}")->fetchColumn();

        if ($payments > 0) {
            throw new RuntimeException(t('A loan with payments cannot be deleted. Write it off instead.'));
        }

        $this->db->prepare("UPDATE loans SET deleted_at = NOW() WHERE id = :id")->execute(['id' => $id]);

        AuditLog::record('loan.delete', 'loans', $id, 'Deleted loan #' . $id);
    }
}
