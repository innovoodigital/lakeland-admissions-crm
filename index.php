<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$db = get_db();
$ym = $_GET['month'] ?? date('Y-m');
[$monthStart, $monthEnd, $ym] = month_bounds($ym);

$active_statuses = "'new','contacted','high_quality','follow_up','visit_scheduled'";

function dashboard_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return false;
    }
}

function dashboard_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return false;
    }
}

function dashboard_has_latest_followup_status(PDO $db): bool
{
    return dashboard_table_exists($db, 'follow_ups')
        && dashboard_column_exists($db, 'follow_ups', 'id')
        && dashboard_column_exists($db, 'follow_ups', 'lead_id')
        && dashboard_column_exists($db, 'follow_ups', 'lead_status');
}

function dashboard_latest_followup_join(
    PDO $db,
    string $leadAlias = 'l',
    string $latestAlias = 'lf'
): string {
    if (!dashboard_has_latest_followup_status($db)) {
        return '';
    }

    return "LEFT JOIN follow_ups {$latestAlias}
            ON {$latestAlias}.id = "
            . dashboard_latest_followup_id_sql($db, "{$leadAlias}.id");
}

function dashboard_latest_followup_id_sql(PDO $db, string $leadIdSql): string
{
    $orderParts = [];

    if (dashboard_column_exists($db, 'follow_ups', 'followup_number')) {
        $orderParts[] = 'f_latest.followup_number DESC';
    }

    if (dashboard_column_exists($db, 'follow_ups', 'followup_date')) {
        $orderParts[] = 'f_latest.followup_date DESC';
    }

    if (dashboard_column_exists($db, 'follow_ups', 'followup_time')) {
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

function dashboard_effective_status_sql(
    PDO $db,
    string $leadAlias = 'l',
    string $latestAlias = 'lf'
): string {
    return dashboard_has_latest_followup_status($db)
        ? "COALESCE({$latestAlias}.lead_status, {$leadAlias}.status)"
        : "{$leadAlias}.status";
}

function dashboard_campaign_windows(string $month): array
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

        $windows[] = [
            'key' => 'campaign_' . $i,
            'label' => 'Campaign ' . $i,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'display' => $start->format('j M') . ' - ' . $end->format('j M'),
        ];
    }

    return $windows;
}

function dashboard_reminder_title(array $row): string
{
    foreach (['reminder_title', 'title', 'reminder_type'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return ucwords(str_replace('_', ' ', $value));
        }
    }

    return 'Reminder';
}

