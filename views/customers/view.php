<?php
/**
 * views/customers/view.php — customer profile, digital file and history.
 *
 * Tabs: Overview · Loan details · Schedule · Payments · Loan history
 *       Documents · Activity
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$customerModel = new Customer();
$loanModel     = new Loan();
$documentModel = new CustomerDocument();
$id            = (int) ($_GET['id'] ?? 0);

$customer = $customerModel->findDetailed($id);

if (!$customer) {
    flash('error', t('Customer not found.'));
    redirect(BASE_URL . 'views/customers/index.php');
}

$tabs = ['overview', 'loans', 'schedule', 'payments', 'history', 'documents', 'activity'];
$tab  = (string) ($_GET['tab'] ?? 'overview');
$tab  = in_array($tab, $tabs, true) ? $tab : 'overview';
$tabUrl = static function (string $target) use ($id): string {
    return BASE_URL . 'views/customers/view.php?id=' . $id . '&tab=' . $target;
};

if (isPost()) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'status') {
            $customerModel->setStatus($id, (string) ($_POST['status'] ?? 'active'));
            flash('success', t('Customer status updated.'));
            $tab = 'overview';
        } elseif ($action === 'archive') {
            User::requireAdmin();
            $customerModel->softDelete($id);
            flash('success', t('Customer archived.'));
            redirect(BASE_URL . 'views/customers/index.php');
        } elseif ($action === 'doc_upload') {
            $category = (string) ($_POST['document_category'] ?? 'supporting');

            if (empty($_FILES['document_file']['name'])) {
                throw new ValidationException([t('Choose an image to upload.')]);
            }

            $issued = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['issue_date'] ?? ''))
                ? (string) $_POST['issue_date'] : null;
            $expiry = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['expiry_date'] ?? ''))
                ? (string) $_POST['expiry_date'] : null;

            if ($issued && $expiry && strtotime($expiry) < strtotime($issued)) {
                throw new ValidationException([t('The expiry date cannot be before the issue date.')]);
            }

            $documentModel->store($id, $_FILES['document_file'], $category, $issued, $expiry);
            flash('success', t('Document added to the customer file.'));
            $tab = 'documents';
        } elseif ($action === 'doc_replace') {
            $documentId = (int) ($_POST['document_id'] ?? 0);
            $document   = $documentModel->findDetailed($documentId);

            if (!$document || (int) $document['customer_id'] !== $id) {
                throw new RuntimeException(t('Document not found.'));
            }

            if (empty($_FILES['replace_file']['name'])) {
                throw new ValidationException([t('Choose a replacement image.')]);
            }

            $documentModel->replace($documentId, $_FILES['replace_file'], (string) ($_POST['replace_reason'] ?? ''));
            flash('success', t('Document replaced. The previous version is kept in the history.'));
            $tab = 'documents';
        } elseif ($action === 'doc_delete') {
            if (!User::isAdmin()) {
                throw new RuntimeException(t('Administrator access is required to delete a document.'));
            }

            $documentId = (int) ($_POST['document_id'] ?? 0);
            $document   = $documentModel->findDetailed($documentId);

            if (!$document || (int) $document['customer_id'] !== $id) {
                throw new RuntimeException(t('Document not found.'));
            }

            $documentModel->softDelete($documentId);
            flash('success', t('Document removed from the customer file.'));
            $tab = 'documents';
        }
    } catch (ValidationException $e) {
        flash('error', implode(' ', $e->errors()));
        $tab = $action === 'doc_upload' || $action === 'doc_replace' ? 'documents' : $tab;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        $tab = $action === 'doc_upload' || $action === 'doc_replace' ? 'documents' : $tab;
    }

    redirect(BASE_URL . 'views/customers/view.php?id=' . $id . '&tab=' . $tab);
}

$loans    = $loanModel->forCustomer($id);
$db       = Database::getConnection();

/* ------------------------------------------------------------------
   Payments — every confirmed receipt for this customer
   ------------------------------------------------------------------ */
$stmt = $db->prepare(
    "SELECT v.*, p.received_by, p.method, p.notes, u.full_name AS received_by_name
       FROM v_payments_with_balance v
       JOIN payments p ON p.id = v.payment_id
  LEFT JOIN users u ON u.id = p.received_by
      WHERE v.customer_id = :id AND v.status = 'confirmed'
      ORDER BY v.payment_date DESC, v.payment_id DESC
      LIMIT 500"
);
$stmt->execute(['id' => $id]);
$payments = $stmt->fetchAll();

/* ------------------------------------------------------------------
   Repayment schedule — all instalments across this customer's loans
   ------------------------------------------------------------------ */
$stmt = $db->prepare(
    "SELECT rs.*, l.reference AS loan_reference, l.loan_date
       FROM repayment_schedules rs
       JOIN loans l ON l.id = rs.loan_id
      WHERE l.customer_id = :id AND l.deleted_at IS NULL
      ORDER BY rs.due_date, l.id, rs.installment_number"
);
$stmt->execute(['id' => $id]);
$schedule = $stmt->fetchAll();

/* ------------------------------------------------------------------
   Customer Digital File
   ------------------------------------------------------------------ */
$documents  = $documentModel->forCustomer($id);
$docSummary = $documentModel->summary($id);

$documentsByCategory = [];
foreach ($documents as $document) {
    $documentsByCategory[$document['document_category']][] = $document;
}

$versionsByDocument = [];
if ($documents) {
    $ids = array_map('intval', array_column($documents, 'id'));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT v.*, u.full_name AS replaced_by_name
           FROM document_versions v
      LEFT JOIN users u ON u.id = v.replaced_by
          WHERE v.document_id IN ($in)
       ORDER BY v.version_number"
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $version) {
        $versionsByDocument[(int) $version['document_id']][] = $version;
    }
}

/* ------------------------------------------------------------------
   Activity — audit trail + collection follow-ups
   ------------------------------------------------------------------ */
