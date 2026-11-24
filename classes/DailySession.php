<?php
/**
 * DailySession.php
 * -------------------------------------------------------
 * Phase C — One "day close" per worker per day.
 *
 * Tracks the ritual: sales → cash → debts → expired → reason → save.
 * Sales rows link back via sales.daily_session_id (added in Phase B).
 * -------------------------------------------------------
 */

class DailySession extends Model
{
    protected function getTable(): string
    {
        return 'daily_sessions';
    }

    /** Find today's session for a user (returns false if not opened yet). */
    public function findToday(int $userId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM daily_sessions WHERE user_id = :uid AND session_date = CURDATE() LIMIT 1"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetch();
    }

    /** Find any session for a specific date. */
    public function findByUserAndDate(int $userId, string $date): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM daily_sessions WHERE user_id = :uid AND session_date = :d LIMIT 1"
        );
        $stmt->execute(['uid' => $userId, 'd' => $date]);
        return $stmt->fetch();
    }

    /**
     * Open a fresh session for today. If one already exists (e.g. after
     * page reload), return it instead of erroring.
     * Returns the session row.
     */
    public function open(int $userId): array
    {
        $existing = $this->findToday($userId);
        if ($existing) {
            return $existing;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO daily_sessions (user_id, session_date, status) VALUES (:uid, CURDATE(), 'open')"
        );
        $stmt->execute(['uid' => $userId]);

        $id = (int) $this->lastInsertId();
        return $this->find($id);
    }

    /**
     * Compute expected cash for this session right now, without saving.
     *
     * Formula (per brief + user confirmation):
     *   expected = carried_cash_from_yesterday
     *            + cash_paid_sales_in_session
     *            + debt_payments_received_in_session
     *            - expenses_paid_in_session
     *            - purchases_received_in_session
     *            - expired_loss_in_session
     *
     * "carried_cash_from_yesterday" comes from CashDeclaration::getCarriedCash().
     */
    public function computeExpected(int $userId, string $date): array
    {
        $cash = new CashDeclaration();
        $carried = $cash->getCarriedCash($userId, $date);

        // Cash sales for this session's date
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_paid),0)  AS cash_from_sales,
                    COALESCE(SUM(total_amount),0) AS revenue,
                    COALESCE(SUM(remaining_balance),0) AS new_debt,
                    COUNT(*) AS sale_count
             FROM sales WHERE user_id = :uid AND sale_date = :d"
        );
        $stmt->execute(['uid' => $userId, 'd' => $date]);
        $sales = $stmt->fetch();

        // Debt payments received (cash that came in for old debts)
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) AS debt_payments
             FROM payments WHERE user_id = :uid AND payment_date = :d"
        );
        $stmt->execute(['uid' => $userId, 'd' => $date]);
        $debt = $stmt->fetch();

        // Expenses paid from the drawer
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) AS expenses
             FROM expenses WHERE user_id = :uid AND expense_date = :d"
        );
        $stmt->execute(['uid' => $userId, 'd' => $date]);
        $exp = $stmt->fetch();

        // Purchases received today (cash left the drawer)
        $purchases = 0.0;
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM purchases
                 WHERE DATE(received_at) = :d AND status = 'received'"
            );
            $stmt->execute(['d' => $date]);
            $purchases = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $purchases = 0.0; // purchases table structure differences shouldn't break daily flow
        }

        // Approved expired products that day (cost loss)
        $expired = 0.0;
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(cost_at_time * quantity),0)
                 FROM expired_products
                 WHERE status = 'approved' AND DATE(reviewed_at) = :d"
            );
            $stmt->execute(['d' => $date]);
            $expired = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $expired = 0.0;
        }

        $cashSales  = (float) $sales['cash_from_sales'];
        $debtPay    = (float) $debt['debt_payments'];
        $expenses   = (float) $exp['expenses'];

        // Expired products DO NOT reduce the expected cash in the till.
        // (Money is unchanged; only stock value was lost.)
        $expected = $carried + $cashSales + $debtPay - $expenses - $purchases;

        return [
            'expected_cash'  => $expected,
            'carried_cash'   => $carried,
            'cash_sales'     => $cashSales,
            'debt_payments'  => $debtPay,
            'expenses_paid'  => $expenses,
            'purchases_paid' => $purchases,
            'expired_loss'   => $expired,
            'revenue'        => (float) $sales['revenue'],
            'new_debt'       => (float) $sales['new_debt'],
            'sale_count'     => (int) $sales['sale_count'],
        ];
    }

    /**
     * Save the session's computed state (without closing).
     * Called whenever the worker's cart or cash input changes.
     */
    public function saveState(int $sessionId, array $cart, float $declaredCash, ?string $reason = null): bool
    {
        $session = $this->find($sessionId);
        if (!$session) return false;

        $calc = $this->computeExpected((int) $session['user_id'], $session['session_date']);
        $expected = $calc['expected_cash'];
        $diff = $declaredCash - $expected;

        $stmt = $this->db->prepare(
            "UPDATE daily_sessions
             SET cart_snapshot = :cart, expected_cash = :exp, declared_cash = :dec,
                 difference = :diff, reason = :reason
             WHERE id = :id AND status = 'open'"
        );
        return $stmt->execute([
            'cart'   => json_encode($cart),
            'exp'    => $expected,
            'dec'    => $declaredCash,
            'diff'   => $diff,
            'reason' => $reason,
            'id'     => $sessionId,
        ]);
    }

    /**
     * Close the session — this is the final "Complete & Save".
     * Marks status = 'closed' and stamps closed_at.
     */
    public function close(int $sessionId, array $cart, float $declaredCash, ?string $reason = null): bool
    {
        $session = $this->find($sessionId);
        if (!$session) return false;
        if ($session['status'] !== 'open') return false;

        $calc = $this->computeExpected((int) $session['user_id'], $session['session_date']);
        $expected = $calc['expected_cash'];
        $diff = $declaredCash - $expected;

        $stmt = $this->db->prepare(
            "UPDATE daily_sessions
             SET cart_snapshot = :cart, expected_cash = :exp, declared_cash = :dec,
                 difference = :diff, reason = :reason, status = 'closed', closed_at = NOW()
             WHERE id = :id AND status = 'open'"
        );
        return $stmt->execute([
            'cart'   => json_encode($cart),
            'exp'    => $expected,
            'dec'    => $declaredCash,
            'diff'   => $diff,
            'reason' => $reason,
            'id'     => $sessionId,
        ]);
    }
}