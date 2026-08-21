<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_admin();

$db = get_db();
$todayDate = date('Y-m-d');

function report_table_exists(PDO $db, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function report_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function report_campaign_windows(string $month, string $todayDate): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $firstDay = DateTime::createFromFormat('!Y-m-d', $month . '-01')
        ?: new DateTime('first day of this month');
    $firstFriday = clone $firstDay;

    if ($firstFriday->format('N') !== '5') {
        $firstFriday->modify('next friday');
    }

    $windows = [];

    for ($i = 1; $i <= 4; $i++) {
        $start = clone $firstFriday;
        $start->modify('+' . (($i - 1) * 7) . ' days');
        $end = clone $start;
        $end->modify('+4 days');

        $startText = $start->format('Y-m-d');

        if ($startText > $todayDate) {
            continue;
        }

        $windows[] = [
            'key' => 'campaign_' . $i,
            'label' => 'Campaign ' . $i,
            'start' => $startText,
            'end' => $end->format('Y-m-d'),
            'display' => $start->format('j M') . ' - ' . $end->format('j M'),
        ];
    }

    return $windows;
}

function report_month_options(string $selectedMonth): array
{
    $base = new DateTime('first day of this month');
    $months = [];

    for ($i = -8; $i <= 1; $i++) {
        $month = (clone $base)->modify(($i >= 0 ? '+' : '') . $i . ' months');
        $months[$month->format('Y-m')] = $month->format('F Y');
    }

    if (!isset($months[$selectedMonth]) && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
        $selected = DateTime::createFromFormat('!Y-m-d', $selectedMonth . '-01');

        if ($selected) {
            $months[$selectedMonth] = $selected->format('F Y');
            ksort($months);
        }
    }

    return $months;
}

function report_latest_followup_id_sql(PDO $db, string $leadIdSql): string
{
    $orderParts = [];

    if (report_column_exists($db, 'follow_ups', 'followup_number')) {
        $orderParts[] = 'f_latest.followup_number DESC';
    }

    if (report_column_exists($db, 'follow_ups', 'followup_date')) {
        $orderParts[] = 'f_latest.followup_date DESC';
    }

    if (report_column_exists($db, 'follow_ups', 'followup_time')) {
        $orderParts[] = 'f_latest.followup_time DESC';
    }

    $orderParts[] = 'f_latest.id DESC';

    return '(
        SELECT f_latest.id
        FROM follow_ups f_latest
        WHERE f_latest.lead_id = ' . $leadIdSql . '
        ORDER BY ' . implode(', ', $orderParts) . '
        LIMIT 1
    )';
}

function report_latest_join(PDO $db): string
{
    if (
        !report_table_exists($db, 'follow_ups')
        || !report_column_exists($db, 'follow_ups', 'id')
        || !report_column_exists($db, 'follow_ups', 'lead_id')
    ) {
        return '';
    }

    return 'LEFT JOIN follow_ups lf ON lf.id = '
        . report_latest_followup_id_sql($db, 'l.id');
}

function report_effective_status_sql(PDO $db): string
{
    return report_column_exists($db, 'follow_ups', 'lead_status')
        ? 'COALESCE(lf.lead_status, l.status)'
        : 'l.status';
}

function report_parent_response_sql(PDO $db): string
{
    if (report_column_exists($db, 'follow_ups', 'outcome')) {
        return report_column_exists($db, 'leads', 'parent_response')
            ? 'COALESCE(lf.outcome, l.parent_response, "pending")'
            : 'COALESCE(lf.outcome, "pending")';
    }

    return report_column_exists($db, 'leads', 'parent_response')
        ? 'COALESCE(l.parent_response, "pending")'
        : '"pending"';
}

function report_count(PDO $db, string $join, string $start, string $end, string $condition = '', array $params = []): int
{
    $sql = "SELECT COUNT(DISTINCT l.id)
            FROM leads l
            {$join}
            WHERE l.received_date BETWEEN ? AND ?";

    if ($condition !== '') {
        $sql .= ' AND (' . $condition . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$start, $end], $params));

    return (int)$stmt->fetchColumn();
}

