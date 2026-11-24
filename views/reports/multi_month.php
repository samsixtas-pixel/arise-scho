<?php
/**
 * views/reports/multi_month.php
 * -------------------------------------------------------
 * Multi-month combined report.
 * Accepts ?months=3|6|9|12 (default 12). Renders:
 *   - Summary row per month (Revenue, COGS, Gross, Expenses, Expired, Net)
 *   - Year/period column totals
 *   - Combined Trial Balance for the whole period
 * Uses the same calculation patterns as balance_sheet_pdf.php.
 * Print via Ctrl+P → PDF.
 * -------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/autoload.php';
require_once __DIR__ . '/../../includes/functions.php';
User::requireAdmin();

$saleModel = new Sale();
$expenseModel = new Expense();

$allowed = [3, 6, 9, 12];
$months  = (int) ($_GET['months'] ?? 12);
if (!in_array($months, $allowed, true)) {
    $months = 12;
}

$endDate   = date('Y-m-t');
$startDate = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime($endDate)));

$db = Database::getConnection();
$has = function (string $table) use ($db): bool {
    try { $db->query("SELECT 1 FROM {$table} LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
};
$hasExpenses  = $has('expenses');
$hasPurchases = $has('purchases');
$hasExpired   = $has('expired_products');
$hasPayments  = $has('payments');

// ---------- Build month buckets ----------
$monthBuckets = [];
$cursor = strtotime($startDate);
$endTs  = strtotime($endDate);
$safe = 0;
while ($cursor <= $endTs && $safe < 24) {
    $safe++;
    $mFrom = date('Y-m-01', $cursor);
    $mTo   = date('Y-m-t', $cursor);
    $monthBuckets[] = [
        'label'    => date('M Y', $cursor),
        'from'     => $mFrom,
        'to'       => $mTo,
        'revenue'  => $saleModel->sumRevenue($mFrom, $mTo),
        'cogs'     => $saleModel->sumCostOfGoods($mFrom, $mTo),
        'gross'    => $saleModel->sumGrossProfit($mFrom, $mTo),
        'expenses' => 0.0,
        'expired'  => 0.0,
        'net'      => 0.0,
    ];
    $cursor = strtotime('+1 month', $cursor);
}

// Fill expenses and expired per month
foreach ($monthBuckets as &$b) {
    if ($hasExpenses) {
        try {
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN :f AND :t");
            $stmt->execute(['f' => $b['from'], 't' => $b['to']]);
            $b['expenses'] = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($hasExpired) {
        try {
            $em = new ExpiredProduct();
            $b['expired'] = $em->sumLoss($b['from'], $b['to']);
        } catch (Throwable $e) {}
    }
    $b['net'] = $b['gross'] - $b['expenses'] - $b['expired'];
}
unset($b);

// Totals
$totRevenue = array_sum(array_column($monthBuckets, 'revenue'));
$totCogs    = array_sum(array_column($monthBuckets, 'cogs'));
$totGross   = array_sum(array_column($monthBuckets, 'gross'));
$totExpenses= array_sum(array_column($monthBuckets, 'expenses'));
$totExpired = array_sum(array_column($monthBuckets, 'expired'));
$totNet     = array_sum(array_column($monthBuckets, 'net'));

// ---------- Trial Balance across whole period ----------
$expenseRows = [];
if ($hasExpenses) {
    $stmt = $db->prepare(
        "SELECT category, COALESCE(SUM(amount),0) AS total
         FROM expenses WHERE expense_date BETWEEN :f AND :t
         GROUP BY category ORDER BY total DESC"
    );
    $stmt->execute(['f' => $startDate, 't' => $endDate]);
    $expenseRows = $stmt->fetchAll();
}

$stmt = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE sale_date BETWEEN :f AND :t");
$stmt->execute(['f' => $startDate, 't' => $endDate]);
$cashFromSales = (float) $stmt->fetchColumn();

$cashFromDebtPay = 0.0;
if ($hasPayments) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date BETWEEN :f AND :t");
    $stmt->execute(['f' => $startDate, 't' => $endDate]);
    $cashFromDebtPay = (float) $stmt->fetchColumn();
}

$cashToSuppliers = 0.0;
if ($hasPurchases) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM purchases WHERE purchase_date BETWEEN :f AND :t");
    $stmt->execute(['f' => $startDate, 't' => $endDate]);
    $cashToSuppliers = (float) $stmt->fetchColumn();
}

$openingCash = 0.0;
try {
    $stmt = $db->prepare(
        "SELECT declared_cash FROM cash_declarations
         WHERE declaration_date < :f AND admin_verified = 1
         ORDER BY declaration_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute(['f' => $startDate]);
    $openingCash = (float) $stmt->fetchColumn();
} catch (Throwable $e) {}

$netCash = ($cashFromSales + $cashFromDebtPay) - ($cashToSuppliers + $totExpenses);
$closingCash = $openingCash + $netCash;

$stockAtCost = (float) $db->query("SELECT COALESCE(SUM(cost_price * current_stock),0) FROM products WHERE status='active'")->fetchColumn();
$debtors     = (float) $db->query("SELECT COALESCE(SUM(remaining_balance),0) FROM sales WHERE payment_status IN ('partial','unpaid')")->fetchColumn();
$payables    = 0.0;
if ($hasPurchases) {
    try {
        $payables = (float) $db->query("SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance > 0")->fetchColumn();
    } catch (Throwable $e) {}
}

// Calculate Balance Sheet Totals (needed for the table rendering)
$totalAssets = $closingCash + $debtors + $stockAtCost;
$totalLiabilities = $payables;

$tbDebits = [
    'Cash on Hand'        => $closingCash,
    'Accounts Receivable' => $debtors,
    'Inventory at Cost'   => $stockAtCost,
    'Cost of Goods Sold'  => $totCogs,
];
foreach ($expenseRows as $er) {
    $tbDebits['Expense: ' . ucfirst($er['category'])] = (float) $er['total'];
}
if ($totExpired > 0) {
    $tbDebits['Expired / Damaged Loss'] = $totExpired;
}
$tbTotalDebits = array_sum($tbDebits);

$tbCredits = [
    'Sales Revenue'    => $totRevenue,
    'Accounts Payable' => $payables,
];
$tbTotalCredits = array_sum($tbCredits);
$tbDifference = $tbTotalDebits - $tbTotalCredits;
$hasError = abs($tbDifference) > 0.009;

if ($hasError) {
    if ($tbDifference > 0) {
        $tbCredits["Owner's Capital (period)"] = $tbDifference;
    } else {
        $tbDebits["Owner's Drawings (period)"] = abs($tbDifference);
    }
    $tbTotalDebits = array_sum($tbDebits);
    $tbTotalCredits = array_sum($tbCredits);
}

// Phase G — If books don't balance, find the month that's off
$errorMonthData = null;
if ($hasError) {
    foreach ($monthBuckets as $b) {
        $mRevDb = (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM sales
             WHERE sale_date BETWEEN '" . $b['from'] . "' AND '" . $b['to'] . "'"
        )->fetchColumn();

        if (abs($mRevDb - $b['revenue']) > 0.01) {
            $errorMonthData = [
                'month'  => $b['label'],
                'from'   => $b['from'],
                'to'     => $b['to'],
                'reason' => 'Revenue mismatch: the monthly summary shows ' . number_format($b['revenue'], 2) . ' but raw sales rows total ' . number_format($mRevDb, 2) . '.',
            ];
            break;
        }
    }
    if ($errorMonthData === null) {
        $errorMonthData = [
            'month'  => 'Whole Period',
            'from'   => $startDate,
            'to'     => $endDate,
            'reason' => 'Monthly totals are internally consistent, but the overall trial balance is off. Likely cause: an opening balance from a prior period, a missing carried-cash entry, or a manual adjustment outside the sales/expenses tables.',
        ];
    }
}

$pageTitle = 'Multi-Month Report';
include __DIR__ . '/../../includes/pdf_header.php';
?>

<style>
  .mm-wrap { max-width: 1000px; margin: 0 auto; padding: 20px; }
  .mm-controls { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
  .mm-controls a {
    padding: 8px 14px; border-radius: 8px; border: 1px solid #d7e0da;
    background: #fff; color: #0A4A38; text-decoration: none; font-weight: 600; font-size: .9rem;
  }
  .mm-controls a.active { background: #0D6348; color: #F7F4E9; border-color: #0D6348; }
  .mm-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: .9rem; }
  .mm-table th, .mm-table td { border: 1px solid #d7e0da; padding: 6px 8px; text-align: right; }
  .mm-table th { background: #0D6348; color: #F7F4E9; font-weight: 700; }
  .mm-table th:first-child, .mm-table td:first-child { text-align: left; }
  .mm-table tr.totals td { background: #F2F5F3; font-weight: 800; }
  .mm-table tr td.neg { color: #B91C1C; }
  .mm-section-title {
    font-size: 1.1rem; font-weight: 800; color: #0A4A38;
    margin: 24px 0 8px; padding-bottom: 6px; border-bottom: 2px solid #0D6348;
  }
  .mm-note { font-size: .8rem; color: #6c757d; margin-top: 4px; }
</style>

<div class="mm-wrap">

  <div class="mm-controls">
    <span style="padding:8px 4px;font-weight:700;color:#0A4A38">Period:</span>
    <?php foreach ($allowed as $m): ?>
      <a href="?months=<?= $m ?>" class="<?= $m === $months ? 'active' : '' ?>">Last <?= $m ?> months</a>
    <?php endforeach; ?>
    <button onclick="window.print()" style="margin-left:auto;padding:8px 14px;border-radius:8px;background:#0D6348;color:#F7F4E9;border:none;font-weight:700;cursor:pointer">
      <i class="bi bi-printer"></i> Print / PDF
    </button>
  </div>

  <h2 style="font-size:1.4rem;font-weight:800;color:#0A4A38;margin:0 0 4px">
    Loan Manager — <?= $months ?>-Month Financial Summary
  </h2>
  <div style="color:#6c757d;margin-bottom:16px">
    <?= date('d M Y', strtotime($startDate)) ?> — <?= date('d M Y', strtotime($endDate)) ?>
  </div>

  <div class="mm-section-title">Monthly Summary</div>
  <table class="mm-table">
    <thead>
      <tr>
        <th>Month</th>
        <th>Revenue</th>
        <th>COGS</th>
        <th>Gross</th>
        <th>Expenses</th>
        <th>Expired</th>
        <th>Net Profit</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($monthBuckets as $b): ?>
        <tr>
          <td><?= e($b['label']) ?></td>
          <td><?= formatMoney($b['revenue']) ?></td>
          <td><?= formatMoney($b['cogs']) ?></td>
          <td><?= formatMoney($b['gross']) ?></td>
          <td><?= formatMoney($b['expenses']) ?></td>
          <td><?= formatMoney($b['expired']) ?></td>
          <td class="<?= $b['net'] < 0 ? 'neg' : '' ?>"><?= formatMoney($b['net']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="totals">
        <td>TOTAL</td>
        <td><?= formatMoney($totRevenue) ?></td>
        <td><?= formatMoney($totCogs) ?></td>
        <td><?= formatMoney($totGross) ?></td>
        <td><?= formatMoney($totExpenses) ?></td>
        <td><?= formatMoney($totExpired) ?></td>
        <td class="<?= $totNet < 0 ? 'neg' : '' ?>"><?= formatMoney($totNet) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="mm-section-title">Balance Sheet (End of Period)</div>
  <table class="mm-table">
    <thead>
      <tr><th>ASSETS</th><th>Amount</th><th>LIABILITIES &amp; EQUITY</th><th>Amount</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Cash on Hand</td><td><?= formatMoney($closingCash) ?></td>
        <td>Accounts Payable (Suppliers)</td><td><?= formatMoney($payables) ?></td>
      </tr>
      <tr>
        <td>Accounts Receivable (Debts)</td><td><?= formatMoney($debtors) ?></td>
        <td>Owner's Equity (Balancing)</td><td><?= formatMoney($totalAssets - $totalLiabilities) ?></td>
      </tr>
      <tr>
        <td>Inventory at Cost</td><td><?= formatMoney($stockAtCost) ?></td>
        <td></td><td></td>
      </tr>
      <tr class="totals">
        <td>TOTAL ASSETS</td><td><?= formatMoney($totalAssets) ?></td>
        <td>TOTAL LIABILITIES &amp; EQUITY</td><td><?= formatMoney($totalAssets) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="mm-section-title">Combined Trial Balance</div>
  <table class="mm-table">
    <thead>
      <tr><th>Account (Debits)</th><th>Amount</th><th>Account (Credits)</th><th>Amount</th></tr>
    </thead>
    <tbody>
      <?php
        $debitRows  = [];
        $creditRows = [];
        foreach ($tbDebits as $k => $v)  $debitRows[]  = [$k, $v];
        foreach ($tbCredits as $k => $v) $creditRows[] = [$k, $v];
        $max = max(count($debitRows), count($creditRows));
        for ($i = 0; $i < $max; $i++):
          $d = $debitRows[$i] ?? ['', ''];
          $c = $creditRows[$i] ?? ['', ''];
      ?>
        <tr>
          <td><?= $d[0] !== '' ? e($d[0]) : '' ?></td>
          <td><?= $d[1] !== '' ? formatMoney($d[1]) : '' ?></td>
          <td><?= $c[0] !== '' ? e($c[0]) : '' ?></td>
          <td><?= $c[1] !== '' ? formatMoney($c[1]) : '' ?></td>
        </tr>
      <?php endfor; ?>
      <tr class="totals">
        <td>TOTAL DEBITS</td>
        <td><?= formatMoney($tbTotalDebits) ?></td>
        <td>TOTAL CREDITS</td>
        <td><?= formatMoney($tbTotalCredits) ?></td>
      </tr>
    </tbody>
  </table>

  <?php if (!$hasError): ?>
    <div class="mm-note" style="color:#0D6348; font-weight:700; margin-top:6px">
      Balanced: ✓ Yes (Perfect Match)
    </div>
  <?php endif; ?>

  <?php if ($hasError && $errorMonthData): ?>
    <?php
      $dFrom = $errorMonthData['from'];
      $dTo   = $errorMonthData['to'];
      $auditSales = [];
      $auditExpenses = [];
      try {
        $stmt = $db->prepare("SELECT id, sale_date, product_id, quantity, total_amount, amount_paid, payment_status, customer_name FROM sales WHERE sale_date BETWEEN :f AND :t ORDER BY sale_date, id");
        $stmt->execute(['f' => $dFrom, 't' => $dTo]);
        $auditSales = $stmt->fetchAll();
      } catch (Throwable $e) {}
      if ($hasExpenses) {
        try {
          $stmt = $db->prepare("SELECT id, expense_date, category, amount, title FROM expenses WHERE expense_date BETWEEN :f AND :t ORDER BY expense_date");
          $stmt->execute(['f' => $dFrom, 't' => $dTo]);
          $auditExpenses = $stmt->fetchAll();
        } catch (Throwable $e) {}
      }
    ?>
    <div style="background:#FFF5F5;border:2px solid #B91C1C;border-radius:12px;padding:20px;margin-top:24px">
      <h3 style="color:#B91C1C;font-size:1.15rem;font-weight:900;margin:0 0 8px">
        <i class="bi bi-exclamation-octagon-fill"></i> Critical Audit Alert — Books Do Not Balance
      </h3>
      <p style="color:#4a5a52;font-size:.9rem;line-height:1.55">
        A discrepancy of <strong><?= formatMoney(abs($tbDifference)) ?></strong> was detected in this period.
        The deep-audit scanner traced the likely origin to <strong><?= e($errorMonthData['month']) ?></strong>.
        <br><br>
        <strong>Reason flagged:</strong> <?= e($errorMonthData['reason']) ?>
        <br><br>
        <em>Below are all sales and expenses recorded during the flagged window. Review these entries with the worker to identify the missing, double-entered, or mis-priced transaction. A temporary balancing entry of <strong><?= formatMoney(abs($tbDifference)) ?></strong> has been applied to Owner's Equity to keep this report readable — <strong>this is a band-aid, not a fix.</strong> Correct the underlying entries, then re-run this report until the audit alert disappears.</em>
      </p>

      <h4 style="margin-top:16px;font-size:1rem;color:#0A4A38">Sales recorded in flagged window (<?= count($auditSales) ?>)</h4>
      <?php if (empty($auditSales)): ?>
        <p style="font-size:.85rem;color:#6c757d">No sales recorded in this window.</p>
      <?php else: ?>
        <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:.85rem;background:#fff">
          <thead>
            <tr style="background:#fcedec;color:#B91C1C">
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">ID</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Date</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Product</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:right">Qty</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:right">Total</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:right">Paid</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Status</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Customer</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditSales as $s): ?>
              <tr>
                <td style="border:1px solid #e2caca;padding:6px">#<?= (int) $s['id'] ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= date('d M Y', strtotime($s['sale_date'])) ?></td>
                <td style="border:1px solid #e2caca;padding:6px">#<?= (int) $s['product_id'] ?></td>
                <td style="border:1px solid #e2caca;padding:6px;text-align:right"><?= (int) $s['quantity'] ?></td>
                <td style="border:1px solid #e2caca;padding:6px;text-align:right"><?= formatMoney($s['total_amount']) ?></td>
                <td style="border:1px solid #e2caca;padding:6px;text-align:right"><?= formatMoney($s['amount_paid']) ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= e($s['payment_status']) ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= e($s['customer_name']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if (!empty($auditExpenses)): ?>
        <h4 style="margin-top:16px;font-size:1rem;color:#0A4A38">Expenses recorded in flagged window (<?= count($auditExpenses) ?>)</h4>
        <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:.85rem;background:#fff">
          <thead>
            <tr style="background:#fcedec;color:#B91C1C">
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">ID</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Date</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Category</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:left">Title</th>
              <th style="border:1px solid #e2caca;padding:6px;text-align:right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditExpenses as $ex): ?>
              <tr>
                <td style="border:1px solid #e2caca;padding:6px">#<?= (int) $ex['id'] ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= date('d M Y', strtotime($ex['expense_date'])) ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= e($ex['category']) ?></td>
                <td style="border:1px solid #e2caca;padding:6px"><?= e($ex['title']) ?></td>
                <td style="border:1px solid #e2caca;padding:6px;text-align:right"><?= formatMoney($ex['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="mm-section-title">Cash Position (whole period)</div>
  <table class="mm-table">
    <tbody>
      <tr><td>Opening cash (verified prior declaration)</td><td><?= formatMoney($openingCash) ?></td></tr>
      <tr><td>+ Cash from sales</td><td><?= formatMoney($cashFromSales) ?></td></tr>
      <tr><td>+ Cash from debt payments</td><td><?= formatMoney($cashFromDebtPay) ?></td></tr>
      <tr><td>− Paid to suppliers (purchases)</td><td><?= formatMoney($cashToSuppliers) ?></td></tr>
      <tr><td>− Expenses paid</td><td><?= formatMoney($totExpenses) ?></td></tr>
      <tr class="totals"><td>Closing cash</td><td><?= formatMoney($closingCash) ?></td></tr>
    </tbody>
  </table>

  <?php if ($months === 12): ?>
    <div class="mm-note" style="margin-top:16px">
      <strong>Annual report:</strong> All 12 months combined. This is your full-year performance summary.
    </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../../includes/pdf_footer.php'; ?>