$stmt = $db->prepare(
    "SELECT a.*
       FROM audit_log a
      WHERE (a.entity_type = 'customers' AND a.entity_id = :c1)
         OR (a.entity_type = 'loans' AND a.entity_id IN
                (SELECT id FROM loans WHERE customer_id = :c2))
         OR (a.entity_type = 'customer_documents' AND a.entity_id IN
                (SELECT id FROM customer_documents WHERE customer_id = :c3))
   ORDER BY a.id DESC
      LIMIT 80"
);
$stmt->execute(['c1' => $id, 'c2' => $id, 'c3' => $id]);
$auditRows = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT ca.*, u.full_name AS officer_name, l.reference AS loan_reference
       FROM collection_activities ca
       JOIN loans l ON l.id = ca.loan_id
  LEFT JOIN users u ON u.id = ca.officer_id
      WHERE l.customer_id = :id AND l.deleted_at IS NULL
   ORDER BY ca.id DESC
      LIMIT 60"
);
$stmt->execute(['id' => $id]);
$followUps = $stmt->fetchAll();

$activity = [];

$auditLabels = [
    'customer.create'          => t('Customer registered'),
    'customer.update'          => t('Customer details updated'),
    'customer.status'          => t('Customer status changed'),
    'customer.delete'          => t('Customer archived'),
    'loan.create'              => t('Loan issued'),
    'loan.update'              => t('Loan updated'),
    'loan.delete'              => t('Loan deleted'),
    'loan.write_off'           => t('Loan written off'),
    'loan.followup'            => t('Follow-up logged'),
    'payment.create'           => t('Payment recorded'),
    'payment.reverse'          => t('Payment reversed'),
    'customer_document.upload' => t('Document uploaded'),
    'customer_document.download' => t('Document downloaded'),
    'customer_document.replace'=> t('Document replaced'),
    'customer_document.delete' => t('Document deleted'),
    'camera_session.create'    => t('Phone camera paired'),
    'camera_session.upload'    => t('Photo received from phone'),
    'camera_session.complete'  => t('Photo accepted'),
    'camera_session.cancel'    => t('Capture cancelled'),
    'camera_session.renew'     => t('Capture code renewed'),
    'customer_document.ocr'    => t('OCR / MRZ read'),
    'customer_dossier.generate'=> t('Dossier generated'),
];

foreach ($auditRows as $row) {
    $action = (string) $row['action'];
    $icon   = 'activity';
    $tone   = '';

    if (str_starts_with($action, 'customer_document.')) {
        $icon = 'file-earmark-text';
        $tone = 'gold';
    } elseif (str_starts_with($action, 'payment.')) {
        $icon = 'cash-stack';
    } elseif (str_starts_with($action, 'loan.')) {
        $icon = 'cash-coin';
    } elseif (str_starts_with($action, 'camera_session.')) {
        $icon = 'camera';
        $tone = 'gold';
    } elseif (str_starts_with($action, 'customer.delete') || str_contains($action, 'delete')) {
        $icon = 'archive';
        $tone = 'red';
    } elseif (str_starts_with($action, 'customer.')) {
        $icon = 'person';
    }

    $activity[] = [
        'when'    => (string) $row['created_at'],
        'icon'    => $icon,
        'tone'    => $tone,
        'title'   => $auditLabels[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action)),
        'details' => (string) ($row['details'] ?? ''),
        'meta'    => (string) ($row['username'] ?? ''),
        'sort'    => strtotime((string) $row['created_at']) ?: 0,
    ];
}

$followLabels = [
    'call'    => t('Phone call'),
    'sms'     => t('SMS reminder'),
    'visit'   => t('Field visit'),
    'note'    => t('Follow-up note'),
    'promise' => t('Payment promise'),
];

foreach ($followUps as $row) {
    $details = trim((string) ($row['notes'] ?? ''));

    if (!empty($row['promise_date'])) {
        $details .= ($details !== '' ? ' · ' : '') . t('Promised :date', ['date' => formatDate($row['promise_date'])]);
    }
    if (!empty($row['promise_amount'])) {
        $details .= ' · ' . money($row['promise_amount']);
    }

    $activity[] = [
        'when'    => (string) $row['created_at'],
        'icon'    => $row['activity_type'] === 'promise' ? 'calendar-check' : 'telephone',
        'tone'    => $row['activity_type'] === 'promise' ? 'gold' : '',
        'title'   => ($followLabels[$row['activity_type']] ?? t('Follow-up'))
                   . ' · ' . (string) $row['loan_reference'],
        'details' => $details,
        'meta'    => (string) ($row['officer_name'] ?? ''),
        'sort'    => strtotime((string) $row['created_at']) ?: 0,
    ];
}

usort($activity, static fn(array $a, array $b): int => $b['sort'] <=> $a['sort']);
$activity = array_slice($activity, 0, 100);

/* ------------------------------------------------------------------
   Totals and counters
   ------------------------------------------------------------------ */
$openLoans   = [];
$closedLoans = [];
$openBalance = 0.0;
$overdue     = 0;

foreach ($loans as $loan) {
    $status = (string) $loan['loan_status'];

    if (in_array($status, ['PAID', 'WRITTEN OFF'], true)) {
        $closedLoans[] = $loan;
    } else {
        $openLoans[] = $loan;
        $openBalance += (float) $loan['remaining_balance'];
    }

    if ($status === 'OVERDUE') {
        $overdue++;
    }
}

$scheduleCounts = ['paid' => 0, 'pending' => 0, 'partial' => 0, 'missed' => 0, 'overdue' => 0];
$nextDue        = null;

foreach ($schedule as $row) {
    $status = (string) $row['status'];

    if ($status === 'paid') {
        $scheduleCounts['paid']++;
        continue;
    }

    if (isset($scheduleCounts[$status])) {
        $scheduleCounts[$status]++;
    }

    if (strtotime((string) $row['due_date']) < strtotime(date('Y-m-d'))) {
        $scheduleCounts['overdue']++;
    }

    if ($nextDue === null) {
        $nextDue = $row;
    }
}

$paidTotal   = 0.0;
foreach ($payments as $payment) {
    $paidTotal += (float) $payment['amount_paid'];
}

$documentCount = count($documents);
$documentAlerts = 0;
foreach ($docSummary as $summaryRow) {
    $documentAlerts += $summaryRow['expired'] + $summaryRow['expiring'];
}

$missing = [];
if (!$customer['nida'])   $missing[] = t('NIDA number');
if (!$customer['phone'])  $missing[] = t('phone number');
if (!$customer['photo_path']) $missing[] = t('passport photo');
if (trim($customer['middle_name']) === '' || trim($customer['last_name']) === '') $missing[] = t('full three-part name');