function report_breakdown(PDO $db, string $join, string $selectSql, string $start, string $end): array
{
    $stmt = $db->prepare(
        "SELECT {$selectSql} AS bucket, COUNT(DISTINCT l.id) AS total
         FROM leads l
         {$join}
         WHERE l.received_date BETWEEN ? AND ?
         GROUP BY bucket
         ORDER BY total DESC, bucket ASC"
    );
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$scope = trim((string)($_GET['scope'] ?? 'month'));
if (!in_array($scope, ['month', 'campaign'], true)) {
    $scope = 'month';
}

$monthOptions = report_month_options($selectedMonth);
$campaignWindows = report_campaign_windows($selectedMonth, $todayDate);
$selectedCampaign = trim((string)($_GET['campaign'] ?? 'all'));
$validCampaigns = array_column($campaignWindows, 'key');

if ($selectedCampaign !== 'all' && !in_array($selectedCampaign, $validCampaigns, true)) {
    $selectedCampaign = 'all';
}

$selectedWindow = null;
foreach ($campaignWindows as $window) {
    if ($window['key'] === $selectedCampaign) {
        $selectedWindow = $window;
        break;
    }
}

if ($scope === 'campaign' && !$selectedWindow && $campaignWindows) {
    $selectedWindow = $campaignWindows[count($campaignWindows) - 1];
    $selectedCampaign = $selectedWindow['key'];
}

[$monthStart, $monthEnd] = month_bounds($selectedMonth);

if ($scope === 'campaign' && $selectedWindow) {
    $start = $selectedWindow['start'];
    $end = $selectedWindow['end'];
    $scopeLabel = $selectedWindow['label'] . ' - ' . $selectedWindow['display'];
} else {
    $scope = 'month';
    $start = $monthStart;
    $end = $monthEnd;
    $scopeLabel = $monthOptions[$selectedMonth] ?? date('F Y', strtotime($monthStart));
}

$latestJoin = report_latest_join($db);
$statusSql = report_effective_status_sql($db);
$responseSql = report_parent_response_sql($db);
$totalLeads = report_count($db, $latestJoin, $start, $end);
$joined = report_count($db, $latestJoin, $start, $end, "{$statusSql} IN ('joined', 'converted')");
$scheduled = report_count($db, $latestJoin, $start, $end, "{$statusSql} = 'visit_scheduled'");
$visited = report_count(
    $db,
    $latestJoin,
    $start,
    $end,
    "{$statusSql} IN ('visited', 'placement_test_scheduled', 'placement_test_completed', 'joined', 'converted')"
);
$lost = report_count(
    $db,
    $latestJoin,
    $start,
    $end,
    "{$statusSql} IN ('closed', 'rejected', 'not_interested', 'random_click')"
);
$pendingAppointments = report_count(
    $db,
    $latestJoin,
    $start,
    $end,
    "({$statusSql} IN ('visit_interested', 'visit_requested') OR {$responseSql} IN ('interested', 'positive'))
     AND l.visit_date IS NULL
     AND {$statusSql} NOT IN ('visit_scheduled', 'visited', 'placement_test_scheduled', 'placement_test_completed', 'joined', 'converted', 'closed')"
);
$missedAppointments = report_count(
    $db,
    $latestJoin,
    $start,
    $end,
    "{$statusSql} = 'visit_scheduled'
     AND l.visit_date IS NOT NULL
     AND l.visit_date < ?",
    [$todayDate]
);
$interviews = report_count(
    $db,
    $latestJoin,
    $start,
    $end,
    "{$statusSql} IN ('placement_test_scheduled', 'placement_test_completed')"
);

$conversionRate = $totalLeads > 0
    ? round(($joined / $totalLeads) * 100, 1)
    : 0;
$visitRate = $totalLeads > 0
    ? round(($visited / $totalLeads) * 100, 1)
    : 0;

$summaryStats = [
    ['label' => 'Total Leads', 'value' => $totalLeads, 'tone' => 'blue'],
    ['label' => 'Pending Appointments', 'value' => $pendingAppointments, 'tone' => 'amber'],
    ['label' => 'Scheduled Appointments', 'value' => $scheduled, 'tone' => 'green'],
    ['label' => 'Missed Appointments', 'value' => $missedAppointments, 'tone' => 'red'],
    ['label' => 'School Visits', 'value' => $visited, 'tone' => 'green'],
    ['label' => 'Lost Leads', 'value' => $lost, 'tone' => 'red'],
    ['label' => 'Interviews', 'value' => $interviews, 'tone' => 'blue'],
    ['label' => 'Confirmed Enrollment', 'value' => $joined, 'tone' => 'green'],
];

$sourceBreakdown = report_breakdown($db, $latestJoin, 'l.source', $start, $end);
$gradeBreakdown = report_breakdown($db, $latestJoin, 'NULLIF(l.grade, "")', $start, $end);
$statusBreakdown = report_breakdown($db, $latestJoin, $statusSql, $start, $end);
$responseBreakdown = report_breakdown($db, $latestJoin, $responseSql, $start, $end);

$page_title = 'Reports';
$active = 'reports';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="report-page">
  <div class="topbar report-topbar">
    <div>
      <div class="eyebrow">Client report</div>
      <h1>Admissions performance report</h1>
      <p>No student or parent names are included in this report.</p>
    </div>
    <button type="button" class="btn btn-primary report-print-button" onclick="window.print()">Download PDF</button>
  </div>

  <form method="get" class="card report-controls">
    <div class="field">
      <label for="scope">Report type</label>
      <select id="scope" name="scope" onchange="this.form.submit()">
        <option value="month" <?= $scope === 'month' ? 'selected' : '' ?>>Monthly report</option>
        <option value="campaign" <?= $scope === 'campaign' ? 'selected' : '' ?>>Ad campaign report</option>
      </select>
    </div>

    <div class="field">
      <label for="month">Month</label>
      <select id="month" name="month" onchange="this.form.submit()">
        <?php foreach ($monthOptions as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $selectedMonth === $value ? 'selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="campaign">Campaign</label>
      <select id="campaign" name="campaign" onchange="this.form.submit()" <?= $scope !== 'campaign' ? 'disabled' : '' ?>>
        <option value="all">Select campaign</option>
        <?php foreach ($campaignWindows as $window): ?>
          <option value="<?= e($window['key']) ?>" <?= $selectedCampaign === $window['key'] ? 'selected' : '' ?>>
            <?= e($window['label'] . ' - ' . $window['display']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <section class="report-sheet">
    <div class="report-brand-row">
      <div>
        <span><?= e(SCHOOL_NAME) ?></span>
        <h2>Admissions Campaign Report</h2>
      </div>
      <div>
        <strong><?= e($scopeLabel) ?></strong>
        <span><?= e(fmt_date($start)) ?> to <?= e(fmt_date($end)) ?></span>
      </div>
    </div>

    <div class="report-summary-band">
      <div>
        <span>Total Leads</span>
        <strong><?= (int)$totalLeads ?></strong>
      </div>
      <div>
        <span>Conversion Rate</span>
        <strong><?= e((string)$conversionRate) ?>%</strong>
      </div>
      <div>
        <span>Visit Rate</span>
        <strong><?= e((string)$visitRate) ?>%</strong>
      </div>
      <div>
        <span>Generated</span>
        <strong><?= e(fmt_date($todayDate)) ?></strong>
      </div>
    </div>

    <div class="report-stat-grid">
      <?php foreach ($summaryStats as $stat): ?>
        <article class="report-stat-card tone-<?= e($stat['tone']) ?>">
          <strong><?= (int)$stat['value'] ?></strong>
          <span><?= e($stat['label']) ?></span>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="report-grid-two">
      <section class="report-panel">
        <h3>Lead Sources</h3>
        <?php foreach ($sourceBreakdown as $row): ?>
          <?php $label = SOURCE_LABELS[$row['bucket']] ?? ucwords(str_replace('_', ' ', (string)$row['bucket'])); ?>
          <div class="report-row"><span><?= e($label ?: 'Unknown') ?></span><strong><?= (int)$row['total'] ?></strong></div>
        <?php endforeach; ?>
      </section>

      <section class="report-panel">
        <h3>Grades Requested</h3>
        <?php foreach ($gradeBreakdown as $row): ?>
          <div class="report-row"><span><?= e($row['bucket'] ?: 'Not specified') ?></span><strong><?= (int)$row['total'] ?></strong></div>
        <?php endforeach; ?>
      </section>

      <section class="report-panel">
        <h3>Admissions Stage</h3>
        <?php foreach ($statusBreakdown as $row): ?>
          <div class="report-row"><span><?= e(status_label((string)$row['bucket'])) ?></span><strong><?= (int)$row['total'] ?></strong></div>
        <?php endforeach; ?>
      </section>

      <section class="report-panel">
        <h3>Parent Response</h3>
        <?php foreach ($responseBreakdown as $row): ?>
          <div class="report-row"><span><?= e(parent_response_label((string)$row['bucket'])) ?></span><strong><?= (int)$row['total'] ?></strong></div>
        <?php endforeach; ?>
      </section>
    </div>

    <div class="report-footer-note">
      This report is aggregate-only and excludes student names, parent names, phone numbers, and private notes.
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