function dashboard_action_time(?string $time): string
{
    $time = trim((string)$time);

    if ($time === '') {
        return 'Any time';
    }

    $timestamp = strtotime($time);

    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function dashboard_lead_name(array $row): string
{
    $child = trim((string)($row['child_name'] ?? ''));
    $parent = trim((string)($row['parent_name'] ?? ''));

    return $child !== ''
        ? $child
        : ($parent !== '' ? $parent : 'Unnamed lead');
}

function dashboard_parent_name(array $row): string
{
    $parent = trim((string)($row['parent_name'] ?? ''));

    return $parent !== '' ? $parent : 'Parent not added';
}

function dashboard_phone_href(array $row): string
{
    $phone = preg_replace('/[^\d+]/', '', (string)($row['contact'] ?? ''));

    return $phone !== '' ? 'tel:' . $phone : '';
}

function dashboard_status_label(array $row): string
{
    return status_label((string)($row['effective_status'] ?? $row['status'] ?? 'new'));
}

function dashboard_add_action(
    array &$actions,
    array $row,
    string $type,
    string $title,
    string $date,
    ?string $time = null,
    string $tone = 'info',
    string $note = ''
): void {
    $leadId = (int)($row['id'] ?? 0);
    $date = trim($date);
    $timeLabel = dashboard_action_time($time);
    $actionGroup = in_array($tone, ['visit', 'call'], true)
        ? $tone
        : strtolower(
            preg_replace('/[^a-z0-9]+/i', '_', $type . '_' . $title)
        );

    if (in_array($tone, ['visit', 'call', 'danger'], true)) {
        foreach ($actions as $index => $existingAction) {
            if (
                (int)($existingAction['lead_id'] ?? 0) === $leadId
                && (string)($existingAction['date'] ?? '') === $date
                && (string)($existingAction['tone'] ?? '') === $tone
                && (string)($existingAction['group'] ?? '') === $actionGroup
            ) {
                $existingHasTime = ($existingAction['time'] ?? 'Any time') !== 'Any time';
                $newHasTime = $timeLabel !== 'Any time';

                if ($newHasTime && !$existingHasTime) {
                    $actions[$index]['time'] = $timeLabel;
                }

                if ($type === 'Call / reminder') {
                    $actions[$index]['type'] = $type;
                    $actions[$index]['title'] = $title;
                    $actions[$index]['note'] = $note;
                }

                return;
            }
        }
    }

    $actions[] = [
        'lead_id' => $leadId,
        'name' => dashboard_lead_name($row),
        'parent' => dashboard_parent_name($row),
        'contact' => trim((string)($row['contact'] ?? '')),
        'phone_href' => dashboard_phone_href($row),
        'status' => dashboard_status_label($row),
        'group' => $actionGroup,
        'type' => $type,
        'title' => $title,
        'date' => $date,
        'time' => $timeLabel,
        'tone' => $tone,
        'note' => $note,
    ];
}

function dashboard_sort_actions(array &$actions): void
{
    usort(
        $actions,
        static function (array $a, array $b): int {
            $aTime = $a['time'] === 'Any time' ? '99:99' : date('H:i', strtotime($a['time']) ?: 0);
            $bTime = $b['time'] === 'Any time' ? '99:99' : date('H:i', strtotime($b['time']) ?: 0);

            return [$a['date'], $aTime, $a['name']] <=> [$b['date'], $bTime, $b['name']];
        }
    );
}

function dashboard_count_leads(
    PDO $db,
    string $latestFollowupJoin,
    string $start,
    string $end,
    string $condition = '',
    array $params = []
): int {
    $sql = "SELECT COUNT(DISTINCT l.id)
            FROM leads l
            {$latestFollowupJoin}
            WHERE l.received_date BETWEEN ? AND ?";

    if ($condition !== '') {
        $sql .= ' AND (' . $condition . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$start, $end], $params));

    return (int)$stmt->fetchColumn();
}

$campaignWindows = dashboard_campaign_windows($ym);
$selectedCampaign = trim((string)($_GET['campaign'] ?? 'all'));
$validCampaignKeys = array_column($campaignWindows, 'key');

if ($selectedCampaign !== 'all' && !in_array($selectedCampaign, $validCampaignKeys, true)) {
    $selectedCampaign = 'all';
}

$selectedWindow = null;
foreach ($campaignWindows as $window) {
    if ($window['key'] === $selectedCampaign) {
        $selectedWindow = $window;
        break;
    }
}

$start = $selectedWindow['start'] ?? $campaignWindows[0]['start'];
$end = $selectedWindow['end'] ?? $campaignWindows[count($campaignWindows) - 1]['end'];
$campaignRangeLabel = $selectedWindow
    ? $selectedWindow['label'] . ' (' . $selectedWindow['display'] . ')'
    : 'All campaigns (' . $campaignWindows[0]['display'] . ' to '
        . $campaignWindows[count($campaignWindows) - 1]['display'] . ')';

$todayDate = date('Y-m-d');
$tomorrowDate = (new DateTime('tomorrow'))->format('Y-m-d');
$nextWeekDate = (new DateTime('+7 days'))->format('Y-m-d');
$yesterdayDate = (new DateTime('yesterday'))->format('Y-m-d');

$stat_leads = $db->prepare("SELECT COUNT(*) FROM leads WHERE received_date BETWEEN ? AND ?");
$stat_leads->execute([$start, $end]);
$total_leads = (int)$stat_leads->fetchColumn();

$latestFollowupJoin = dashboard_latest_followup_join($db);
$effectiveStatusSql = dashboard_effective_status_sql($db);

$plannedVisitConditions = [
    'l.visit_date BETWEEN ? AND ?',
];
$plannedVisitParams = [$start, $end];

if (
    dashboard_column_exists($db, 'follow_ups', 'lead_status')
    && dashboard_column_exists($db, 'follow_ups', 'next_action_date')
) {
    $plannedVisitConditions[] = 'lf.next_action_date BETWEEN ? AND ?';
    $plannedVisitParams[] = $start;
    $plannedVisitParams[] = $end;
}

