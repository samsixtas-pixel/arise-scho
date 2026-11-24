<?php
/**
 * views/sales/daily.php
 * -------------------------------------------------------
 * Daily closing form for legacy cashier workflows.
 *
 * Flow:
 *   1. Worker lands here after login (redirect set in Phase D step 2).
 *   2. Enters each product sold (counted empty bottles from overnight).
 *   3. Fills cash in hand → ticks ✓ → sees expected vs declared.
 *   4. If mismatched, is walked through debts → expired → reason.
 *   5. Complete & Save writes: all sale rows, cash declaration, closes session.
 *
 * If session already closed today: shows summary only.
 * -------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/autoload.php';
require_once __DIR__ . '/../../includes/functions.php';
User::requireLogin();

$userId       = (int) $_SESSION['user_id'];
$sessionModel = new DailySession();
$saleModel    = new Sale();
$cashModel    = new CashDeclaration();
$productModel = new Product();

$errors   = [];
$success  = '';
$today    = date('Y-m-d');

// ---------------- POST: complete & save ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $itemsRaw      = $_POST['items'] ?? '[]';
    $declaredCash  = (float) ($_POST['declared_cash'] ?? 0);
    $reason        = trim($_POST['reason'] ?? '');
    $items         = json_decode($itemsRaw, true);

    if (!is_array($items) || count($items) === 0) {
        $errors[] = 'Cart is empty. Add at least one product sold today.';
    }

    $session = $sessionModel->open($userId);

    if ($session['status'] !== 'open') {
        $errors[] = 'Today is already closed. Contact the boss if you need to edit.';
    }

    if (!$errors) {
        $saleDate = Security::safePastDate($today, 0);

        // 1. Record all sale rows
        $result = $saleModel->addMultipleSales($items, (int) $session['id'], $userId, $saleDate);

        if ($result['recorded'] === 0) {
            $errors[] = 'Nothing was recorded. ' . implode('; ', array_column($result['failed'], 'reason'));
        }

        if ($result['failed']) {
            foreach ($result['failed'] as $f) {
                $errors[] = 'Product #' . $f['id'] . ': ' . $f['reason'];
            }
        }

        if (!$errors) {
            // 2. Compute the correct expected cash using DailySession (includes purchases + expired)
            $calc     = $sessionModel->computeExpected($userId, $today);
            $expected = $calc['expected_cash'];
            $diff     = $declaredCash - $expected;

            // 3. Insert / update cash declaration with the correct expected
            $existing = $cashModel->findByUserAndDate($userId, $today);
            $db       = Database::getConnection();

            if ($existing) {
                $stmt = $db->prepare(
                    "UPDATE cash_declarations
                     SET expected_cash = :exp, cash_in_hand = :cih, declared_cash = :dec,
                         difference = :diff, difference_reason = :reason, admin_verified = 0
                     WHERE id = :id"
                );
                $stmt->execute([
                    'exp'    => $expected,
                    'cih'    => $declaredCash,
                    'dec'    => $declaredCash,
                    'diff'   => $diff,
                    'reason' => $reason,
                    'id'     => $existing['id'],
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO cash_declarations
                        (user_id, declaration_date, opening_cash, expected_cash, expenses_paid,
                         cash_in_hand, mobile_money, declared_cash, difference, difference_reason)
                     VALUES
                        (:uid, :d, 0, :exp, :exppaid,
                         :cih, 0, :dec, :diff, :reason)"
                );
                $stmt->execute([
                    'uid'     => $userId,
                    'd'       => $today,
                    'exp'     => $expected,
                    'exppaid' => $calc['expenses_paid'],
                    'cih'     => $declaredCash,
                    'dec'     => $declaredCash,
                    'diff'    => $diff,
                    'reason'  => $reason,
                ]);
            }

            // 4. Close the session
            $sessionModel->close((int) $session['id'], $items, $declaredCash, $reason);

            AuditLog::record(
                'daily.close', 'daily_sessions', (int) $session['id'],
                'Closed day: ' . $result['recorded'] . ' sales | declared ' . number_format($declaredCash, 2) .
                ' | expected ' . number_format($expected, 2) .
                ' | diff ' . number_format($diff, 2) .
                ($reason !== '' ? ' | reason: ' . $reason : '')
            );

            header('Location: daily.php?success=' . urlencode(
                'Day closed. ' . $result['recorded'] . ' sale(s) recorded. Cash: TZS ' . number_format($declaredCash, 2)
            ));
            exit;
        }
    }
}

if (!empty($_GET['success'])) $success = $_GET['success'];

// ---------------- Session state on load ----------------
$session = $sessionModel->findToday($userId);
$isClosed = $session && $session['status'] !== 'open';

// Products for typeahead
$products = $productModel->getActiveProducts();
$productPayload = array_map(fn($p) => [
    'id'    => (int) $p['id'],
    'name'  => $p['product_name'] . ($p['size'] ? ' ' . $p['size'] : ''),
    'price' => (float) $p['selling_price'],
    'stock' => (int) $p['current_stock'],
], $products);

// If closed, load the snapshot for display
$closedSnapshot = [];
if ($isClosed) {
    $closedSnapshot = json_decode($session['cart_snapshot'] ?? '[]', true) ?: [];
}

// Compute carried cash hint (from CashDeclaration)
$carriedHint = $cashModel->getCarriedCash($userId, $today);

$pageTitle = 'Daily Sales';
include __DIR__ . '/../../includes/header.php';
?>

<style>
  .daily-date { font-size: .95rem; color: #4a5a52; margin-bottom: 4px; }
  .daily-title { font-size: 1.4rem; font-weight: 800; color: #0A4A38; margin-bottom: 12px; }
  .cart-empty { color: #888; text-align: center; padding: 20px 0; font-size: .95rem; }

  .cart-line {
    display: grid;
    grid-template-columns: 1fr auto auto auto;
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #eef2ef;
  }
  .cart-line:last-child { border-bottom: none; }
  .cart-line .cl-name { font-weight: 600; color: #0A4A38; }
  .cart-line .cl-qty  { color: #4a5a52; font-size: .9rem; }
  .cart-line .cl-sub  { font-weight: 700; color: #0D6348; text-align: right; min-width: 110px; }
  .cart-line .cl-del  { background: none; border: 1px solid #dc3545; color: #dc3545; border-radius: 6px;
                        width: 28px; height: 28px; line-height: 1; font-weight: 700; cursor: pointer; }
  .cart-line .cl-del:hover { background: #dc3545; color: #fff; }

  .cart-total-bar {
    background: #0D6348; color: #F7F4E9;
    padding: 12px 16px; border-radius: 0 0 12px 12px;
    display: flex; justify-content: space-between; align-items: center;
    font-weight: 800; font-size: 1.05rem;
  }

  .cash-input-wrap { position: relative; }
  .cash-input-wrap input { padding-right: 52px; }
  .cash-tick {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    width: 40px; height: 40px; border-radius: 10px;
    background: #0D6348; color: #F7F4E9; border: none;
    font-size: 1.2rem; cursor: pointer; display: none;
  }
  .cash-tick:hover { background: #0A4A38; }
  .cash-tick.show { display: flex; align-items: center; justify-content: center; }
  .cash-tick:disabled { background: #999; cursor: not-allowed; }

  .expected-box {
    display: none; margin-top: 12px; padding: 14px 16px;
    border-radius: 12px; background: #fff; border: 2px solid #d7e0da;
  }
  .expected-box.show { display: block; }
  .expected-box .label { font-size: .85rem; color: #4a5a52; text-transform: uppercase; letter-spacing: .04em; }
  .expected-box .value { font-size: 1.6rem; font-weight: 900; margin-top: 2px; }
  .expected-box.balanced { border-color: #0D6348; }
  .expected-box.balanced .value { color: #0D6348; }
  .expected-box.short { border-color: #dc3545; }
  .expected-box.short .value { color: #dc3545; }
  .expected-box.over { border-color: #198754; }
  .expected-box.over .value { color: #198754; }
  .expected-box .status-line { font-weight: 700; font-size: .95rem; margin-top: 4px; }

  .reason-box { display: none; margin-top: 14px; }
  .reason-box.show { display: block; }
  .reason-box label { font-weight: 700; color: #b02a37; }
  .reason-box textarea {
    border: 2px solid #dc3545; background: #fff5f5; border-radius: 10px;
    padding: 10px; width: 100%; resize: vertical; min-height: 80px;
  }
  .reason-box textarea.shake { animation: reasonShake .35s; }
  @keyframes reasonShake {
    0%,100% { transform: translateX(0); }
    20%,60% { transform: translateX(-8px); }
    40%,80% { transform: translateX(8px); }
  }

  .save-btn { display: block; width: 100%; padding: 16px; margin-top: 16px;
              font-size: 1.15rem; font-weight: 800; border-radius: 12px;
              background: #0D6348; color: #F7F4E9; border: none; }
  .save-btn:disabled { background: #b5bdb7; cursor: not-allowed; }

  .closed-banner {
    background: #FFF8E1; border: 2px solid #C98012;
    color: #7a4a04; padding: 14px 16px; border-radius: 12px; margin-bottom: 14px;
  }
</style>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="daily-date"><i class="bi bi-calendar3 me-1"></i><?= date('l, d M Y') ?></div>
<div class="daily-title">
  <i class="bi bi-sunrise-fill me-2"></i>Today's Sales
  <?php if ($isClosed): ?>
    <span class="badge bg-warning text-dark ms-2">Closed</span>
  <?php else: ?>
    <span class="badge bg-success ms-2">Open</span>
  <?php endif; ?>
</div>

<?php if ($isClosed): ?>

  <div class="closed-banner">
    <strong>You already closed today.</strong><br>
    Declared: TZS <?= number_format((float) $session['declared_cash'], 2) ?><br>
    Expected: TZS <?= number_format((float) $session['expected_cash'], 2) ?><br>
    Difference: TZS <?= number_format((float) $session['difference'], 2) ?>
    <?php if (!empty($session['reason'])): ?>
      <br><em>Reason: <?= e($session['reason']) ?></em>
    <?php endif; ?>
  </div>

  <div class="card mb-3">
    <div class="card-header fw-bold">Items recorded</div>
    <div class="card-body p-0">
      <?php if (empty($closedSnapshot)): ?>
        <div class="cart-empty">No items recorded.</div>
      <?php else: ?>
        <?php foreach ($closedSnapshot as $line):
          $pname = '';
          foreach ($productPayload as $pp) { if ($pp['id'] === (int) $line['id']) { $pname = $pp['name']; break; } }
        ?>
          <div class="cart-line">
            <div class="cl-name"><?= e($pname ?: 'Product #' . $line['id']) ?></div>
            <div class="cl-qty">×<?= (int) $line['qty'] ?></div>
            <div class="cl-sub">—</div>
            <div></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <a href="../../my_shift.php" class="btn btn-outline-secondary w-100">View My Profile</a>

<?php else: ?>

  <!-- ============ ADD PRODUCTS ============ -->
  <div class="card mb-3">
    <div class="card-header fw-bold"><i class="bi bi-plus-circle me-1"></i>Add products sold today</div>
    <div class="card-body">

      <div class="row g-2 mb-3 position-relative">
        <div class="col-12 col-md-7">
          <label class="form-label small fw-bold">Product</label>
          <input type="text" id="productSearch" class="form-control form-control-lg"
                 placeholder="Type product name…" autocomplete="off" autofocus>
          <div id="productResults" class="typeahead-results" style="display:none"></div>
          <small id="productPicked" class="text-muted">No product selected.</small>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small fw-bold">Qty</label>
          <input type="number" min="1" id="quantityInput" class="form-control form-control-lg" value="1">
        </div>
        <div class="col-6 col-md-3 d-flex align-items-end">
          <button type="button" id="addToCartBtn" class="btn btn-success btn-lg w-100">
            <i class="bi bi-plus-lg"></i> Add
          </button>
        </div>
      </div>

      <div id="cartList" class="border rounded-3 overflow-hidden"></div>
      <div class="cart-total-bar">
        <span>TOTAL</span>
        <span id="cartTotal">TZS 0.00</span>
      </div>

    </div>
  </div>

  <!-- ============ CASH DECLARATION ============ -->
  <div class="card mb-3">
    <div class="card-header fw-bold"><i class="bi bi-cash-coin me-1"></i>Cash Declaration</div>
    <div class="card-body">

      <?php if ($carriedHint > 0): ?>
        <div class="alert alert-info py-2 px-3 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Carried cash from yesterday: <strong>TZS <?= number_format($carriedHint, 2) ?></strong>
          (boss has not yet confirmed receipt)
        </div>
      <?php endif; ?>

      <label class="form-label fw-bold">Cash in hand (TZS)</label>
      <div class="cash-input-wrap">
        <input type="number" step="0.01" min="0" id="cashInput"
               class="form-control form-control-lg" placeholder="0.00">
        <button type="button" class="cash-tick" id="cashTick" aria-label="Confirm cash amount">
          <i class="bi bi-check-lg"></i>
        </button>
      </div>

      <div class="expected-box" id="expectedBox">
        <div class="label">Expected</div>
        <div class="value" id="expectedValue">TZS 0.00</div>
        <div class="status-line" id="statusLine">—</div>
      </div>

      <div class="mt-3">
        <a href="<?= BASE_URL ?>views/stock/stock_in.php?from_shift=1" class="btn btn-outline-primary w-100">
          <i class="bi bi-cart-plus-fill me-1"></i> Order Stock (deducts from cash)
        </a>
      </div>

    </div>
  </div>

  <!-- ============ REASON (revealed when mismatch and no debts/expired) ============ -->
  <div class="reason-box" id="reasonBox">
    <label for="reasonText"><i class="bi bi-exclamation-triangle me-1"></i>Explain the missing amount</label>
    <textarea id="reasonText" placeholder="e.g. paid for taxi, gave change, miscounted bottles"></textarea>
  </div>

  <!-- ============ COMPLETE & SAVE ============ -->
  <button type="button" id="saveBtn" class="save-btn" disabled>
    <i class="bi bi-check-circle-fill me-1"></i> Complete &amp; Save
  </button>

  <!-- Hidden form submitted by JS after all guards pass -->
  <form method="POST" id="completeForm" style="display:none">
    <input type="hidden" name="action" value="complete">
    <input type="hidden" name="items" id="formItems">
    <input type="hidden" name="declared_cash" id="formDeclared">
    <input type="hidden" name="reason" id="formReason">
  </form>

<?php endif; ?>

<style>
  /* Typeahead dropdown — inline because .typeahead-results isn't in style.css */
  .typeahead-results {
    position: absolute;
    z-index: 1050;
    left: 12px;
    right: 12px;
    top: calc(100% - 8px);
    max-height: 280px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #d7e0da;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
  }
  .typeahead-results .ta-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f3f1;
    display: flex;
    justify-content: space-between;
    gap: 8px;
  }
  .typeahead-results .ta-item:last-child { border-bottom: none; }
  .typeahead-results .ta-item:hover { background: #f2f5f3; }
  .typeahead-results .ta-item .ta-name { font-weight: 600; color: #0A4A38; }
  .typeahead-results .ta-item .ta-price { color: #C98012; font-weight: 700; font-size: .9rem; }
  .typeahead-results .ta-item .ta-stock { color: #6c757d; font-size: .8rem; }
  .typeahead-results .ta-empty {
    padding: 12px 14px;
    color: #888;
    font-size: .9rem;
    text-align: center;
  }
</style>

<script>
(function () {
  'use strict';

  window.DAILY_DATA = {
    products:    <?= json_encode($productPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    today:       <?= json_encode($today) ?>,
    userId:      <?= (int) $userId ?>,
    carriedHint: <?= json_encode($carriedHint) ?>,
    isClosed:    <?= $isClosed ? 'true' : 'false' ?>
  };

  var D = window.DAILY_DATA || {};
  var PRODUCTS = D.products || [];
  var CART_KEY = 'loan_manager_daily_cart_' + (D.userId || '0') + '_' + (D.today || '');
  var VOICE_KEY = 'loan_manager_voice_on';

  // ============ DOM refs ============
  var searchInput = document.getElementById('productSearch');
  var resultsBox  = document.getElementById('productResults');
  var pickedEl    = document.getElementById('productPicked');
  var qtyInput    = document.getElementById('quantityInput');
  var addBtn      = document.getElementById('addToCartBtn');
  var cartList    = document.getElementById('cartList');
  var cartTotal   = document.getElementById('cartTotal');
  var cashInput   = document.getElementById('cashInput');
  var cashTick    = document.getElementById('cashTick');
  var expectedBox = document.getElementById('expectedBox');
  var expectedVal = document.getElementById('expectedValue');
  var statusLine  = document.getElementById('statusLine');
  var reasonBox   = document.getElementById('reasonBox');
  var reasonText  = document.getElementById('reasonText');
  var saveBtn     = document.getElementById('saveBtn');
  var completeForm= document.getElementById('completeForm');
  var formItems   = document.getElementById('formItems');
  var formDeclared= document.getElementById('formDeclared');
  var formReason  = document.getElementById('formReason');

  // Daily page has no cart UI when the day is already closed.
  if (!searchInput || !addBtn) return;

  // ============ State ============
  var cart = [];
  var pickedProduct = null;
  var cashConfirmed = false;
  var confirmedDeclared = 0;
  var currentExpected = 0;
  var mismatchResolved = false;   // true when debts/expired already walked through
  var reasonRequired = false;

  // ============ Helpers ============
  function fmt(n) {
    return 'TZS ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function saveCart() { try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} }
  function loadCart() {
    try {
      var raw = localStorage.getItem(CART_KEY);
      if (!raw) return [];
      var arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr : [];
    } catch (e) { return []; }
  }
  function clearCartStorage() { try { localStorage.removeItem(CART_KEY); } catch (e) {} }

  // ============ Typeahead ============
  function showResults(matches) {
    if (!matches.length) {
      resultsBox.innerHTML = '<div class="ta-empty">No products match</div>';
      resultsBox.style.display = 'block';
      return;
    }
    resultsBox.innerHTML = matches.slice(0, 8).map(function (p) {
      return '<div class="ta-item" data-id="' + p.id + '">'
           +   '<div><span class="ta-name">' + p.name + '</span> '
           +     '<span class="ta-stock">stock ' + p.stock + '</span></div>'
           +   '<div class="ta-price">' + fmt(p.price) + '</div>'
           + '</div>';
    }).join('');
    resultsBox.style.display = 'block';
  }

  searchInput.addEventListener('input', function () {
    var q = searchInput.value.trim().toLowerCase();
    pickedProduct = null;
    pickedEl.textContent = 'No product selected.';
    pickedEl.className = 'text-muted';
    if (!q) { resultsBox.style.display = 'none'; return; }
    var matches = PRODUCTS.filter(function (p) {
      return p.name.toLowerCase().indexOf(q) !== -1;
    });
    showResults(matches);
  });

  resultsBox.addEventListener('click', function (ev) {
    var item = ev.target.closest('.ta-item');
    if (!item) return;
    var id = parseInt(item.dataset.id, 10);
    var p = PRODUCTS.find(function (x) { return x.id === id; });
    if (!p) return;
    pickedProduct = p;
    searchInput.value = p.name;
    pickedEl.textContent = 'Selected: ' + p.name + ' — ' + fmt(p.price);
    pickedEl.className = 'text-success small';
    resultsBox.style.display = 'none';
    qtyInput.focus();
  });

  document.addEventListener('click', function (ev) {
    if (!searchInput.contains(ev.target) && !resultsBox.contains(ev.target)) {
      resultsBox.style.display = 'none';
    }
  });

  // ============ Cart ============
  function renderCart() {
    if (!cart.length) {
      cartList.innerHTML = '<div class="cart-empty">Cart is empty. Add a product above.</div>';
    } else {
      cartList.innerHTML = cart.map(function (line) {
        var p = PRODUCTS.find(function (x) { return x.id === line.id; });
        var name = p ? p.name : ('Product #' + line.id);
        var price = p ? p.price : 0;
        var subtotal = price * line.qty;
        return '<div class="cart-line">'
             +   '<div class="cl-name">' + name + '</div>'
             +   '<div class="cl-qty">×' + line.qty + '</div>'
             +   '<div class="cl-sub">' + fmt(subtotal) + '</div>'
             +   '<button type="button" class="cl-del" data-remove="' + line.id + '">×</button>'
             + '</div>';
      }).join('');
    }
    var total = cart.reduce(function (sum, line) {
      var p = PRODUCTS.find(function (x) { return x.id === line.id; });
      return sum + (p ? p.price * line.qty : 0);
    }, 0);
    cartTotal.textContent = fmt(total);
    refreshSaveButton();
  }

  cartList.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-remove]');
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-remove'), 10);
    cart = cart.filter(function (l) { return l.id !== id; });
    saveCart();
    renderCart();
    // Cash tick becomes invalid again because total changed
    if (cashConfirmed) {
      cashConfirmed = false;
      expectedBox.classList.remove('show');
      cashTick.classList.add('show');
      refreshSaveButton();
    }
  });

  addBtn.addEventListener('click', function () {
    if (!pickedProduct) {
      Swal.fire({ icon: 'warning', title: 'Pick a product', text: 'Type a product name and pick a suggestion first.' });
      searchInput.focus();
      return;
    }
    var qty = parseInt(qtyInput.value, 10);
    if (!qty || qty <= 0) {
      Swal.fire({ icon: 'warning', title: 'Invalid quantity' });
      return;
    }
    if (qty > pickedProduct.stock) {
      Swal.fire({ icon: 'warning', title: 'Not enough stock', text: pickedProduct.name + ' — only ' + pickedProduct.stock + ' left' });
      return;
    }
    var existing = cart.find(function (l) { return l.id === pickedProduct.id; });
    if (existing) {
      var newQty = existing.qty + qty;
      if (newQty > pickedProduct.stock) {
        Swal.fire({ icon: 'warning', title: 'Not enough stock', text: pickedProduct.name + ' — only ' + pickedProduct.stock + ' left' });
        return;
      }
      existing.qty = newQty;
    } else {
      cart.push({ id: pickedProduct.id, qty: qty });
    }
    saveCart();
    renderCart();

    // Reset inputs for next entry
    pickedProduct = null;
    searchInput.value = '';
    qtyInput.value = 1;
    pickedEl.textContent = 'No product selected.';
    pickedEl.className = 'text-muted';
    searchInput.focus();

    // Any cash confirmation is stale now
    if (cashConfirmed) {
      cashConfirmed = false;
      expectedBox.classList.remove('show');
      cashTick.classList.add('show');
      refreshSaveButton();
    }
  });

  // ============ Cash tick ============
  cashInput.addEventListener('input', function () {
    var v = parseFloat(cashInput.value || '0');
    if (isFinite(v) && cashInput.value !== '') {
      cashTick.classList.add('show');
    } else {
      cashTick.classList.remove('show');
    }
    // Any change to cash invalidates a previous confirmation
    if (cashConfirmed) {
      cashConfirmed = false;
      expectedBox.classList.remove('show');
      refreshSaveButton();
    }
  });

  function getCartTotal() {
    return cart.reduce(function (sum, line) {
      var p = PRODUCTS.find(function (x) { return x.id === line.id; });
      return sum + (p ? p.price * line.qty : 0);
    }, 0);
  }

  cashTick.addEventListener('click', function () {
    var declared = parseFloat(cashInput.value || '0');
    if (!isFinite(declared) || declared < 0) {
      Swal.fire({ icon: 'warning', title: 'Enter a valid amount' });
      return;
    }
    Swal.fire({
      icon: 'question',
      title: 'Confirm cash in hand',
      html: 'Are you sure this is the amount of cash in hand?<br><strong style="font-size:1.4rem">' + fmt(declared) + '</strong>',
      showCancelButton: true,
      confirmButtonText: 'Yes, it is correct',
      cancelButtonText: 'No, let me recount',
      confirmButtonColor: '#0D6348',
      cancelButtonColor: '#6c757d'
    }).then(function (res) {
      if (!res.isConfirmed) {
        cashInput.focus();
        cashInput.select();
        return;
      }
      cashConfirmed = true;
      confirmedDeclared = declared;
      showExpected(declared);
    });
  });

  // ============ Expected cash reveal ============
  function showExpected(declared) {
    // Expected = today's cart total + carried hint
    // (server will recompute on save with purchases+expired included;
    //  this client preview is a good-faith estimate using available data)
    var expected = getCartTotal() + (parseFloat(D.carriedHint) || 0);
    currentExpected = expected;
    var diff = declared - expected;

    expectedBox.classList.remove('balanced', 'short', 'over');
    expectedVal.textContent = fmt(expected);

    if (Math.abs(diff) < 0.01) {
      expectedBox.classList.add('balanced', 'show');
      statusLine.textContent = 'Balanced ✓';
      statusLine.style.color = '#0D6348';
      reasonRequired = false;
      reasonBox.classList.remove('show');
    } else if (diff < 0) {
      expectedBox.classList.add('short', 'show');
      statusLine.textContent = 'Missing ' + fmt(Math.abs(diff));
      statusLine.style.color = '#dc3545';
      handleMismatch();
    } else {
      expectedBox.classList.add('over', 'show');
      statusLine.textContent = 'Exceeded by ' + fmt(diff);
      statusLine.style.color = '#198754';
      handleMismatch();
    }
    refreshSaveButton();
  }

  // ============ Mismatch resolution walk ============
  function handleMismatch() {
    // Ask in order: debts? → expired? → reason
    Swal.fire({
      icon: 'question',
      title: 'Any debts during this session?',
      text: 'Were any sales on credit (customer pays later)?',
      showCancelButton: true,
      confirmButtonText: 'Yes, there are debts',
      cancelButtonText: 'No',
      confirmButtonColor: '#198754',
      cancelButtonColor: '#dc3545',
      allowOutsideClick: false
    }).then(function (res) {
      if (res.isConfirmed) {
        window.location = '<?= BASE_URL ?>views/debts/add.php?from_shift=1';
        return;
      }
      askExpired();
    });
  }

  function askExpired() {
    Swal.fire({
      icon: 'question',
      title: 'Any expired products?',
      text: 'Were any products found expired or damaged during the sale?',
      showCancelButton: true,
      confirmButtonText: 'Yes, there are expired',
      cancelButtonText: 'No',
      confirmButtonColor: '#198754',
      cancelButtonColor: '#dc3545',
      allowOutsideClick: false
    }).then(function (res) {
      if (res.isConfirmed) {
        window.location = '<?= BASE_URL ?>views/stock/expired.php?from_shift=1';
        return;
      }
      askPurchases();
    });
  }

  function askPurchases() {
    Swal.fire({
      icon: 'question',
      title: 'Did you order new stock today?',
      text: 'If you purchased new products from a supplier, enter the order now so the cost deducts from your cash.',
      showCancelButton: true,
      confirmButtonText: 'Yes, order stock',
      cancelButtonText: 'No',
      confirmButtonColor: '#198754',
      cancelButtonColor: '#dc3545',
      allowOutsideClick: false
    }).then(function (res) {
      if (res.isConfirmed) {
        window.location = '<?= BASE_URL ?>views/stock/stock_in.php?from_shift=1';
        return;
      }
      // No debts, no expired, no purchases → require reason
      reasonRequired = true;
      reasonBox.classList.add('show');
      reasonText.focus();
      refreshSaveButton();
    });
  }

  // ============ Save guard ============
  function refreshSaveButton() {
    if (!saveBtn) return;
    var ok = true;
    if (!cart.length) ok = false;
    if (!cashConfirmed) ok = false;
    if (cashConfirmed) {
      var diff = confirmedDeclared - currentExpected;
      if (Math.abs(diff) >= 0.01) {
        // Mismatch must be resolved either by reason text or by
        // navigating away (which the user chose not to do)
        if (!reasonRequired) ok = false;
        if (reasonRequired && reasonText.value.trim().length < 3) ok = false;
      }
    }
    saveBtn.disabled = !ok;
  }

  reasonText.addEventListener('input', refreshSaveButton);

  saveBtn.addEventListener('click', function () {
    var reason = reasonText.value.trim();
    if (reasonRequired && reason.length < 3) {
      // Shake the reason box
      reasonBox.querySelector('textarea').classList.remove('shake');
      void reasonBox.querySelector('textarea').offsetWidth; // reflow
      reasonBox.querySelector('textarea').classList.add('shake');
      reasonBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      Swal.fire({ icon: 'warning', title: 'Reason required', text: 'Please explain the missing amount before saving.' });
      return;
    }

    var payload = cart.map(function (l) { return { id: l.id, qty: l.qty }; });
    formItems.value = JSON.stringify(payload);
    formDeclared.value = String(confirmedDeclared);
    formReason.value = reason;
    Swal.fire({
      icon: 'question',
      title: 'Complete & save?',
      text: 'This will record all sales and close today.',
      showCancelButton: true,
      confirmButtonText: 'Yes, save',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#0D6348'
    }).then(function (res) {
      if (res.isConfirmed) {
        // Clear cart storage so next open starts fresh
        clearCartStorage();
        completeForm.submit();
      }
    });
  });

  // ============ Init ============
  cart = loadCart();
  renderCart();

  // If cart has items and cash already entered, show tick
  if (cashInput.value && parseFloat(cashInput.value) > 0) {
    cashTick.classList.add('show');
  }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>