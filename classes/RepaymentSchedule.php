<?php
/**
 * RepaymentSchedule.php
 * -------------------------------------------------------
 * One row per weekly instalment, plus the allocation engine
 * that distributes every confirmed payment over the oldest
 * unpaid instalments. Allocation is rebuilt from scratch on
 * each payment/reversal, so the schedule can never drift.
 * -------------------------------------------------------
 */

class RepaymentSchedule extends Model
{
    protected function getTable(): string
    {
        return 'repayment_schedules';
    }

    /** Create the weekly instalment rows for a loan. */
    public function generate(int $loanId, string $firstPaymentDate, int $weeks, float $weeklyAmount): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO repayment_schedules
                (loan_id, installment_number, due_date, amount_due, amount_paid, status)
             VALUES (:loan_id, :number, :due_date, :amount_due, 0, 'pending')"
        );

        for ($i = 1; $i <= $weeks; $i++) {
            $stmt->execute([
                'loan_id'   => $loanId,
                'number'    => $i,
                'due_date'  => addDays($firstPaymentDate, ($i - 1) * 7),
                'amount_due'=> $weeklyAmount,
            ]);
        }
    }

    /** All instalments for a loan, oldest first. */
    public function forLoan(int $loanId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM repayment_schedules
              WHERE loan_id = :id
              ORDER BY installment_number"
        );
        $stmt->execute(['id' => $loanId]);

        return $stmt->fetchAll();
    }

    /**
     * Rebuild allocations and instalment statuses for a loan.
     * Safe to call repeatedly; the result depends only on the
     * set of confirmed payments.
     */
    public function rebuild(int $loanId): void
    {
        $ownTransaction = !$this->db->inTransaction();

        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $schedules = $this->forLoan($loanId);

            if (!$schedules) {
                if ($ownTransaction) {
                    $this->db->commit();
                }
                return;
            }

            $scheduleIds = array_column($schedules, 'id');
            $in          = implode(',', array_map('intval', $scheduleIds));

            $this->db->exec("DELETE FROM payment_allocations WHERE schedule_id IN ({$in})");

            $payments = $this->db->prepare(
                "SELECT id, amount, paid_at
                   FROM payments
                  WHERE loan_id = :loan_id AND status = 'confirmed'
                  ORDER BY paid_at, id"
            );
            $payments->execute(['loan_id' => $loanId]);
            $payments = $payments->fetchAll();

            $remaining = [];
            foreach ($schedules as $schedule) {
                $remaining[(int) $schedule['id']] = (float) $schedule['amount_due'];
            }

            $paidAt   = [];
            $insert   = $this->db->prepare(
                "INSERT INTO payment_allocations (payment_id, schedule_id, amount)
                 VALUES (:payment_id, :schedule_id, :amount)"
            );

            foreach ($payments as $payment) {
                $left = (float) $payment['amount'];

                foreach ($schedules as $schedule) {
                    if ($left <= 0.00001) {
                        break;
                    }

                    $sid = (int) $schedule['id'];

                    if ($remaining[$sid] <= 0.00001) {
                        continue;
                    }

                    $alloc = min($left, $remaining[$sid]);

                    $insert->execute([
                        'payment_id'  => (int) $payment['id'],
                        'schedule_id' => $sid,
                        'amount'      => round($alloc, 2),
                    ]);

                    $remaining[$sid] = round($remaining[$sid] - $alloc, 2);
                    $left            = round($left - $alloc, 2);

                    if ($remaining[$sid] <= 0.00001) {
                        $paidAt[$sid] = $payment['paid_at'];
                    }
                }
            }

            $paidSums = $this->db->prepare(
                "SELECT schedule_id, SUM(amount) AS paid
                   FROM payment_allocations
                  WHERE schedule_id IN ({$in})
                  GROUP BY schedule_id"
            );
            $paidSums->execute();
            $sums = [];
            foreach ($paidSums->fetchAll() as $row) {
                $sums[(int) $row['schedule_id']] = (float) $row['paid'];
            }

            $update = $this->db->prepare(
                "UPDATE repayment_schedules
                    SET amount_paid = :paid,
                        paid_at = :paid_at,
                        status = :status
                  WHERE id = :id"
            );

            $today = date('Y-m-d');

            foreach ($schedules as $schedule) {
                $sid    = (int) $schedule['id'];
                $paid   = round($sums[$sid] ?? 0, 2);
                $due    = (float) $schedule['amount_due'];
                $status = 'pending';

                if ($paid + 0.00001 >= $due) {
                    $status = 'paid';
                } elseif ($paid > 0) {
                    $status = 'partial';
                } elseif ($schedule['due_date'] < $today) {
                    $status = 'missed';
                }

                $update->execute([
                    'paid'    => $paid,
                    'paid_at' => $status === 'paid' ? ($paidAt[$sid] ?? null) : null,
                    'status'  => $status,
                    'id'      => $sid,
                ]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Next unpaid instalment, if any. */
    public function nextDue(int $loanId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM repayment_schedules
              WHERE loan_id = :id AND status IN ('pending','partial','missed')
              ORDER BY installment_number
              LIMIT 1"
        );
        $stmt->execute(['id' => $loanId]);

        return $stmt->fetch();
    }
}