if (
    dashboard_table_exists($db, 'followup_schedule_options')
    && dashboard_column_exists($db, 'followup_schedule_options', 'lead_id')
) {
    $plannedVisitConditions[] = "EXISTS (
        SELECT 1
        FROM followup_schedule_options s
        WHERE s.lead_id = l.id
          AND s.schedule_type IN ('visit_preference', 'confirmed_visit')
          AND s.option_date BETWEEN ? AND ?
    )";
    $plannedVisitParams[] = $start;
    $plannedVisitParams[] = $end;
} elseif (
    dashboard_table_exists($db, 'followup_schedule_options')
    && dashboard_column_exists($db, 'followup_schedule_options', 'followup_id')
) {
    $plannedVisitConditions[] = "EXISTS (
        SELECT 1
        FROM followup_schedule_options s
        INNER JOIN follow_ups f ON f.id = s.followup_id
        WHERE f.lead_id = l.id
          AND s.schedule_type IN ('visit_preference', 'confirmed_visit')
          AND s.option_date BETWEEN ? AND ?
    )";
    $plannedVisitParams[] = $start;
    $plannedVisitParams[] = $end;
}

$stat_visits = $db->prepare(
    'SELECT COUNT(DISTINCT l.id)
     FROM leads l
     ' . $latestFollowupJoin . "
     WHERE l.received_date BETWEEN ? AND ?
       AND {$effectiveStatusSql} = 'visit_scheduled'
       AND (" . implode(' OR ', $plannedVisitConditions) . ')'
);
$stat_visits->execute(array_merge([$start, $end], $plannedVisitParams));
$total_visits = (int)$stat_visits->fetchColumn();
$visit_goal = (int) MONTHLY_VISIT_GOAL;
$visit_remaining = max(0, $visit_goal - $total_visits);
$visit_progress = $visit_goal > 0
    ? min(100, round(($total_visits / $visit_goal) * 100))
    : 0;

$enrolledConditions = [
    'l.converted_date BETWEEN ? AND ?',
];
$enrolledParams = [$start, $end];

if (
    dashboard_table_exists($db, 'followup_schedule_options')
    && dashboard_column_exists($db, 'followup_schedule_options', 'lead_id')
) {
    $enrolledConditions[] = "EXISTS (
        SELECT 1
        FROM followup_schedule_options s
        WHERE s.lead_id = l.id
          AND s.schedule_type = 'enrollment'
          AND s.option_date BETWEEN ? AND ?
    )";
    $enrolledParams[] = $start;
    $enrolledParams[] = $end;
} elseif (
    dashboard_table_exists($db, 'followup_schedule_options')
    && dashboard_column_exists($db, 'followup_schedule_options', 'followup_id')
) {
    $enrolledConditions[] = "EXISTS (
        SELECT 1
        FROM followup_schedule_options s
        INNER JOIN follow_ups f ON f.id = s.followup_id
        WHERE f.lead_id = l.id
          AND s.schedule_type = 'enrollment'
          AND s.option_date BETWEEN ? AND ?
    )";
    $enrolledParams[] = $start;
    $enrolledParams[] = $end;
}

$stat_conv = $db->prepare(
    'SELECT COUNT(DISTINCT l.id)
     FROM leads l
     ' . $latestFollowupJoin . "
     WHERE l.received_date BETWEEN ? AND ?
       AND {$effectiveStatusSql} IN ('joined', 'converted')
       AND (" . implode(' OR ', $enrolledConditions) . ')'
);
$stat_conv->execute(array_merge([$start, $end], $enrolledParams));
$total_conv = (int)$stat_conv->fetchColumn();

$pendingAppointmentCondition = "{$effectiveStatusSql} IN ('visit_interested', 'visit_requested')
    AND l.visit_date IS NULL";

if (dashboard_column_exists($db, 'leads', 'parent_response')) {
    $pendingAppointmentCondition = '(' . $pendingAppointmentCondition . ")
        OR (
            l.parent_response IN ('interested', 'positive')
            AND {$effectiveStatusSql} NOT IN (
                'visit_scheduled',
                'visited',
                'placement_test_scheduled',
                'placement_test_completed',
                'joined',
                'converted',
                'closed',
                'rejected',
                'not_interested',
                'random_click'
            )
            AND l.visit_date IS NULL
        )";
}

$campaignStats = [
    [
        'label' => 'Total Leads',
        'value' => $total_leads,
        'tone' => 'blue',
    ],
    [
        'label' => 'Pending Appointments',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            $pendingAppointmentCondition
        ),
        'tone' => 'blue',
    ],
    [
        'label' => 'Scheduled Appointments',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            "{$effectiveStatusSql} = 'visit_scheduled'"
        ),
        'tone' => 'green',
    ],
    [
        'label' => 'Missed Appointments',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            "{$effectiveStatusSql} = 'visit_scheduled'
                AND l.visit_date IS NOT NULL
                AND l.visit_date < ?",
            [$todayDate ?? date('Y-m-d')]
        ),
        'tone' => 'red',
    ],
    [
        'label' => 'School Visits',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            "{$effectiveStatusSql} IN (
                'visited',
                'placement_test_scheduled',
                'placement_test_completed',
                'joined',
                'converted'
            )"
        ),
        'tone' => 'green',
    ],
    [
        'label' => 'Lost Leads',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            "{$effectiveStatusSql} IN (
                'closed',
                'rejected',
                'not_interested',
                'random_click'
            )"
        ),
        'tone' => 'blue',
    ],
    [
        'label' => 'Interviews',
        'value' => dashboard_count_leads(
            $db,
            $latestFollowupJoin,
            $start,
            $end,
            "{$effectiveStatusSql} IN (
                'placement_test_scheduled',
                'placement_test_completed'
            )"
        ),
        'tone' => 'blue',
    ],
    [
        'label' => 'Confirmed Enrollment',
        'value' => $total_conv,
        'tone' => 'red',
    ],
];

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

