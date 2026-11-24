<?php
/**
 * Payment.php
 * -------------------------------------------------------
 * Receipting, reversal and the payment ledger.
 *
 * Every write runs in a transaction and then rebuilds the
 * loan's instalment allocations, so a receipt can never
 * leave the schedule inconsistent.
 * -------------------------------------------------------
 */

class Payment extends Model
{
    protected function getTable(): string
    {
        return 'payments';
    }

    public function nextReference(): string
    {
        $max = (int) $this->db
            ->query("SELECT COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) FROM payments")
            ->fetchColumn();

        return 'PMT-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Record a payment against a loan.
     *
     * @param array $data loan_id, amount, paid_at, method, notes, external_reference
     * @throws ValidationException
     */
    public function record(array $data): int
    {
        $loanId = (int) ($data['loan_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $method = $data['method'] ?? 'cash';
        $paidAt = $this->normaliseDateTime((string) ($data['paid_at'] ?? ''));

        $errors = [];

        $loan = null;

        if ($loanId > 0) {
            $stmt = $this->db->prepare(
                "SELECT l.*, c.full_name
                   FROM loans l
                   JOIN customers c ON c.id = l.customer_id
                  WHERE l.id = :id AND l.deleted_at IS NULL
                  LIMIT 1"
            );
            $stmt->execute(['id' => $loanId]);
            $loan = $stmt->fetch();
        }

        if (!$loan) {
            $errors[] = t('Select a valid loan.');
        }

        if ($amount <= 0) {
            $errors[] = t('Enter an amount greater than zero.');
        }

        if ($amount > 100000000) {
            $errors[] = t('That amount looks too large. Please check it.');
        }

        if (!in_array($method, ['cash', 'mobile_money', 'bank_transfer', 'other'], true)) {
            $errors[] = t('Select a valid payment method.');
        }

        if ($paidAt === null) {
            $errors[] = t('Enter a valid payment date.');
        } elseif (strtotime($paidAt) > strtotime('+1 day')) {
            $errors[] = t('The payment date cannot be in the future.');
        }

        if (!$errors && $loan) {
            if ((int) $loan['is_written_off'] === 1) {
                $errors[] = t('This loan has been written off and cannot receive payments.');
            } elseif (strtotime($paidAt) < strtotime($loan['loan_date'])) {
                $errors[] = t('The payment date cannot be before the loan date (:date).', ['date' => formatDate($loan['loan_date'])]);
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        try {
            $this->db->beginTransaction();

            // Lock the loan row so two officers cannot race a receipt.
            $lock = $this->db->prepare("SELECT id FROM loans WHERE id = :id FOR UPDATE");
            $lock->execute(['id' => $loanId]);

            $balance = $this->outstandingBalance($loanId);

            if ($balance <= 0 && (float) $loan['total_payable'] > 0) {
                throw new ValidationException([t('This loan is already fully paid.')]);
            }

            $reference = $this->nextReference();

            $stmt = $this->db->prepare(
                "INSERT INTO payments
                    (reference, loan_id, customer_id, amount, method, status,
                     received_by, paid_at, external_reference, notes)
                 VALUES
                    (:reference, :loan_id, :customer_id, :amount, :method, 'confirmed',
                     :received_by, :paid_at, :external_reference, :notes)"
            );

            $stmt->execute([
                'reference'          => $reference,
                'loan_id'            => $loanId,
                'customer_id'        => (int) $loan['customer_id'],
                'amount'             => $amount,
                'method'             => $method,
                'received_by'        => currentUserId(),
                'paid_at'            => $paidAt,
                'external_reference' => $data['external_reference'] ?: null,
                'notes'              => $data['notes'] ?: null,
            ]);

            $paymentId = (int) $this->lastInsertId();

            (new RepaymentSchedule())->rebuild($loanId);

            AuditLog::record('payment.create', 'payments', $paymentId,
                sprintf('Received %s from %s against %s (%s)',
                    money($amount), $loan['full_name'], $loan['reference'], $reference));

            $this->db->commit();

            return $paymentId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Reverse a receipt. Reversal is an admin-only correction and keeps history. */
    public function reverse(int $paymentId, string $reason): void
    {
        $payment = $this->find($paymentId);

        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }

        if ($payment['status'] === 'reversed') {
            throw new RuntimeException(t('This payment has already been reversed.'));
        }

        if (trim($reason) === '') {
            throw new ValidationException([t('A reason is required to reverse a payment.')]);
        }

        try {
            $this->db->beginTransaction();

            $this->db->prepare("UPDATE payments SET status = 'reversed' WHERE id = :id")
                     ->execute(['id' => $paymentId]);

            $this->db->prepare(
                "INSERT INTO payment_reversals (original_payment_id, amount, reason, reversed_by, reversed_at)
                 VALUES (:id, :amount, :reason, :user_id, NOW())"
            )->execute([
                'id'     => $paymentId,
                'amount' => $payment['amount'],
                'reason' => $reason,
                'user_id'=> currentUserId(),
            ]);

            (new RepaymentSchedule())->rebuild((int) $payment['loan_id']);

            AuditLog::record('payment.reverse', 'payments', $paymentId,
                sprintf('Reversed %s (%s): %s', $payment['reference'], money($payment['amount']), $reason));

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function outstandingBalance(int $loanId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
              WHERE loan_id = :id AND status = 'confirmed'"
        );
        $stmt->execute(['id' => $loanId]);
        $paid = (float) $stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT total_payable FROM loans WHERE id = :id");
        $stmt->execute(['id' => $loanId]);
        $total = (float) $stmt->fetchColumn();

        return round($total - $paid, 2);
    }

    /**
     * Paginated ledger with cumulative balances.
     *
     * @param array $filters q, from, to, method, loan_id, status, officer
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ["1=1"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = "(v.payment_reference LIKE :q OR v.loan_reference LIKE :q
                         OR v.customer_name LIKE :q OR v.phone_number LIKE :q)";
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = "DATE(v.payment_date) >= :from";
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = "DATE(v.payment_date) <= :to";
            $params['to'] = $filters['to'];
        }

        if (!empty($filters['method'])) {
            $where[] = "v.method = :method";
            $params['method'] = $filters['method'];
        }

        if (!empty($filters['loan_id'])) {
            $where[] = "v.loan_id = :loan_id";
            $params['loan_id'] = (int) $filters['loan_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "v.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['officer'])) {
            $where[] = "p.received_by = :officer";
            $params['officer'] = (int) $filters['officer'];
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
               FROM v_payments_with_balance v
               JOIN payments p ON p.id = v.payment_id
              WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page   = max(1, $page);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT v.*, p.received_by, p.external_reference, p.created_at,
                       u.full_name AS received_by_name
                  FROM v_payments_with_balance v
                  JOIN payments p ON p.id = v.payment_id
             LEFT JOIN users u ON u.id = p.received_by
                 WHERE {$whereSql}
              ORDER BY v.payment_date DESC, v.payment_id DESC
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

    /** Confirmed payments for one loan, oldest first (statement order). */
    public function forLoan(int $loanId, bool $includeReversed = false): array
    {
        $sql = "SELECT * FROM v_payments_with_balance
                 WHERE loan_id = :id";

        if (!$includeReversed) {
            $sql .= " AND status = 'confirmed'";
        }

        $sql .= " ORDER BY payment_date, payment_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $loanId]);

        return $stmt->fetchAll();
    }

    /** Totals for a date range, used by reconciliation and reports. */
    public function summary(string $from, string $to, ?int $officerId = null): array
    {
        $sql = "SELECT COUNT(*) AS receipts,
                       COALESCE(SUM(amount), 0) AS total,
                       COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) AS cash,
                       COALESCE(SUM(CASE WHEN method = 'mobile_money' THEN amount ELSE 0 END), 0) AS mobile,
                       COALESCE(SUM(CASE WHEN method = 'bank_transfer' THEN amount ELSE 0 END), 0) AS bank,
                       COALESCE(SUM(CASE WHEN method = 'other' THEN amount ELSE 0 END), 0) AS other,
                       COUNT(DISTINCT customer_id) AS customers
                  FROM payments
                 WHERE status = 'confirmed'
                   AND DATE(paid_at) BETWEEN :from AND :to";

        $params = ['from' => $from, 'to' => $to];

        if ($officerId !== null && $officerId > 0) {
            $sql .= " AND received_by = :officer";
            $params['officer'] = $officerId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch() ?: [];

        foreach ($row as $key => $value) {
            $row[$key] = is_numeric($value) ? (float) $value : $value;
        }

        return $row;
    }

    /** Daily collection totals for a month/range. */
    public function dailyTotals(string $from, string $to, ?int $officerId = null): array
    {
        $sql = "SELECT DATE(paid_at) AS day, SUM(amount) AS total, COUNT(*) AS receipts
                  FROM payments
                 WHERE status = 'confirmed' AND DATE(paid_at) BETWEEN :from AND :to";

        $params = ['from' => $from, 'to' => $to];

        if ($officerId !== null && $officerId > 0) {
            $sql .= " AND received_by = :officer";
            $params['officer'] = $officerId;
        }

        $sql .= " GROUP BY day ORDER BY day";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Receipt data for the printable slip. */
    public function receipt(int $paymentId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, p.received_by, p.external_reference, p.created_at, p.status AS payment_status,
                    u.full_name AS received_by_name, c.reference AS customer_reference,
                    c.phone AS customer_phone
               FROM v_payments_with_balance v
               JOIN payments p ON p.id = v.payment_id
               JOIN customers c ON c.id = v.customer_id
          LEFT JOIN users u ON u.id = p.received_by
              WHERE v.payment_id = :id
              LIMIT 1"
        );

        $stmt->execute(['id' => $paymentId]);

        return $stmt->fetch();
    }

    /** Today's counter for the dashboard/officer header. */
    public function todayTotal(?int $officerId = null): array
    {
        return $this->summary(date('Y-m-d'), date('Y-m-d'), $officerId);
    }

    private function normaliseDateTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // <input type="datetime-local"> sends 2026-11-15T14:30
        $value = str_replace('T', ' ', $value);

        $ts = strtotime($value);

        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
