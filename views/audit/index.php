<?php
/**
 * views/audit/index.php — audit trail (administrator only).
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireAdmin();

$db       = Database::getConnection();
$logModel = new AuditLog();

$filters = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'action' => trim((string) ($_GET['action'] ?? '')),
    'entity' => trim((string) ($_GET['entity'] ?? '')),
];

$rows = $logModel->recent(
    300,
    $filters['action'] !== '' ? $filters['action'] : null,
    $filters['entity'] !== '' ? $filters['entity'] : null,
    $filters['q'] !== '' ? $filters['q'] : null
);

$actions = $db->query(
    "SELECT action, COUNT(*) AS n FROM audit_log GROUP BY action ORDER BY action"
)->fetchAll();

$entities = $db->query(
    "SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type"
)->fetchAll(PDO::FETCH_COLUMN);

$todayCount = (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

$totalCount = (int) $db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

$pageTitle = t('Audit log');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
  <div>
    <h4><?= t('Audit log') ?></h4>
    <p><?= t(':total entries · :today today', ['total' => $totalCount, 'today' => $todayCount]) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">
      <i class="bi bi-printer me-1"></i><?= t('Print') ?>
    </button>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= BASE_URL ?>views/audit/index.php">
  <div class="field grow">
    <label for="q"><?= t('Search') ?></label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="<?= t('Details, user or action') ?>">
  </div>

  <div class="field">
    <label for="action"><?= t('Action') ?></label>
    <select class="form-select" id="action" name="action" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach ($actions as $action): ?>
        <option value="<?= e($action['action']) ?>" <?= $filters['action'] === $action['action'] ? 'selected' : '' ?>>
          <?= e($action['action']) ?> (<?= (int) $action['n'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="entity"><?= t('Entity') ?></label>
    <select class="form-select" id="entity" name="entity" data-auto-submit>
      <option value=""><?= t('All') ?></option>
      <?php foreach ($entities as $entity): ?>
        <option value="<?= e($entity) ?>" <?= $filters['entity'] === $entity ? 'selected' : '' ?>><?= e($entity) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label>&nbsp;</label>
    <button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i><?= t('Filter') ?></button>
  </div>

  <?php if (array_filter($filters)): ?>
  <div class="field">
    <label>&nbsp;</label>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/audit/index.php"><?= t('Clear') ?></a>
  </div>
  <?php endif; ?>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty-state">
      <i class="bi bi-shield-lock"></i>
      <strong><?= t('No audit entries match this filter') ?></strong>
      <p><?= t('Every meaningful action is recorded here automatically.') ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><?= t('When') ?></th>
            <th><?= t('User') ?></th>
            <th><?= t('Action') ?></th>
            <th class="d-none d-md-table-cell"><?= t('Entity') ?></th>
            <th><?= t('Details') ?></th>
            <th class="d-none d-xl-table-cell"><?= t('IP address') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small"><?= e(formatDateTime($row['created_at'])) ?></td>
              <td class="small">
                <?= e($row['username'] ?? t('system')) ?>
                <?php if (!empty($row['user_id'])): ?>
                  <span class="sub">#<?= (int) $row['user_id'] ?></span>
                <?php endif; ?>
              </td>
              <td><span class="ref"><?= e($row['action']) ?></span></td>
              <td class="d-none d-md-table-cell small text-muted">
                <?= e($row['entity_type'] ?? '—') ?><?= $row['entity_id'] ? ' #' . (int) $row['entity_id'] : '' ?>
              </td>
              <td class="small"><?= e($row['details'] ?? '') ?></td>
              <td class="d-none d-xl-table-cell small text-muted"><?= e($row['ip_address'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<p class="small text-muted mt-2">
  <?= t('Showing the most recent 300 entries. Narrow the filters to find older activity.') ?>
</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