$todayActions = [];
$overdueActions = [];
$upcomingVisits = [];
$newUncontacted = [];
$pendingVisits = [];

if (
    dashboard_table_exists($db, 'lead_reminders')
    && dashboard_column_exists($db, 'lead_reminders', 'lead_id')
    && dashboard_column_exists($db, 'lead_reminders', 'reminder_date')
) {
    $statusSql = dashboard_column_exists($db, 'lead_reminders', 'status')
        ? " AND COALESCE(r.status, 'pending') = 'pending'"
        : '';
    $timeOrder = dashboard_column_exists($db, 'lead_reminders', 'reminder_time')
        ? 'r.reminder_time ASC,'
        : '';
    $latestReminderSql = (
        dashboard_has_latest_followup_status($db)
        && dashboard_column_exists($db, 'lead_reminders', 'followup_id')
    )
        ? ' AND (
              r.followup_id IS NULL
              OR r.followup_id = ' . dashboard_latest_followup_id_sql($db, 'l.id') . '
            )'
        : '';
    $reminderLatestJoin = dashboard_has_latest_followup_status($db)
        ? $latestFollowupJoin
        : '';
    $reminderEffectiveStatusSelect = dashboard_has_latest_followup_status($db)
        ? ", {$effectiveStatusSql} AS effective_status"
        : '';

    $stmt = $db->prepare(
        "SELECT r.*, l.* {$reminderEffectiveStatusSelect}
         FROM lead_reminders r
         INNER JOIN leads l ON l.id = r.lead_id
         {$reminderLatestJoin}
         WHERE r.reminder_date = ? {$statusSql} {$latestReminderSql}
           AND l.received_date BETWEEN ? AND ?
         ORDER BY {$timeOrder} l.child_name ASC
         LIMIT 12"
    );
    $stmt->execute([$todayDate, $start, $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboard_add_action(
            $todayActions,
            $row,
            'Call / reminder',
            dashboard_reminder_title($row),
            $todayDate,
            $row['reminder_time'] ?? null,
            'call',
            'Reminder due today'
        );
    }

    $stmt = $db->prepare(
        "SELECT r.*, l.* {$reminderEffectiveStatusSelect}
         FROM lead_reminders r
         INNER JOIN leads l ON l.id = r.lead_id
         {$reminderLatestJoin}
         WHERE r.reminder_date < ? {$statusSql} {$latestReminderSql}
           AND l.received_date BETWEEN ? AND ?
         ORDER BY r.reminder_date ASC, {$timeOrder} l.child_name ASC
         LIMIT 12"
    );
    $stmt->execute([$todayDate, $start, $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboard_add_action(
            $overdueActions,
            $row,
            'Overdue reminder',
            dashboard_reminder_title($row),
            (string)($row['reminder_date'] ?? ''),
            $row['reminder_time'] ?? null,
            'danger',
            'Reminder date has passed'
        );
    }
}

if (dashboard_column_exists($db, 'follow_ups', 'next_action_date')) {
    $latestFollowupOnlySql = dashboard_has_latest_followup_status($db)
        ? ' AND f.id = ' . dashboard_latest_followup_id_sql($db, 'l.id')
        : '';

    $stmt = $db->prepare(
        'SELECT l.*, f.next_action_date, f.followup_time'
        . (
            dashboard_column_exists($db, 'follow_ups', 'lead_status')
                ? ', f.lead_status AS effective_status'
                : ''
        ) . '
         FROM follow_ups f
         INNER JOIN leads l ON l.id = f.lead_id
         WHERE f.next_action_date = ?
         ' . $latestFollowupOnlySql . '
           AND l.received_date BETWEEN ? AND ?
         ORDER BY f.followup_time ASC, l.child_name ASC
         LIMIT 12'
    );
    $stmt->execute([$todayDate, $start, $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboard_add_action(
            $todayActions,
            $row,
            'Follow-up',
            'Call parent today',
            $todayDate,
            $row['followup_time'] ?? null,
            'call',
            'Next action scheduled today'
        );
    }

    $stmt = $db->prepare(
        'SELECT l.*, f.next_action_date, f.followup_time'
        . (
            dashboard_column_exists($db, 'follow_ups', 'lead_status')
                ? ', f.lead_status AS effective_status'
                : ''
        ) . '
         FROM follow_ups f
         INNER JOIN leads l ON l.id = f.lead_id
         WHERE f.next_action_date < ?
         ' . $latestFollowupOnlySql . '
           AND l.received_date BETWEEN ? AND ?
         ORDER BY f.next_action_date ASC, f.followup_time ASC, l.child_name ASC
         LIMIT 12'
    );
    $stmt->execute([$todayDate, $start, $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboard_add_action(
            $overdueActions,
            $row,
            'Missed follow-up',
            'Follow-up overdue',
            (string)($row['next_action_date'] ?? ''),
            $row['followup_time'] ?? null,
            'danger',
            'Next action date has passed'
        );
    }
}

$visitCompleteStatuses = "'visited','converted','joined','placement_test_scheduled','placement_test_completed'";

$stmt = $db->prepare(
    "SELECT l.*, {$effectiveStatusSql} AS effective_status
     FROM leads l
     {$latestFollowupJoin}
     WHERE l.visit_date = ?
       AND l.received_date BETWEEN ? AND ?
       AND {$effectiveStatusSql} = 'visit_scheduled'
     ORDER BY child_name ASC
     LIMIT 12"
);
$stmt->execute([$todayDate, $start, $end]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    dashboard_add_action(
        $todayActions,
        $row,
        'School visit',
        'Visit scheduled today',
        $todayDate,
        null,
        'visit',
        'Prepare for the family visit'
    );
}

$stmt = $db->prepare(
    "SELECT l.*, {$effectiveStatusSql} AS effective_status
     FROM leads l
     {$latestFollowupJoin}
     WHERE l.visit_date < ?
       AND l.received_date BETWEEN ? AND ?
       AND {$effectiveStatusSql} = 'visit_scheduled'
     ORDER BY visit_date ASC, child_name ASC
     LIMIT 12"
);
$stmt->execute([$todayDate, $start, $end]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    dashboard_add_action(
        $overdueActions,
        $row,
        'Missed appointment',
        'Visit date passed',
        (string)($row['visit_date'] ?? ''),
        null,
        'danger',
        'Call and reschedule'
    );
}

$stmt = $db->prepare(
    "SELECT l.*, {$effectiveStatusSql} AS effective_status
     FROM leads l
     {$latestFollowupJoin}
     WHERE l.visit_date BETWEEN ? AND ?
       AND l.received_date BETWEEN ? AND ?
       AND {$effectiveStatusSql} = 'visit_scheduled'
     ORDER BY visit_date ASC, child_name ASC
     LIMIT 10"
);
$stmt->execute([$tomorrowDate, $nextWeekDate, $start, $end]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    dashboard_add_action(
        $upcomingVisits,
        $row,
        'School visit',
        'Upcoming visit',
        (string)($row['visit_date'] ?? ''),
        null,
        'visit',
        'Next 7 days'
    );
}

if (
    dashboard_table_exists($db, 'followup_schedule_options')
    && dashboard_column_exists($db, 'followup_schedule_options', 'option_date')
    && dashboard_column_exists($db, 'followup_schedule_options', 'schedule_type')
) {
    $scheduleTimeColumn = dashboard_column_exists($db, 'followup_schedule_options', 'start_time')
        ? 's.start_time'
        : 'NULL';

    $scheduleJoin = '';
    $scheduleLatestSql = '';
    $scheduleEffectiveStatusSelect = '';
    if (dashboard_column_exists($db, 'followup_schedule_options', 'followup_id')) {
        $scheduleJoin = 'INNER JOIN follow_ups f ON f.id = s.followup_id INNER JOIN leads l ON l.id = f.lead_id';
        $scheduleLatestSql = dashboard_has_latest_followup_status($db)
            ? ' AND f.id = ' . dashboard_latest_followup_id_sql($db, 'l.id')
            : '';
        $scheduleEffectiveStatusSelect = dashboard_column_exists($db, 'follow_ups', 'lead_status')
            ? ', f.lead_status AS effective_status'
            : '';
    } elseif (dashboard_column_exists($db, 'followup_schedule_options', 'lead_id')) {
        $scheduleJoin = 'INNER JOIN leads l ON l.id = s.lead_id';
    }

    if ($scheduleJoin !== '') {
        $stmt = $db->prepare(
            "SELECT l.*, s.schedule_type, s.option_date, {$scheduleTimeColumn} AS schedule_time {$scheduleEffectiveStatusSelect}
             FROM followup_schedule_options s
             {$scheduleJoin}
             WHERE s.option_date = ?
               AND s.schedule_type IN ('visit_preference', 'confirmed_visit', 'placement_test', 'enrollment')
               {$scheduleLatestSql}
               AND l.received_date BETWEEN ? AND ?
             ORDER BY schedule_time ASC, l.child_name ASC
             LIMIT 12"
        );
        $stmt->execute([$todayDate, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string)($row['schedule_type'] ?? '');
            dashboard_add_action(
                $todayActions,
                $row,
                schedule_type_label($type),
                schedule_type_label($type) . ' today',
                $todayDate,
                $row['schedule_time'] ?? null,
                in_array($type, ['visit_preference', 'confirmed_visit'], true) ? 'visit' : 'info',
                'Scheduled for today'
            );
        }

        $stmt = $db->prepare(
            "SELECT l.*, s.schedule_type, s.option_date, {$scheduleTimeColumn} AS schedule_time {$scheduleEffectiveStatusSelect}
             FROM followup_schedule_options s
             {$scheduleJoin}
             WHERE s.option_date BETWEEN ? AND ?
               AND s.schedule_type IN ('visit_preference', 'confirmed_visit')
               {$scheduleLatestSql}
               AND l.received_date BETWEEN ? AND ?
             ORDER BY s.option_date ASC, schedule_time ASC, l.child_name ASC
             LIMIT 10"
        );
        $stmt->execute([$tomorrowDate, $nextWeekDate, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            dashboard_add_action(
                $upcomingVisits,
                $row,
                schedule_type_label((string)($row['schedule_type'] ?? '')),
                'Upcoming visit',
                (string)($row['option_date'] ?? ''),
                $row['schedule_time'] ?? null,
                'visit',
                'Next 7 days'
            );
        }
    }
}

$stmt = $db->prepare(
    "SELECT l.*
     FROM leads l
     LEFT JOIN follow_ups f ON f.lead_id = l.id
     WHERE l.received_date <= ?
       AND l.received_date BETWEEN ? AND ?
       AND f.id IS NULL
       AND l.status NOT IN ({$visitCompleteStatuses}, 'closed', 'rejected', 'not_interested')
     GROUP BY l.id
     ORDER BY l.received_date ASC, l.id ASC
     LIMIT 10"
);
$stmt->execute([$yesterdayDate, $start, $end]);
$newUncontacted = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingConditions = [
    "l.status IN ('visit_interested', 'visit_requested')",
];
if (dashboard_column_exists($db, 'leads', 'parent_response')) {
    $pendingConditions[] = "l.parent_response IN ('interested', 'positive')";
}
$stmt = $db->prepare(
    'SELECT l.*
     FROM leads l
     WHERE (' . implode(' OR ', $pendingConditions) . ')
       AND l.received_date BETWEEN ? AND ?
       AND l.visit_date IS NULL
       AND l.status NOT IN (' . $visitCompleteStatuses . ", 'closed', 'rejected', 'not_interested')
     ORDER BY l.received_date ASC, l.id ASC
     LIMIT 10"
);
$stmt->execute([$start, $end]);
$pendingVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

dashboard_sort_actions($todayActions);
dashboard_sort_actions($overdueActions);
dashboard_sort_actions($upcomingVisits);

function dashboard_render_actions(
    string $title,
    string $subtitle,
    array $actions,
    string $emptyText,
    string $class = ''
): void {
    ?>
    <section class="card dashboard-panel <?= e($class) ?>">
      <div class="dashboard-panel-head">
        <div>
          <h2><?= e($title) ?></h2>
          <p><?= e($subtitle) ?></p>
        </div>
        <span><?= count($actions) ?></span>
      </div>

      <?php if (!$actions): ?>
        <div class="dashboard-empty"><?= e($emptyText) ?></div>
      <?php else: ?>
        <div class="dashboard-action-list">
          <?php foreach (array_slice($actions, 0, 8) as $action): ?>
            <article class="dashboard-action-item tone-<?= e($action['tone']) ?>">
              <div class="dashboard-action-time">
                <strong><?= e($action['time']) ?></strong>
                <span><?= e(fmt_date($action['date'])) ?></span>
              </div>
              <div class="dashboard-action-main">
                <b><?= e($action['title']) ?></b>
                <span><?= e($action['type']) ?><?= $action['note'] !== '' ? ' - ' . e($action['note']) : '' ?></span>
              </div>
              <div class="dashboard-action-lead">
                <strong><?= e($action['name']) ?></strong>
                <span><?= e($action['parent']) ?> - <?= e($action['status']) ?></span>
              </div>
              <div class="dashboard-action-buttons">
                <?php if ($action['phone_href'] !== ''): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e($action['phone_href']) ?>">Call</a>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm" href="lead_view.php?id=<?= (int)$action['lead_id'] ?>">Open</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function dashboard_render_leads(
    string $title,
    string $subtitle,
    array $leads,
    string $emptyText,
    string $class = ''
): void {
    ?>
    <section class="card dashboard-panel <?= e($class) ?>">
      <div class="dashboard-panel-head">
        <div>
          <h2><?= e($title) ?></h2>
          <p><?= e($subtitle) ?></p>
        </div>
        <span><?= count($leads) ?></span>
      </div>

      <?php if (!$leads): ?>
        <div class="dashboard-empty"><?= e($emptyText) ?></div>
      <?php else: ?>
        <div class="dashboard-lead-list">
          <?php foreach (array_slice($leads, 0, 8) as $lead): ?>
            <article class="dashboard-lead-item">
              <div>
                <strong><?= e(dashboard_lead_name($lead)) ?></strong>
                <span><?= e(dashboard_parent_name($lead)) ?> - <?= e(status_label((string)($lead['status'] ?? 'new'))) ?></span>
              </div>
              <small><?= e(fmt_date($lead['received_date'] ?? null)) ?></small>
              <div class="dashboard-action-buttons">
                <?php $phoneHref = dashboard_phone_href($lead); ?>
                <?php if ($phoneHref !== ''): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e($phoneHref) ?>">Call</a>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm" href="lead_view.php?id=<?= (int)$lead['id'] ?>">Open</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

$page_title = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="topbar">
  <div>
    <div class="eyebrow">Monthly register</div>
    <h1>Admissions overview</h1>
  </div>
  <form method="get" class="filters dashboard-month-filter">
    <div class="field dashboard-month-field">
      <label for="month">Month</label>
      <input type="month" id="month" name="month" value="<?= e($ym) ?>" onchange="this.form.submit()">
    </div>
    <div class="field dashboard-month-field">
      <label for="campaign">Campaign</label>
      <select id="campaign" name="campaign" onchange="this.form.submit()">
        <option value="all" <?= $selectedCampaign === 'all' ? 'selected' : '' ?>>All campaigns</option>
        <?php foreach ($campaignWindows as $window): ?>
          <option value="<?= e($window['key']) ?>" <?= $selectedCampaign === $window['key'] ? 'selected' : '' ?>>
            <?= e($window['label'] . ' - ' . $window['display']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dashboard-campaign-range"><?= e($campaignRangeLabel) ?></div>
  </form>
</div>

<section class="campaign-stat-board" aria-label="Campaign statistics">
  <?php foreach ($campaignStats as $stat): ?>
    <article class="campaign-stat-cell tone-<?= e($stat['tone']) ?>">
      <strong><?= (int)$stat['value'] ?></strong>
      <span><?= e($stat['label']) ?></span>
    </article>
  <?php endforeach; ?>
</section>

<div class="card visit-goal-card">
  <h2>Visit register - goal <?= MONTHLY_VISIT_GOAL ?> school visits this month</h2>
  <div class="visit-goal-main">
    <div
      class="visit-progress-ring <?= $total_visits >= $visit_goal ? 'goal-hit' : '' ?>"
      style="--visit-progress: <?= (int)$visit_progress ?>%;"
      aria-label="<?= (int)$visit_progress ?>% of monthly visit goal"
    >
      <div>
        <strong><?= (int)$total_visits ?></strong>
        <span>/ <?= (int)$visit_goal ?></span>
      </div>
    </div>

    <div class="visit-goal-copy">
      <div class="eyebrow">Visit register</div>
      <h2>Goal <?= (int)$visit_goal ?> school visits this month</h2>
      <p>
        <?= (int)$total_visits ?> visits booked so far
        <?php if ($visit_remaining > 0): ?>
          with <?= (int)$visit_remaining ?> still to go.
        <?php else: ?>
          and the monthly goal is complete.
        <?php endif; ?>
      </p>
      <div class="visit-progress-track" aria-hidden="true">
        <span style="width: <?= (int)$visit_progress ?>%;"></span>
      </div>
    </div>
  </div>
</div>

<?php dashboard_render_actions(
    "Today's Priority Actions",
    'Calls, visits, reminders, and scheduled tasks that need attention today.',
    $todayActions,
    'No priority actions scheduled for today.',
    'dashboard-panel-today'
); ?>

<div class="card visit-goal-card visit-goal-card-legacy">
  <h2>Visit register — goal <?= MONTHLY_VISIT_GOAL ?> school visits this month</h2>
  <div class="visit-goal-main">
    <div
      class="visit-progress-ring <?= $total_visits >= $visit_goal ? 'goal-hit' : '' ?>"
      style="--visit-progress: <?= (int)$visit_progress ?>%;"
      aria-label="<?= (int)$visit_progress ?>% of monthly visit goal"
    >
      <div>
        <strong><?= (int)$total_visits ?></strong>
        <span>/ <?= (int)$visit_goal ?></span>
      </div>
    </div>

    <div class="visit-goal-copy">
      <div class="eyebrow">Visit register</div>
      <h2>Goal <?= (int)$visit_goal ?> school visits this month</h2>
      <p>
        <?= (int)$total_visits ?> visits booked so far
        <?php if ($visit_remaining > 0): ?>
          with <?= (int)$visit_remaining ?> still to go.
        <?php else: ?>
          and the monthly goal is complete.
        <?php endif; ?>
      </p>
      <div class="visit-progress-track" aria-hidden="true">
        <span style="width: <?= (int)$visit_progress ?>%;"></span>
      </div>
    </div>
  </div>

  <div class="register">
    <?php for ($i = 1; $i <= MONTHLY_VISIT_GOAL; $i++): ?>
      <div class="slot <?= $i <= $total_visits ? 'filled' : '' ?> <?= ($i <= $total_visits && $total_visits >= MONTHLY_VISIT_GOAL) ? 'goal-hit' : '' ?>"><?= $i ?></div>
    <?php endfor; ?>
  </div>
  <div class="register-caption"><?= $total_visits ?> of <?= MONTHLY_VISIT_GOAL ?> visits booked so far this month — <?= max(0, MONTHLY_VISIT_GOAL - $total_visits) ?> to go.</div>
</div>

<div class="dashboard-panel-grid">
  <?php dashboard_render_actions(
      'Overdue Actions',
      'Follow-ups, reminders, or visits that already passed.',
      $overdueActions,
      'Nothing overdue. The desk is clear.',
      'dashboard-panel-overdue'
  ); ?>

  <?php dashboard_render_actions(
      'Upcoming Visits',
      'School visits booked for the next 7 days.',
      $upcomingVisits,
      'No upcoming visits in the next 7 days.',
      'dashboard-panel-upcoming'
  ); ?>
</div>

<div class="dashboard-panel-grid">
  <?php dashboard_render_leads(
      'New Leads Not Contacted',
      'Fresh leads older than yesterday with no follow-up yet.',
      $newUncontacted,
      'Every recent lead has at least one follow-up.',
      'dashboard-panel-new'
  ); ?>

  <?php dashboard_render_leads(
      'Pending Visits',
      'Interested families who still need a confirmed visit date.',
      $pendingVisits,
      'No interested families are waiting for a visit date.',
      'dashboard-panel-pending'
  ); ?>
</div>

<div class="grid grid-2 dashboard-legacy-grid">
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