$photo       = PhotoStorage::url($customer['photo_path']);
$genderLabel = !empty($customer['gender']) ? t(ucfirst((string) $customer['gender'])) : '—';
$pageTitle   = t('Customer profile');
$pageScripts = [
    'assets/vendor/qrcode.js',
    'assets/js/image-editor.js',
    'assets/js/document-capture.js',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= e($customer['full_name']) ?></h4>
    <p>
      <span class="ref"><?= e($customer['reference']) ?></span>
      · <?= t('Registered :date', ['date' => formatDate($customer['registered_at'])]) ?>
      · <?= statusBadge($customer['status']) ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/payments/add.php?customer_id=<?= $id ?>">
      <i class="bi bi-cash me-1"></i><?= t('Record payment') ?>
    </a>
    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php?customer_id=<?= $id ?>">
      <i class="bi bi-cash-coin me-1"></i><?= t('New loan') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/edit.php?id=<?= $id ?>">
      <i class="bi bi-pencil me-1"></i><?= t('Edit') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/id_card.php?id=<?= $id ?>">
      <i class="bi bi-person-vcard me-1"></i><?= t('ID card') ?>
    </a>
    <?php if ($documentCount > 0): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/dossier.php?id=<?= $id ?>">
        <i class="bi bi-file-earmark-pdf me-1"></i><?= t('Dossier') ?>
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($missing): ?>
  <div class="alert alert-success d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-circle mt-1"></i>
    <div class="small">
      <strong><?= t('This record is incomplete.') ?></strong>
      <?= t('Still missing: :items.', ['items' => implode(', ', $missing)]) ?>
      <a href="<?= BASE_URL ?>views/customers/edit.php?id=<?= $id ?>" class="ms-1"><?= t('Complete it now') ?></a>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">

  <!-- ═══ Summary column ═══ -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body text-center">
        <?php if ($photo): ?>
          <img class="avatar-photo lg mb-2" src="<?= e($photo) ?>" alt="<?= e($customer['full_name']) ?>">
        <?php else: ?>
          <span class="avatar-fallback lg mb-2"><?= e(initials($customer['full_name'])) ?></span>
        <?php endif; ?>

        <h6 class="mb-1 fw-bold"><?= e($customer['full_name']) ?></h6>
        <div class="small text-muted mb-2">
          <?= e($customer['phone'] ? formatPhone($customer['phone']) : t('No phone on file')) ?>
        </div>

        <div class="d-flex justify-content-center gap-2 flex-wrap">
          <?php if ($overdue > 0): ?>
            <span class="pill pill-red"><i class="bi bi-exclamation-triangle-fill"></i><?= $overdue ?> <?= t('overdue') ?></span>
          <?php endif; ?>
          <span class="pill <?= $customer['data_status'] === 'complete' ? 'pill-green' : 'pill-gold' ?>">
            <i class="bi bi-<?= $customer['data_status'] === 'complete' ? 'check2-circle' : 'exclamation-circle' ?>"></i>
            <?= e(t(ucfirst($customer['data_status']))) ?>
          </span>
          <?php if ($documentCount > 0): ?>
            <span class="pill pill-grey"><i class="bi bi-folder2-open"></i><?= $documentCount ?> <?= t('documents') ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-body pt-0">
        <dl class="detail-list">
          <div><dt><?= t('Gender') ?></dt><dd><?= e($genderLabel) ?></dd></div>
          <div><dt><?= t('NIDA') ?></dt><dd><?= e($customer['nida'] ?: '—') ?></dd></div>
          <div><dt><?= t('Alternate phone') ?></dt><dd><?= e($customer['phone_alt'] ? formatPhone($customer['phone_alt']) : '—') ?></dd></div>
          <div><dt><?= t('Emergency contact') ?></dt><dd><?= e($customer['emergency_contact'] ?: '—') ?></dd></div>
          <div><dt><?= t('Emergency phone') ?></dt><dd><?= e($customer['emergency_phone'] ? formatPhone($customer['emergency_phone']) : '—') ?></dd></div>
          <div><dt><?= t('Officer') ?></dt><dd><?= e($customer['officer_name'] ?? '—') ?></dd></div>
          <div><dt><?= t('First loan') ?></dt><dd><?= e(formatDate($customer['first_loan_date'])) ?></dd></div>
        </dl>

        <?php if (!empty($customer['notes'])): ?>
          <div class="divide-row"></div>
          <div class="small text-muted"><?= nl2br(e($customer['notes'])) ?></div>
        <?php endif; ?>
      </div>

      <div class="card-body border-top d-flex gap-2 flex-wrap">
        <?php if ($customer['status'] !== 'blacklisted'): ?>
          <form method="POST" data-confirm="<?= t('Blacklisting blocks new loans for this customer.') ?>">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="status" value="blacklisted">
            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-slash-circle me-1"></i><?= t('Blacklist') ?></button>
          </form>
        <?php else: ?>
          <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="status" value="active">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check2 me-1"></i><?= t('Reactivate') ?></button>
          </form>
        <?php endif; ?>

        <?php if (User::isAdmin()): ?>
          <form method="POST" data-confirm="<?= t('Archive this customer? Their history is kept but they disappear from the register.') ?>">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="archive">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-archive me-1"></i><?= t('Archive') ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title-sm"><i class="bi bi-speedometer2 me-1"></i><?= t('At a glance') ?></span></div>
      <div class="card-body">
        <dl class="detail-list">
          <div><dt><?= t('Open loans') ?></dt><dd><?= count($openLoans) ?></dd></div>
          <div><dt><?= t('Closed loans') ?></dt><dd><?= count($closedLoans) ?></dd></div>
          <div><dt><?= t('Outstanding') ?></dt><dd><?= e(money($openBalance)) ?></dd></div>
          <div><dt><?= t('Repaid') ?></dt><dd class="text-success"><?= e(money($customer['total_repaid'])) ?></dd></div>
          <div><dt><?= t('Next instalment') ?></dt>
            <dd><?= $nextDue ? e(formatDate($nextDue['due_date'])) : '—' ?></dd></div>
          <div><dt><?= t('Documents') ?></dt><dd><?= $documentCount ?></dd></div>
        </dl>
      </div>
    </div>
  </div>

  <!-- ═══ Tabbed column ═══ -->
  <div class="col-lg-8">
    <div class="kpi-grid profile-kpis mb-3">
      <div class="kpi">
        <div class="k-icon"><i class="bi bi-journal-text"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Loans') ?></div>
          <div class="k-value"><?= (int) $customer['loan_count'] ?></div>
        </div>
      </div>
      <div class="kpi gold">
        <div class="k-icon"><i class="bi bi-hourglass"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Outstanding') ?></div>
          <div class="k-value"><?= e(money($openBalance)) ?></div>
        </div>
      </div>
      <div class="kpi ink">
        <div class="k-icon"><i class="bi bi-arrow-down-circle"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Repaid') ?></div>
          <div class="k-value"><?= e(money($customer['total_repaid'])) ?></div>
        </div>
      </div>
      <div class="kpi <?= $overdue > 0 ? 'red' : '' ?>">
        <div class="k-icon"><i class="bi bi-calendar-check"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Overdue instalments') ?></div>
          <div class="k-value"><?= (int) $scheduleCounts['overdue'] ?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header pb-0">
        <ul class="nav nav-tabs profile-tabs" role="tablist">
          <?php
          $tabLabels = [
              'overview'  => ['bi-person-lines-fill', t('Overview')],
              'loans'     => ['bi-cash-coin', t('Loan details')],
              'schedule'  => ['bi-calendar3', t('Schedule')],
              'payments'  => ['bi-receipt', t('Payments')],
              'history'   => ['bi-clock-history', t('Loan history')],
              'documents' => ['bi-folder2-open', t('Documents')],
              'activity'  => ['bi-activity', t('Activity')],
          ];
          foreach ($tabLabels as $key => [$icon, $label]):
          ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?= $tab === $key ? 'active' : '' ?>" id="tab-<?= $key ?>-btn"
                      data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button"
                      role="tab" aria-controls="tab-<?= $key ?>" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>">
                <i class="bi bi-<?= $icon ?>"></i><?= e($label) ?>
                <?php if ($key === 'documents' && $documentCount > 0): ?>
                  <span class="badge bg-light text-dark border"><?= $documentCount ?></span>
                <?php elseif ($key === 'payments' && count($payments) > 0): ?>
                  <span class="badge bg-light text-dark border"><?= count($payments) ?></span>
                <?php endif; ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="card-body">
        <div class="tab-content">

          <!-- ═══════════ OVERVIEW ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'overview' ? 'show active' : '' ?>" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-btn">
            <div class="row g-3">
              <div class="col-md-6">
                <h6 class="card-title-sm mb-2"><i class="bi bi-person me-1"></i><?= t('Identity') ?></h6>
                <dl class="detail-list">
                  <div><dt><?= t('Full name') ?></dt><dd><?= e($customer['full_name']) ?></dd></div>
                  <div><dt><?= t('Gender') ?></dt><dd><?= e($genderLabel) ?></dd></div>
                  <div><dt><?= t('NIDA') ?></dt><dd><?= e($customer['nida'] ?: '—') ?></dd></div>
                  <div><dt><?= t('Reference') ?></dt><dd><span class="ref"><?= e($customer['reference']) ?></span></dd></div>
                  <div><dt><?= t('Data status') ?></dt><dd><?= statusBadge($customer['data_status']) ?></dd></div>
                </dl>
              </div>
              <div class="col-md-6">
                <h6 class="card-title-sm mb-2"><i class="bi bi-telephone me-1"></i><?= t('Contact') ?></h6>
                <dl class="detail-list">
                  <div><dt><?= t('Phone') ?></dt><dd><?= e($customer['phone'] ? formatPhone($customer['phone']) : '—') ?></dd></div>
                  <div><dt><?= t('Alternate phone') ?></dt><dd><?= e($customer['phone_alt'] ? formatPhone($customer['phone_alt']) : '—') ?></dd></div>
                  <div><dt><?= t('Emergency contact') ?></dt><dd><?= e($customer['emergency_contact'] ?: '—') ?></dd></div>
                  <div><dt><?= t('Emergency phone') ?></dt><dd><?= e($customer['emergency_phone'] ? formatPhone($customer['emergency_phone']) : '—') ?></dd></div>
                  <div><dt><?= t('Officer') ?></dt><dd><?= e($customer['officer_name'] ?? '—') ?></dd></div>
                </dl>
              </div>
            </div>

            <div class="divide-row"></div>

            <div class="row g-3">
              <div class="col-md-6">
                <h6 class="card-title-sm mb-2"><i class="bi bi-cash-stack me-1"></i><?= t('Loan snapshot') ?></h6>
                <dl class="detail-list">
                  <div><dt><?= t('Open loans') ?></dt><dd><?= count($openLoans) ?></dd></div>
                  <div><dt><?= t('Outstanding') ?></dt><dd><?= e(money($openBalance)) ?></dd></div>
                  <div><dt><?= t('Repaid') ?></dt><dd><?= e(money($customer['total_repaid'])) ?></dd></div>
                  <div><dt><?= t('Next instalment') ?></dt>
                    <dd><?= $nextDue ? e(formatDate($nextDue['due_date'])) : '—' ?></dd></div>
                </dl>
              </div>
              <div class="col-md-6">
                <h6 class="card-title-sm mb-2"><i class="bi bi-folder2-open me-1"></i><?= t('Digital file') ?></h6>
                <dl class="detail-list">
                  <div><dt><?= t('Documents') ?></dt><dd><?= $documentCount ?></dd></div>
                  <div><dt><?= t('Categories used') ?></dt><dd><?= count($docSummary) ?></dd></div>
                  <div><dt><?= t('Expiry warnings') ?></dt>
                    <dd class="<?= $documentAlerts > 0 ? 'text-danger fw-semibold' : '' ?>"><?= $documentAlerts ?></dd></div>
                </dl>
                <a class="btn btn-outline-primary btn-sm mt-2" href="<?= $tabUrl('documents') ?>">
                  <i class="bi bi-folder2-open me-1"></i><?= t('Open customer file') ?>
                </a>
              </div>
            </div>

            <?php if (!empty($customer['notes'])): ?>
              <div class="divide-row"></div>
              <h6 class="card-title-sm mb-2"><i class="bi bi-sticky me-1"></i><?= t('Notes') ?></h6>
              <div class="small text-muted"><?= nl2br(e($customer['notes'])) ?></div>
            <?php endif; ?>
          </div>

          <!-- ═══════════ LOAN DETAILS ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'loans' ? 'show active' : '' ?>" id="tab-loans" role="tabpanel" aria-labelledby="tab-loans-btn">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h6 class="card-title-sm mb-0"><i class="bi bi-cash-coin me-1"></i><?= t('Open loans in detail') ?></h6>
              <a class="card-link" href="<?= BASE_URL ?>views/loans/add.php?customer_id=<?= $id ?>"><?= t('New loan') ?></a>
            </div>

            <?php if (!$openLoans): ?>
              <div class="empty-state py-4">
                <i class="bi bi-cash-coin"></i>
                <strong><?= t('No open loans') ?></strong>
                <p><?= t('Issue a weekly loan, or review closed loans in the loan history tab.') ?></p>
                <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php?customer_id=<?= $id ?>"><?= t('New loan') ?></a>
              </div>
            <?php else: ?>
              <?php foreach ($openLoans as $loan): ?>
                <?php
                $progress = percent($loan['amount_paid'], $loan['total_amount_payable']);
                $loanUrl  = BASE_URL . 'views/loans/view.php?id=' . (int) $loan['loan_id'];
                ?>
                <div class="border rounded-3 p-3 mb-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                      <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="ref"><?= e($loan['loan_reference']) ?></span>
                        <?= loanBadge($loan['loan_status']) ?>
                        <?php if ((int) $loan['days_overdue'] > 0): ?>
                          <span class="badge bg-danger"><?= (int) $loan['days_overdue'] ?> <?= t('days overdue') ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="small text-muted mt-1">
                        <?= t('Issued :date', ['date' => formatDate($loan['loan_date'])]) ?>
                        · <?= e(money($loan['weekly_amount'])) ?> × <?= (int) $loan['number_of_weeks'] ?> <?= t('weeks') ?>
                      </div>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                      <?php if ($loan['loan_status'] !== 'PAID'): ?>
                        <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>views/payments/add.php?loan_id=<?= (int) $loan['loan_id'] ?>">
                          <i class="bi bi-cash me-1"></i><?= t('Payment') ?>
                        </a>
                      <?php endif; ?>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= $loanUrl ?>"><i class="bi bi-eye me-1"></i><?= t('View') ?></a>
                    </div>
                  </div>

                  <div class="progress progress-thin mb-2">
                    <div class="progress-bar bg-success" style="width: <?= number_format($progress, 1, '.', '') ?>%"></div>
                  </div>

                  <div class="row g-2 small">
                    <div class="col-6 col-md-3"><span class="text-muted d-block"><?= t('Payable') ?></span><strong><?= e(money($loan['total_amount_payable'])) ?></strong></div>
                    <div class="col-6 col-md-3"><span class="text-muted d-block"><?= t('Paid') ?></span><strong class="text-success"><?= e(money($loan['amount_paid'])) ?></strong></div>
                    <div class="col-6 col-md-3"><span class="text-muted d-block"><?= t('Balance') ?></span><strong><?= e(money($loan['remaining_balance'])) ?></strong></div>
                    <div class="col-6 col-md-3"><span class="text-muted d-block"><?= t('Next due') ?></span><strong><?= e(formatDate($loan['next_payment_date'])) ?></strong></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- ═══════════ SCHEDULE ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'schedule' ? 'show active' : '' ?>" id="tab-schedule" role="tabpanel" aria-labelledby="tab-schedule-btn">
            <div class="d-flex flex-wrap gap-2 mb-3">
              <span class="pill pill-green"><i class="bi bi-check2"></i><?= $scheduleCounts['paid'] ?> <?= t('paid') ?></span>
              <span class="pill pill-grey"><i class="bi bi-clock"></i><?= $scheduleCounts['pending'] ?> <?= t('pending') ?></span>
              <span class="pill pill-gold"><i class="bi bi-hourglass-split"></i><?= $scheduleCounts['partial'] ?> <?= t('partial') ?></span>
              <span class="pill pill-red"><i class="bi bi-exclamation-triangle"></i><?= $scheduleCounts['overdue'] + $scheduleCounts['missed'] ?> <?= t('missed') ?></span>
            </div>

            <?php if (!$schedule): ?>
              <div class="empty-state py-4">
                <i class="bi bi-calendar3"></i>
                <strong><?= t('No schedule yet') ?></strong>
                <p><?= t('Weekly instalments appear here once a loan is issued.') ?></p>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table table-sm table-hover align-middle">
                  <thead>
                    <tr>
                      <th><?= t('Loan') ?></th>
                      <th>#</th>
                      <th><?= t('Due date') ?></th>
                      <th class="num"><?= t('Due') ?></th>
                      <th class="num"><?= t('Paid') ?></th>
                      <th><?= t('Status') ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($schedule as $row): ?>
                      <?php
                      $isLate = $row['status'] !== 'paid' && strtotime((string) $row['due_date']) < strtotime(date('Y-m-d'));
                      ?>
                      <tr class="<?= $isLate ? 'table-danger' : '' ?>">
                        <td><span class="ref"><?= e($row['loan_reference']) ?></span></td>
                        <td><?= (int) $row['installment_number'] ?></td>
                        <td><?= e(formatDate($row['due_date'])) ?></td>
                        <td class="num"><?= e(num($row['amount_due'])) ?></td>
                        <td class="num"><?= e(num($row['amount_paid'])) ?></td>
                        <td><?= statusBadge($row['status']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- ═══════════ PAYMENTS ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'payments' ? 'show active' : '' ?>" id="tab-payments" role="tabpanel" aria-labelledby="tab-payments-btn">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <div class="small text-muted">
                <?= t(':n payments · :total received', [
                    'n'     => count($payments),
                    'total' => money($paidTotal),
                ]) ?>
              </div>
              <a class="card-link" href="<?= BASE_URL ?>views/payments/index.php?q=<?= urlencode($customer['reference']) ?>"><?= t('Open payment register') ?></a>
            </div>

            <?php if (!$payments): ?>
              <div class="empty-state py-4">
                <i class="bi bi-receipt"></i>
                <strong><?= t('No payments yet') ?></strong>
                <p><?= t('Receipts will appear here.') ?></p>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th><?= t('Receipt') ?></th>
                      <th class="d-none d-sm-table-cell"><?= t('Loan') ?></th>
                      <th><?= t('Date') ?></th>
                      <th class="num"><?= t('Amount') ?></th>
                      <th class="d-none d-md-table-cell"><?= t('Received by') ?></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($payments as $payment): ?>
                      <tr>
                        <td><span class="ref"><?= e($payment['payment_reference']) ?></span></td>
                        <td class="d-none d-sm-table-cell"><span class="ref"><?= e($payment['loan_reference']) ?></span></td>
                        <td><?= e(formatDate($payment['payment_date'])) ?></td>
                        <td class="num fw-semibold"><?= e(money($payment['amount_paid'])) ?></td>
                        <td class="d-none d-md-table-cell small"><?= e($payment['received_by_name'] ?? '—') ?></td>
                        <td class="text-end">
                          <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('Receipt') ?>"
                             href="<?= BASE_URL ?>views/payments/receipt.php?id=<?= (int) $payment['payment_id'] ?>">
                            <i class="bi bi-printer"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- ═══════════ LOAN HISTORY ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'history' ? 'show active' : '' ?>" id="tab-history" role="tabpanel" aria-labelledby="tab-history-btn">
            <?php if (!$loans): ?>
              <div class="empty-state py-4">
                <i class="bi bi-clock-history"></i>
                <strong><?= t('No loans yet') ?></strong>
                <p><?= t('Issue the first weekly loan to this customer.') ?></p>
                <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>views/loans/add.php?customer_id=<?= $id ?>"><?= t('New loan') ?></a>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th><?= t('Loan') ?></th>
                      <th class="d-none d-sm-table-cell"><?= t('Issued') ?></th>
                      <th class="num"><?= t('Payable') ?></th>
                      <th class="num"><?= t('Paid') ?></th>
                      <th class="num"><?= t('Balance') ?></th>
                      <th><?= t('Status') ?></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($loans as $loan): ?>
                      <tr>
                        <td>
                          <span class="ref"><?= e($loan['loan_reference']) ?></span>
                          <?php if ((int) $loan['is_written_off'] === 1): ?>
                            <div class="small text-danger"><?= t('Written off') ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="d-none d-sm-table-cell"><?= e(formatDate($loan['loan_date'])) ?></td>
                        <td class="num"><?= e(num($loan['total_amount_payable'])) ?></td>
                        <td class="num text-success"><?= e(num($loan['amount_paid'])) ?></td>
                        <td class="num fw-semibold"><?= e(num($loan['remaining_balance'])) ?></td>
                        <td><?= loanBadge($loan['loan_status']) ?></td>
                        <td class="text-end">
                          <a class="btn btn-sm btn-outline-secondary btn-icon" title="<?= t('View') ?>"
                             href="<?= BASE_URL ?>views/loans/view.php?id=<?= (int) $loan['loan_id'] ?>">
                            <i class="bi bi-eye"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- ═══════════ DOCUMENTS ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'documents' ? 'show active' : '' ?>" id="tab-documents" role="tabpanel" aria-labelledby="tab-documents-btn">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
              <div>
                <div class="small fw-semibold"><?= t('Customer Digital File') ?></div>
                <div class="small text-muted">
                  <?= t(':n documents · private storage', ['n' => $documentCount]) ?>
                  <?php if ($documentAlerts > 0): ?>
                    · <span class="text-danger fw-semibold"><?= $documentAlerts ?> <?= t('expiry warnings') ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($documentCount > 0): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/dossier.php?id=<?= $id ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i><?= t('Print dossier') ?>
                </a>
              <?php endif; ?>
            </div>

            <details class="doc-upload-panel mb-3" <?= $documentCount === 0 ? 'open' : '' ?>>
              <summary class="fw-semibold small mb-2" style="cursor:pointer">
                <i class="bi bi-cloud-arrow-up me-1"></i><?= t('Add a document or capture a passport') ?>
              </summary>

              <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="doc_upload">

                <div class="col-md-4">
                  <label class="form-label" for="document_category"><?= t('Document category') ?></label>
                  <select class="form-select form-select-sm" id="document_category" name="document_category" required>
                    <?php foreach (CustomerDocument::CATEGORIES as $key => $label): ?>
                      <option value="<?= e($key) ?>" <?= $key === 'passport' ? 'selected' : '' ?>><?= e(t($label)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="document_file"><?= t('Image file') ?></label>
                  <input class="form-control form-control-sm" type="file" id="document_file" name="document_file"
                         accept="image/jpeg,image/png" required>
                  <div class="form-text"><?= t('JPEG or PNG, up to 10 MB. Text must stay readable.') ?></div>
                </div>

                <div class="col-6 col-md-2">
                  <label class="form-label" for="issue_date"><?= t('Issued') ?></label>
                  <input class="form-control form-control-sm" type="date" id="issue_date" name="issue_date">
                </div>

                <div class="col-6 col-md-2">
                  <label class="form-label" for="expiry_date"><?= t('Expires') ?></label>
                  <input class="form-control form-control-sm" type="date" id="expiry_date" name="expiry_date">
                </div>

                <div class="col-12">
                  <button class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-upload me-1"></i><?= t('Add to customer file') ?>
                  </button>
                  <span class="form-text ms-2"><?= t('The image is stored privately; only signed-in staff can open it.') ?></span>
                </div>
              </form>
            </details>

            <div class="doc-upload-panel mb-3" id="docCapturePanel"
                 data-customer="<?= $id ?>"
                 data-msg-camera-unavailable="<?= e(t('This browser cannot open the camera. Use Upload image instead.')) ?>"
                 data-msg-https="<?= e(t('Camera access requires HTTPS.')) ?>"
                 data-msg-permission="<?= e(t('Camera access was blocked. Allow camera permission in the browser, or upload an image instead.')) ?>"
                 data-msg-saved="<?= e(t('Document added to the customer file.')) ?>"
                 data-msg-failed="<?= e(t('The image could not be processed. Please try again.')) ?>"
                 data-msg-uploading="<?= e(t('Saving the document…')) ?>"
                 data-msg-waiting="<?= e(t('Waiting for the phone…')) ?>"
                 data-msg-received="<?= e(t('Image received from the phone.')) ?>"
                 data-msg-expired="<?= e(t('The capture code expired. Create a new one.')) ?>"
                 data-msg-cancelled="<?= e(t('Capture cancelled.')) ?>">
              <div class="d-flex flex-wrap align-items-end gap-2">
                <div>
                  <label class="form-label" for="docCaptureCategory"><?= t('Document category') ?></label>
                  <select class="form-select form-select-sm" id="docCaptureCategory">
                    <?php foreach (CustomerDocument::CATEGORIES as $key => $label): ?>
                      <option value="<?= e($key) ?>" <?= $key === 'passport' ? 'selected' : '' ?>>
                        <?= e(t($label)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="d-flex flex-wrap gap-2">
                  <button type="button" class="btn btn-primary btn-sm" id="docCaptureThis">
                    <i class="bi bi-camera me-1"></i><?= t('Capture on this device') ?>
                  </button>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="docCapturePhone">
                    <i class="bi bi-phone me-1"></i><?= t('Capture using a phone') ?>
                  </button>
                  <label class="btn btn-outline-secondary btn-sm mb-0">
                    <i class="bi bi-image me-1"></i><?= t('Upload image') ?>
                    <input type="file" id="docCaptureFile" accept="image/*" hidden>
                  </label>
                </div>
              </div>

              <div class="capture-live d-none mt-2" id="docCaptureLive">
                <video id="docCaptureVideo" playsinline muted autoplay></video>
                <div class="capture-guide document" aria-hidden="true"></div>
                <div class="capture-live-hint"><?= t('All edges and text must stay inside the frame') ?></div>
                <div class="camera-controls mt-2">
                  <button type="button" class="btn btn-primary btn-sm" id="docCaptureShoot">
                    <i class="bi bi-camera me-1"></i><?= t('Capture') ?>
                  </button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="docCaptureSwitch">
                    <i class="bi bi-arrow-repeat me-1"></i><?= t('Switch') ?>
                  </button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="docCaptureStop">
                    <?= t('Cancel') ?>
                  </button>
                </div>
              </div>

              <div class="doc-capture-preview d-none mt-2" id="docCapturePreviewWrap">
                <img id="docCapturePreview" alt="<?= t('Document image') ?>">
              </div>

              <div class="form-text doc-capture-status mt-2" id="docCaptureStatus"></div>
            </div>

            <?php if (!$documents): ?>
              <div class="empty-state py-4">
                <i class="bi bi-folder2-open"></i>
                <strong><?= t('No documents yet') ?></strong>
                <p><?= t('Add a passport, NIDA card, loan form or supporting evidence.') ?></p>
              </div>
            <?php else: ?>
              <?php foreach (CustomerDocument::CATEGORIES as $categoryKey => $categoryLabel): ?>
                <?php if (empty($documentsByCategory[$categoryKey])) continue; ?>
                <div class="doc-category-head">
                  <h6><i class="bi bi-<?= CustomerDocument::icon($categoryKey) ?> me-1"></i><?= e(t($categoryLabel)) ?></h6>
                  <span class="count"><?= count($documentsByCategory[$categoryKey]) ?></span>
                </div>

                <div class="doc-grid mb-2">
                  <?php foreach ($documentsByCategory[$categoryKey] as $document): ?>
                    <?php
                    $expired  = !empty($document['expiry_date']) && strtotime((string) $document['expiry_date']) < strtotime(date('Y-m-d'));
                    $expiring = !empty($document['expiry_date']) && !$expired
                        && strtotime((string) $document['expiry_date']) <= strtotime('+60 days');
                    $docVersions = $versionsByDocument[(int) $document['id']] ?? [];
                    ?>
                    <div class="doc-card">
                      <a class="doc-thumb" href="<?= e(CustomerDocument::url((int) $document['id'], 'full')) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(CustomerDocument::url((int) $document['id'], 'thumb')) ?>"
                             alt="<?= e(t($categoryLabel)) ?>" loading="lazy">
                      </a>
                      <div class="doc-body">
                        <p class="doc-title">
                          <?= e(t($categoryLabel)) ?>
                          <?php if ((int) $document['is_primary'] === 1): ?>
                            <span class="badge bg-success ms-1"><?= t('Primary') ?></span>
                          <?php endif; ?>
                        </p>
                        <div class="doc-meta">
                          <?= t('v:n', ['n' => (int) $document['version_number']]) ?>
                          · <?= e(formatDate($document['uploaded_at'])) ?>
                          <?php if (!empty($document['uploaded_by_name'])): ?>
                            · <?= e($document['uploaded_by_name']) ?>
                          <?php endif; ?>
                        </div>
                        <div class="doc-meta">
                          <?php if (!empty($document['expiry_date'])): ?>
                            <span class="<?= $expired ? 'doc-expired' : ($expiring ? 'doc-expiring' : '') ?>">
                              <i class="bi bi-calendar-event me-1"></i><?= t('Expires :date', ['date' => formatDate($document['expiry_date'])]) ?>
                            </span>
                          <?php else: ?>
                            <?= t('No expiry date') ?>
                          <?php endif; ?>
                          <?php if ((int) ($document['file_size_bytes'] ?? 0) > 0): ?>
                            · <?= e(number_format(((int) $document['file_size_bytes']) / 1024, 0)) ?> KB
                          <?php endif; ?>
                        </div>

                        <div class="doc-actions">
                          <a class="btn btn-outline-secondary" href="<?= e(CustomerDocument::url((int) $document['id'], 'full')) ?>"
                             target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i><?= t('View') ?></a>
                          <a class="btn btn-outline-secondary" href="<?= e(CustomerDocument::downloadUrl((int) $document['id'])) ?>">
                            <i class="bi bi-download me-1"></i><?= t('Download') ?>
                          </a>
                          <?php if (in_array($document['document_category'], ['passport', 'nida', 'other_id'], true)): ?>
                            <a class="btn btn-outline-primary"
                               href="<?= BASE_URL ?>views/customers/passport_ocr.php?id=<?= $id ?>&doc_id=<?= (int) $document['id'] ?>">
                              <i class="bi bi-upc-scan me-1"></i><?= t('Read MRZ') ?>
                            </a>
                          <?php endif; ?>
                        </div>

                        <details class="mt-1">
                          <summary class="small text-muted" style="cursor:pointer"><?= t('Replace / history') ?></summary>

                          <form method="POST" enctype="multipart/form-data" class="mt-2">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="action" value="doc_replace">
                            <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                            <input class="form-control form-control-sm mb-1" type="file" name="replace_file"
                                   accept="image/jpeg,image/png" required>
                            <input class="form-control form-control-sm mb-1" type="text" name="replace_reason"
                                   maxlength="255" placeholder="<?= t('Reason (optional)') ?>">
                            <button class="btn btn-outline-primary btn-sm w-100" type="submit">
                              <i class="bi bi-arrow-repeat me-1"></i><?= t('Replace image') ?>
                            </button>
                          </form>

                          <?php if ($docVersions): ?>
                            <ul class="doc-version-list">
                              <?php foreach (array_reverse($docVersions) as $version): ?>
                                <li>
                                  <a href="<?= e(CustomerDocument::versionUrl((int) $version['id'])) ?>">
                                    <?= t('v:n', ['n' => (int) $version['version_number']]) ?>
                                  </a>
                                  · <?= e(formatDate($version['created_at'])) ?>
                                  <?php if (!empty($version['replaced_by_name'])): ?>
                                    · <?= e($version['replaced_by_name']) ?>
                                  <?php endif; ?>
                                  <?php if (!empty($version['replacement_reason'])): ?>
                                    · <?= e($version['replacement_reason']) ?>
                                  <?php endif; ?>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          <?php else: ?>
                            <div class="doc-meta mt-1"><?= t('No earlier versions.') ?></div>
                          <?php endif; ?>

                          <?php if (User::isAdmin()): ?>
                            <form method="POST" class="mt-2"
                                  data-confirm="<?= t('Delete this document from the customer file? The file is retained for the audit period but hidden from staff.') ?>">
                              <?= Security::csrfField() ?>
                              <input type="hidden" name="action" value="doc_delete">
                              <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                              <button class="btn btn-outline-danger btn-sm w-100" type="submit">
                                <i class="bi bi-trash me-1"></i><?= t('Delete document') ?>
                              </button>
                            </form>
                          <?php endif; ?>
                        </details>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- ═══════════ ACTIVITY ═══════════ -->
          <div class="tab-pane fade <?= $tab === 'activity' ? 'show active' : '' ?>" id="tab-activity" role="tabpanel" aria-labelledby="tab-activity-btn">
            <?php if (!$activity): ?>
              <div class="empty-state py-4">
                <i class="bi bi-activity"></i>
                <strong><?= t('No activity recorded') ?></strong>
                <p><?= t('Customer changes, payments, follow-ups and document actions appear here.') ?></p>
              </div>
            <?php else: ?>
              <ul class="timeline">
                <?php foreach ($activity as $item): ?>
                  <li class="timeline-item">
                    <span class="timeline-dot <?= e($item['tone']) ?>"><i class="bi bi-<?= e($item['icon']) ?>"></i></span>
                    <p class="timeline-title"><?= e(t($item['title'])) ?></p>
                    <?php if ($item['details'] !== ''): ?>
                      <div class="small text-muted"><?= nl2br(e($item['details'])) ?></div>
                    <?php endif; ?>
                    <div class="timeline-meta">
                      <span><i class="bi bi-clock me-1"></i><?= e(formatDateTime($item['when'])) ?></span>
                      <?php if ($item['meta'] !== ''): ?>
                        <span>· <i class="bi bi-person me-1"></i><?= e($item['meta']) ?></span>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- Document image editor -->
<div class="modal fade" id="docEditorModal" tabindex="-1" aria-labelledby="docEditorTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="docEditorTitle"><i class="bi bi-crop me-1"></i><?= t('Adjust photograph') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <div class="editor-stage document" id="docEditorStage">
          <canvas id="docEditorCanvas"></canvas>
          <div class="editor-frame" aria-hidden="true"></div>
        </div>
        <div class="editor-tools">
          <button type="button" class="btn btn-outline-secondary btn-sm btn-icon" data-doc-rotate="-90"
                  title="<?= t('Rotate left') ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
          <div class="editor-zoom">
            <i class="bi bi-zoom-out"></i>
            <input type="range" id="docEditorZoom" min="1" max="3" step="0.01" value="1" aria-label="<?= t('Zoom') ?>">
            <i class="bi bi-zoom-in"></i>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm btn-icon" data-doc-rotate="90"
                  title="<?= t('Rotate right') ?>"><i class="bi bi-arrow-clockwise"></i></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="docEditorReset">
            <i class="bi bi-arrow-repeat me-1"></i><?= t('Reset') ?>
          </button>
        </div>
        <div class="form-text mt-2"><?= t('All edges and text must stay inside the frame') ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
        <button type="button" class="btn btn-primary" id="docEditorUse">
          <i class="bi bi-check2 me-1"></i><?= t('Use this photo') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Document QR pairing -->
<div class="modal fade" id="docPairModal" tabindex="-1" aria-labelledby="docPairTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="docPairTitle"><i class="bi bi-phone me-1"></i><?= t('Use a phone as the camera') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button>
      </div>
      <div class="modal-body">
        <div class="pair-grid">
          <div class="pair-qr" id="docPairQr">
            <div class="pair-qr-fallback"><i class="bi bi-qr-code"></i><span><?= t('Preparing QR code…') ?></span></div>
          </div>
          <div class="pair-copy">
            <h6><?= t('Scan this code with the phone camera') ?></h6>
            <p class="small text-muted mb-2">
              <?= t('No app or file transfer is needed. The photo appears here automatically after the phone confirms it.') ?>
            </p>
            <div class="pair-status waiting" id="docPairStatus"><?= t('Waiting for the phone…') ?></div>
            <div class="pair-timer" id="docPairTimer">0:00</div>
          </div>
        </div>
        <div class="alert alert-success small mt-3 mb-0 py-2">
          <i class="bi bi-shield-check me-1"></i>
          <?= t('The link only allows one photo to be sent to this registration. It expires automatically and shows no customer information.') ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="docPairCancel"><?= t('Cancel capture') ?></button>
        <button type="button" class="btn btn-primary d-none" id="docPairRenew">
          <i class="bi bi-arrow-repeat me-1"></i><?= t('New code') ?>
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
