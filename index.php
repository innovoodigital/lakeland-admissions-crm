<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$db = get_db();
$ym = $_GET['month'] ?? date('Y-m');
[$start, $end, $ym] = month_bounds($ym);

$active_statuses = "'new','contacted','high_quality','follow_up','visit_scheduled'";

$stat_leads = $db->prepare("SELECT COUNT(*) FROM leads WHERE received_date BETWEEN ? AND ?");
$stat_leads->execute([$start, $end]);
$total_leads = (int)$stat_leads->fetchColumn();

$stat_calls = $db->prepare("SELECT COUNT(*) FROM follow_ups WHERE followup_date BETWEEN ? AND ?");
$stat_calls->execute([$start, $end]);
$total_calls = (int)$stat_calls->fetchColumn();

$stat_visits = $db->prepare("SELECT COUNT(*) FROM leads WHERE visit_date BETWEEN ? AND ?");
$stat_visits->execute([$start, $end]);
$total_visits = (int)$stat_visits->fetchColumn();

$stat_conv = $db->prepare("SELECT COUNT(*) FROM leads WHERE converted_date BETWEEN ? AND ?");
$stat_conv->execute([$start, $end]);
$total_conv = (int)$stat_conv->fetchColumn();

// Status breakdown (all-time, all active leads)
$breakdown = $db->query("SELECT status, COUNT(*) c FROM leads GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// Leads needing follow-up: active status, ordered by how long since last contact
$stale = $db->query("
  SELECT l.*, 
    COALESCE(MAX(f.followup_date), l.received_date) AS last_touch,
    DATEDIFF(CURDATE(), COALESCE(MAX(f.followup_date), l.received_date)) AS days_since
  FROM leads l
  LEFT JOIN follow_ups f ON f.lead_id = l.id
  WHERE l.status IN ($active_statuses)
  GROUP BY l.id
  HAVING days_since >= 1
  ORDER BY days_since DESC
  LIMIT 12
")->fetchAll();

$page_title = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="topbar">
  <div>
    <div class="eyebrow">Monthly register</div>
    <h1>Admissions overview</h1>
  </div>
  <form method="get" class="filters" style="margin:0;">
    <div class="field">
      <label for="month">Month</label>
      <input type="month" id="month" name="month" value="<?= e($ym) ?>" onchange="this.form.submit()">
    </div>
  </form>
</div>

<div class="grid grid-3">
  <div class="card stat">
    <span class="num"><?= $total_leads ?></span>
    <span class="label">New inquiries</span>
  </div>
  <div class="card stat">
    <span class="num"><?= $total_calls ?></span>
    <span class="label">Follow-ups logged</span>
  </div>
  <div class="card stat">
    <span class="num"><?= $total_conv ?> <span style="font-size:1rem;color:var(--slate);">/ <?= MONTHLY_CONVERSION_GOAL ?></span></span>
    <span class="label">Conversions</span>
  </div>
</div>

<div class="card">
  <h2>Visit register — goal <?= MONTHLY_VISIT_GOAL ?> school visits this month</h2>
  <div class="register">
    <?php for ($i = 1; $i <= MONTHLY_VISIT_GOAL; $i++): ?>
      <div class="slot <?= $i <= $total_visits ? 'filled' : '' ?> <?= ($i <= $total_visits && $total_visits >= MONTHLY_VISIT_GOAL) ? 'goal-hit' : '' ?>"><?= $i ?></div>
    <?php endfor; ?>
  </div>
  <div class="register-caption"><?= $total_visits ?> of <?= MONTHLY_VISIT_GOAL ?> visits booked so far this month — <?= max(0, MONTHLY_VISIT_GOAL - $total_visits) ?> to go.</div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Needs a follow-up</h2>
    <?php if (!$stale): ?>
      <p style="color:var(--slate);">Nothing overdue — every active lead has been touched recently.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Child / Parent</th><th>Status</th><th>Last contact</th><th class="num">Days</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($stale as $l): ?>
          <tr>
            <td><?= e($l['child_name'] ?: '—') ?><br><small style="color:var(--slate);"><?= e($l['parent_name']) ?></small></td>
            <td><span class="tag <?= status_class($l['status']) ?>"><?= status_label($l['status']) ?></span></td>
            <td><?= fmt_date($l['last_touch']) ?></td>
            <td class="num"><?= (int)$l['days_since'] ?></td>
            <td><a class="btn btn-outline btn-sm" href="lead_view.php?id=<?= (int)$l['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>All leads by status</h2>
    <?php foreach (STATUS_LABELS as $key => $label): $c = $breakdown[$key] ?? 0; if (!$c) continue; ?>
      <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--line);">
        <span class="tag <?= status_class($key) ?>"><?= e($label) ?></span>
        <span class="mono"><?= (int)$c ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
