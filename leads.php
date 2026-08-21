<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$db = get_db();

function leads_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function leads_dt(?string $date, ?string $time = null): ?DateTime
{
    if (!$date) return null;
    try { return new DateTime($date . ' ' . ($time ?: '00:00:00')); }
    catch (Throwable $e) { return null; }
}


function leads_relative_time(?DateTime $dateTime, bool $future = false): string
{
    if (!$dateTime) return '—';
    $seconds = $dateTime->getTimestamp() - time();
    $isFuture = $seconds >= 0;
    $seconds = abs($seconds);
    if ($seconds < 60) $value = 'just now';
    elseif ($seconds < 3600) { $n = max(1, (int)round($seconds / 60)); $value = $n . ' minute' . ($n === 1 ? '' : 's'); }
    elseif ($seconds < 86400) { $n = max(1, (int)round($seconds / 3600)); $value = $n . ' hour' . ($n === 1 ? '' : 's'); }
    else { $n = max(1, (int)floor($seconds / 86400)); $value = $n . ' day' . ($n === 1 ? '' : 's'); }
    if ($value === 'just now') return $value;
    return $isFuture ? $value . ' remaining' : $value . ' ago';
}

function leads_followup_summary(array $followup): string
{
    foreach (['notes','followup_notes','summary','remarks','description','message'] as $key) {
        $value = trim(strip_tags((string)($followup[$key] ?? '')));
        if ($value !== '') return $value;
    }
    $outcome = normalize_parent_response($followup['outcome'] ?? 'pending');
    return parent_response_label($outcome) . '.';
}

function leads_parent_response_label_short(string $response): string
{
    $response = normalize_parent_response($response);
    return match ($response) {
        'interested' => 'Interested',
        'still_considering','pending','call_back_later','will_call_back' => 'Considering',
        'no_response','not_reached','number_not_working' => 'No Response',
        'not_interested','wrong_lead','accidental_lead','job_inquiry','rejected' => 'Not Interested',
        default => parent_response_label($response),
    };
}

function leads_parent_response_tone(string $response): string
{
    $response = normalize_parent_response($response);
    return match ($response) {
        'interested' => 'interested',
        'still_considering','pending','call_back_later','will_call_back' => 'considering',
        'no_response','not_reached','number_not_working' => 'no-response',
        default => 'not-interested',
    };
}

function leads_parent_response_filter_key(string $response): string
{
    $response = normalize_parent_response($response);

    return match ($response) {
        'interested' => 'interested',
        'still_considering', 'pending', 'call_back_later', 'will_call_back' => 'still_considering',
        'no_response', 'not_reached', 'number_not_working' => 'no_response',
        default => 'not_interested',
    };
}

function leads_unique_filter_options(array $values, callable $normalizer, callable $labeler): array
{
    $options = [];
    $seenLabels = [];

    foreach ($values as $value) {
        $normalized = $normalizer((string)$value);
        $label = trim((string)$labeler($normalized));
        $labelKey = strtolower($label);

        if (
            $normalized === ''
            || isset($options[$normalized])
            || isset($seenLabels[$labelKey])
        ) {
            continue;
        }

        $options[$normalized] = $label;
        $seenLabels[$labelKey] = true;
    }

    return $options;
}

function leads_grade_filter_key(string $grade): string
{
    $grade = strtolower(trim($grade));
    $grade = str_replace(['_', '-'], ' ', $grade);
    $grade = preg_replace('/\s+/', ' ', $grade) ?? $grade;

    if (preg_match('/(?:grade\s*)?(\d{1,2})/', $grade, $matches)) {
        $gradeNumber = (int)$matches[1];

        if ($gradeNumber >= 1 && $gradeNumber <= 11) {
            return 'grade_' . $gradeNumber;
        }
    }

    return $grade;
}

function leads_grade_filter_label(string $grade): string
{
    if (preg_match('/^grade_(\d{1,2})$/', $grade, $matches)) {
        return 'Grade ' . (int)$matches[1];
    }

    return ucwords(str_replace('_', ' ', $grade));
}

function leads_quality_insight(int $score, string $workflow, string $parentResponse, array $followups, array $inquiries): array
{
    $label = leads_quality_label($score);
    $parent = leads_parent_response_label_short($parentResponse);
    $status = leads_workflow_label($workflow);
    $followupCount = count($followups);
    $inquiryCount = count($inquiries);

    if ($score >= 80) {
        $tone = 'hot';
        $summary = "This lead is showing very strong admission intent. The parent response is {$parent}, the current stage is {$status}, and the recent activity supports a high likelihood of progressing.";
        $recommendation = 'Keep the momentum positive: confirm the next commitment clearly, send any promised information immediately, and avoid leaving the parent without an agreed next step.';
        $watchout = 'The main risk is delay. A highly engaged parent may cool down if confirmation, visit details, or requested documents are not shared on time.';
        $encouragement = 'This is a promising lead. A timely, personal follow-up can help convert the current interest into a confirmed visit or enrolment.';
    } elseif ($score >= 65) {
        $tone = 'strong';
        $summary = "This lead has a healthy level of engagement. The parent response is {$parent} and the lead has progressed to {$status}, with enough activity to show genuine interest.";
        $recommendation = 'Clarify the parent’s remaining questions and secure one specific next action, such as a visit date, document submission, or callback time.';
        $watchout = 'Avoid general follow-ups without a clear purpose. Repeating the same information can slow the decision instead of moving it forward.';
        $encouragement = 'The lead is moving in the right direction. One well-timed and relevant conversation may be enough to move it into the hot-lead stage.';
    } elseif ($score >= 45) {
        $tone = 'potential';
        $summary = "This lead still has conversion potential, but the engagement is not yet consistent. The parent response is {$parent}, while the current lead stage is {$status}.";
        $recommendation = 'Use the next contact to identify the main decision barrier—fees, timing, curriculum, transport, or another concern—and respond with one clear solution.';
        $watchout = 'Do not continue with generic reminders only. The lead needs a more relevant message connected to the parent’s actual concern.';
        $encouragement = 'There is still a realistic opportunity here. A focused follow-up that answers the right concern can meaningfully improve the quality score.';
    } elseif ($score >= 25) {
        $tone = 'low';
        $summary = "This lead currently has limited engagement. The parent response is {$parent}, the current stage is {$status}, and the available activity does not yet show a firm admission decision.";
        $recommendation = 'Try a concise, helpful follow-up with a clear question and an easy response option. Confirm whether the parent is still interested before scheduling repeated calls.';
        $watchout = 'Repeated follow-ups without a reply may reduce trust. Avoid calling too frequently or sending long messages that do not address the parent’s situation.';
        $encouragement = 'The lead is not lost. A respectful, well-timed message may reopen the conversation and help you understand the parent’s real position.';
    } else {
        $tone = 'unqualified';
        $summary = "This lead has very little verified engagement at present. The parent response is {$parent} and the current lead stage is {$status}.";
        $recommendation = 'Verify the contact details and make one final, polite qualification attempt. If there is still no relevant response, close or deprioritise the lead to protect the team’s time.';
        $watchout = 'Do not invest repeated follow-up time until the contact is verified and genuine admission interest is confirmed.';
        $encouragement = 'A clear qualification step will still add value—even if the result is closure—because it keeps the active pipeline accurate and focused.';
    }

    $activity = $followupCount === 0
        ? 'No follow-up has been recorded yet.'
        : ($followupCount === 1 ? 'One follow-up has been recorded.' : "{$followupCount} follow-ups have been recorded.");
    if ($inquiryCount > 0) $activity .= " {$inquiryCount} inquiry item" . ($inquiryCount === 1 ? ' is' : 's are') . ' available for context.';

    return compact('tone','label','summary','recommendation','watchout','encouragement','activity');
}

function leads_quality_score(
    string $workflowStatus,
    string $parentResponse,
    array $inquiries,
    array $followups,
    ?string $lastDate,
    ?string $lastTime
): int {
    $responseScores = [
        'interested'=>70,'positive'=>70,'still_considering'=>50,'pending'=>40,
        'will_call_back'=>45,'call_back_later'=>30,'no_response'=>25,
        'not_reached'=>20,'number_not_working'=>5,'not_interested'=>10,
        'rejected'=>5,'wrong_lead'=>0,'accidental_lead'=>0,'job_inquiry'=>0,
        'neutral'=>50,'negative'=>10,
    ];

    $score = $responseScores[$parentResponse] ?? 20;

    $workflowBonuses = [
        'new'=>5,'contacted'=>2,'follow_up_required'=>0,'visit_interested'=>8,
        'visit_requested'=>12,'visit_scheduled'=>15,'visited'=>20,
        'placement_test_scheduled'=>20,'placement_test_completed'=>25,
        'joined'=>100,'closed'=>0,
    ];

    if ($workflowStatus === 'joined') {
        $score = 100;
    } else {
        $score += $workflowBonuses[$workflowStatus] ?? 0;
    }

    $inquiryBonus = 0;
    foreach ($inquiries as $inquiry) {
        $inquiryBonus += match ($inquiry['inquiry_status'] ?? 'pending') {
            'possible','recommended','completed' => 4,
            'can_consider','adjustable','alternative_available','management_approval' => 2,
            'not_possible' => -15,
            default => 0,
        };
    }
    $score += max(-30, min(15, $inquiryBonus));

    $followupBonus = 0;
    foreach ($followups as $followup) {
        $followupBonus += match ($followup['followup_type'] ?? '') {
            'physical_followup' => 4,
            'phone_call','call_engagement' => 3,
            'whatsapp_admission','whatsapp_engagement','general_followup' => 1,
            default => 0,
        };
    }
    $score += min($followupBonus, 8);

    $lastContact = leads_dt($lastDate, $lastTime);
    if ($lastContact) {
        $daysInactive = intdiv(max(0, time() - $lastContact->getTimestamp()), 86400);
        if ($daysInactive >= 14) $score -= 20;
        elseif ($daysInactive >= 7) $score -= 10;
    }

    $caps = [
        'still_considering'=>65,'neutral'=>65,'pending'=>55,'will_call_back'=>55,
        'call_back_later'=>40,'no_response'=>30,'not_reached'=>25,
        'number_not_working'=>10,'not_interested'=>15,'negative'=>15,
        'rejected'=>10,'wrong_lead'=>0,'accidental_lead'=>0,'job_inquiry'=>0,
    ];
    if (isset($caps[$parentResponse])) $score = min($score, $caps[$parentResponse]);

    return max(0, min(100, $score));
}

function leads_quality_group(int $score): string
{
    return $score >= 70 ? 'high' : ($score >= 40 ? 'moderate' : 'low');
}

function leads_quality_label(int $score): string
{
    if ($score >= 80) return 'Hot Lead';
    if ($score >= 65) return 'Strong Lead';
    if ($score >= 45) return 'Potential Lead';
    if ($score >= 25) return 'Low Engagement';
    return 'Unqualified Lead';
}

function leads_quality_key(int $score): string
{
    if ($score >= 80) return 'hot';
    if ($score >= 65) return 'strong';
    if ($score >= 45) return 'potential';
    if ($score >= 25) return 'low_engagement';
    return 'unqualified';
}

function leads_workflow_label(string $status): string
{
    $status = normalize_workflow_status($status);
    $labels = [
        'new'=>'New','contacted'=>'Contacted','follow_up_required'=>'Follow-up Required',
        'visit_interested'=>'Visit Interested','visit_requested'=>'Visit Requested',
        'visit_scheduled'=>'Visit Scheduled','visited'=>'Visited',
        'placement_test_scheduled'=>'Placement Test Scheduled',
        'placement_test_completed'=>'Placement Test Completed',
        'joined'=>'Joined / Enrolled','closed'=>'Closed',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function leads_reminder_state(array $reminders): array
{
    $pending = array_values(array_filter($reminders, static fn($r) => ($r['status'] ?? 'pending') === 'pending'));
    if (!$pending) return ['class'=>'none','label'=>'','next'=>null,'count'=>0];

    usort($pending, static function ($a, $b) {
        $ad = leads_dt($a['reminder_date'] ?? null, $a['reminder_time'] ?? null);
        $bd = leads_dt($b['reminder_date'] ?? null, $b['reminder_time'] ?? null);
        return ($ad?->getTimestamp() ?? PHP_INT_MAX) <=> ($bd?->getTimestamp() ?? PHP_INT_MAX);
    });

    $next = $pending[0];
    $due = leads_dt($next['reminder_date'] ?? null, $next['reminder_time'] ?? null);
    if (!$due) return ['class'=>'future','label'=>'Upcoming','next'=>$next,'count'=>count($pending)];

    $seconds = $due->getTimestamp() - time();
    if ($seconds < 0) return ['class'=>'overdue','label'=>'Overdue','next'=>$next,'count'=>count($pending)];
    if ($seconds <= 86400) return ['class'=>'within-24','label'=>'Within 24 hours','next'=>$next,'count'=>count($pending)];
    if ($seconds <= 172800) return ['class'=>'within-48','label'=>'Within 48 hours','next'=>$next,'count'=>count($pending)];
    return ['class'=>'future','label'=>'Upcoming','next'=>$next,'count'=>count($pending)];
}

function leads_campaign_windows(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $firstDay = DateTime::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTime('first day of this month');
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

function leads_month_options(string $selectedMonth): array
{
    $base = new DateTime('first day of this month');
    $months = [];
    for ($i = -6; $i <= 3; $i++) {
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

function leads_campaign_for_date(?string $date, array $campaignWindows): string
{
    if (!$date) return '';
    foreach ($campaignWindows as $window) {
        if ($date >= $window['start'] && $date <= $window['end']) {
            return $window['label'];
        }
    }
    return '';
}

function leads_trim_text(string $value, int $width, string $suffix = '...'): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, $suffix);
    }
    return strlen($value) > $width ? substr($value, 0, max(0, $width - strlen($suffix))) . $suffix : $value;
}

function leads_visit_appointment_date(array $lead, array $followups, ?DateTime $nextFollowup): ?DateTime
{
    if (!empty($lead['visit_date'])) {
        return leads_dt($lead['visit_date']);
    }

    foreach ($followups as $followup) {
        $status = normalize_workflow_status($followup['lead_status'] ?? '');
        if ($status === 'visit_scheduled' && !empty($followup['next_action_date'])) {
            return leads_dt($followup['next_action_date'] ?? null, $followup['next_action_time'] ?? null);
        }
    }

    return $nextFollowup;
}

function leads_visit_bucket(string $workflow, string $parentResponse, ?DateTime $appointmentDate): string
{
    if (in_array($workflow, ['visited','placement_test_scheduled','placement_test_completed','joined'], true)) {
        return 'visited';
    }

    if ($workflow === 'visit_scheduled') {
        if ($appointmentDate && $appointmentDate < new DateTime('today')) {
            return 'missed_appointments';
        }
        return 'planned_visits';
    }

    if (in_array($workflow, ['visit_interested','visit_requested'], true) || $parentResponse === 'interested') {
        return 'pending_visits';
    }

    return '';
}

$today = new DateTime('today');
$selectedMonth = trim((string)($_GET['campaign_month'] ?? $today->format('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $today->format('Y-m');
}
$campaignWindows = leads_campaign_windows($selectedMonth);
$monthOptions = leads_month_options($selectedMonth);
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
$campaignRangeStart = $selectedWindow['start'] ?? $campaignWindows[0]['start'];
$campaignRangeEnd = $selectedWindow['end'] ?? $campaignWindows[count($campaignWindows) - 1]['end'];
$campaignRangeLabel = $selectedWindow
    ? $selectedWindow['label'] . ' (' . $selectedWindow['display'] . ')'
    : 'All campaigns (' . $campaignWindows[0]['display'] . ' to ' . $campaignWindows[count($campaignWindows) - 1]['display'] . ')';

$startDate = $campaignRangeStart;
$endDate = $campaignRangeEnd;
$q = trim((string)($_GET['q'] ?? ''));
$selectedVisitView = trim((string)($_GET['visit_view'] ?? ''));
$visitViewMeta = [
    'pending_visits' => [
        'label' => 'Pending Visits',
        'short' => 'Interested, no date',
        'description' => 'People who are interested to visit the school but have not fixed a date yet.',
    ],
    'planned_visits' => [
        'label' => 'Planned Visits',
        'short' => 'Booked appointments',
        'description' => 'People who already booked an appointment to visit the school.',
    ],
    'visited' => [
        'label' => 'Visited',
        'short' => 'Completed visits',
        'description' => 'People who visited the school or moved into the next admission stage.',
    ],
    'missed_appointments' => [
        'label' => 'Missed Appointments',
        'short' => 'Did not visit',
        'description' => 'People who had a scheduled visit date that has already passed without a visit update.',
    ],
];
if (!isset($visitViewMeta[$selectedVisitView])) {
    $selectedVisitView = '';
}
$selectedStatuses = $_GET['status'] ?? [];
$selectedGrades = $_GET['grade'] ?? [];
$selectedQualities = $_GET['quality'] ?? [];
$selectedParentResponses = $_GET['parent_response'] ?? [];

foreach (['selectedStatuses','selectedGrades','selectedQualities','selectedParentResponses'] as $name) {
    if (!is_array($$name)) $$name = [$$name];
    $$name = array_values(array_filter(array_map('strval', $$name)));
}
$selectedStatuses = array_values(array_unique(array_map('normalize_workflow_status', $selectedStatuses)));
$selectedParentResponses = array_values(array_unique(array_map('leads_parent_response_filter_key', $selectedParentResponses)));
$selectedQualities = array_values(array_unique($selectedQualities));
$selectedGrades = array_values(array_unique(array_map('leads_grade_filter_key', $selectedGrades)));
if ($selectedVisitView !== '') {
    $selectedStatuses = [];
}

$where = [];
$params = [];
if ($startDate !== '') { $where[] = 'l.received_date >= ?'; $params[] = $startDate; }
if ($endDate !== '') { $where[] = 'l.received_date <= ?'; $params[] = $endDate; }
if ($q !== '') {
    $where[] = '(l.parent_name LIKE ? OR l.child_name LIKE ? OR l.contact LIKE ? OR l.current_school LIKE ? OR l.grade LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like);
}

$sql = 'SELECT l.* FROM leads l';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY l.received_date DESC, l.id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rawLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leadIds = array_map(static fn($l) => (int)$l['id'], $rawLeads);
$followupsByLead = $inquiriesByLead = $remindersByLead = [];

if ($leadIds) {
    $ph = implode(',', array_fill(0, count($leadIds), '?'));

    $s = $db->prepare("SELECT * FROM follow_ups WHERE lead_id IN ($ph) ORDER BY lead_id, followup_number DESC, followup_date DESC, followup_time DESC, id DESC");
    $s->execute($leadIds);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $followupsByLead[(int)$r['lead_id']][] = $r;

    if (leads_table_exists($db, 'lead_inquiries')) {
        $s = $db->prepare("SELECT * FROM lead_inquiries WHERE lead_id IN ($ph) ORDER BY lead_id, created_at, id");
        $s->execute($leadIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $inquiriesByLead[(int)$r['lead_id']][] = $r;
    }

    if (leads_table_exists($db, 'lead_reminders')) {
        $s = $db->prepare("SELECT * FROM lead_reminders WHERE lead_id IN ($ph) ORDER BY lead_id, reminder_date, reminder_time, id");
        $s->execute($leadIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $remindersByLead[(int)$r['lead_id']][] = $r;
    }
}

$rows = [];
$availableGrades = [];
$visitViewCounts = array_fill_keys(array_keys($visitViewMeta), 0);

foreach ($rawLeads as $lead) {
    $id = (int)$lead['id'];
    $followups = $followupsByLead[$id] ?? [];
    $inquiries = $inquiriesByLead[$id] ?? [];
    $reminders = $remindersByLead[$id] ?? [];
    $latest = $followups[0] ?? null;

    $workflow = normalize_workflow_status($latest['lead_status'] ?? $lead['status'] ?? 'new');
    $parentResponse = normalize_parent_response($latest['outcome'] ?? $lead['parent_response'] ?? 'pending');
    $quality = leads_quality_score(
        $workflow, $parentResponse, $inquiries, $followups,
        $latest['followup_date'] ?? null, $latest['followup_time'] ?? null
    );
    $group = leads_quality_group($quality);
    $qualityKey = leads_quality_key($quality);
    $grade = trim((string)($lead['grade'] ?? ''));
    $gradeKey = leads_grade_filter_key($grade);
    if ($gradeKey !== '') $availableGrades[$gradeKey] = leads_grade_filter_label($gradeKey);

    $nextFollowup = null;
    foreach ($followups as $f) {
        $candidate = leads_dt($f['next_action_date'] ?? null, $f['next_action_time'] ?? null);
        if ($candidate && (!$nextFollowup || $candidate < $nextFollowup)) $nextFollowup = $candidate;
    }

    $visitAppointment = leads_visit_appointment_date($lead, $followups, $nextFollowup);
    $visitBucket = leads_visit_bucket($workflow, $parentResponse, $visitAppointment);
    if ($visitBucket !== '' && isset($visitViewCounts[$visitBucket])) {
        $visitViewCounts[$visitBucket]++;
    }
    if ($selectedVisitView !== '' && $visitBucket !== $selectedVisitView) continue;
    if ($selectedStatuses && !in_array($workflow, $selectedStatuses, true)) continue;
    if ($selectedGrades && !in_array($gradeKey, $selectedGrades, true)) continue;
    if ($selectedParentResponses && !in_array(leads_parent_response_filter_key($parentResponse), $selectedParentResponses, true)) continue;
    if ($selectedQualities
        && !in_array($qualityKey, $selectedQualities, true)
        && !in_array($group, $selectedQualities, true)
    ) continue;

    $rows[] = [
        'lead'=>$lead,'followups'=>$followups,'inquiries'=>$inquiries,'reminders'=>$reminders,
        'workflow'=>$workflow,'parent_response'=>$parentResponse,'latest_followup'=>$latest,'quality'=>$quality,'quality_group'=>$group,'quality_key'=>$qualityKey,
        'grade_key'=>$gradeKey,
        'quality_label'=>leads_quality_label($quality),
        'reminder_state'=>leads_reminder_state($reminders),
        'next_followup'=>$nextFollowup,'first_inquiry'=>$inquiries[0] ?? null,
        'visit_bucket'=>$visitBucket,'visit_appointment'=>$visitAppointment,
    ];
}
uksort(
    $availableGrades,
    static function (string $a, string $b): int {
        $aNumber = preg_match('/^grade_(\d+)$/', $a, $aMatch) ? (int)$aMatch[1] : PHP_INT_MAX;
        $bNumber = preg_match('/^grade_(\d+)$/', $b, $bMatch) ? (int)$bMatch[1] : PHP_INT_MAX;

        return $aNumber <=> $bNumber ?: strnatcasecmp($a, $b);
    }
);

$total = count($rows);
$ongoing = $scheduled = $visited = $missed = 0;
foreach ($rows as $row) {
    if (in_array($row['workflow'], ['contacted','follow_up_required','visit_interested','visit_requested','visit_scheduled','visited','placement_test_scheduled','placement_test_completed'], true)) $ongoing++;
    if ($row['workflow'] === 'visit_scheduled') $scheduled++;
    if (in_array($row['workflow'], ['visited','placement_test_scheduled','placement_test_completed','joined'], true)) $visited++;
    if ($row['reminder_state']['class'] === 'overdue') $missed++;
}


$page_title = 'Leads';
$active = 'leads';

$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 20, 50], true)) $perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$visibleRows = array_slice($rows, $offset, $perPage);
$firstVisible = $total ? $offset + 1 : 0;
$lastVisible = min($offset + $perPage, $total);

function leads_page_url(array $changes = []): string
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'leads.php' . ($query ? '?' . http_build_query($query) : '');
}

$statusFilterOptions = leads_unique_filter_options(
    [
        'new',
        'contacted',
        'follow_up_required',
        'visit_interested',
        'visit_scheduled',
        'visited',
        'placement_test_scheduled',
        'placement_test_completed',
        'joined',
        'closed',
    ],
    'normalize_workflow_status',
    'leads_workflow_label'
);

$gradeFilterOptions = [];
for ($gradeNumber = 1; $gradeNumber <= 11; $gradeNumber++) {
    $gradeKey = 'grade_' . $gradeNumber;
    $gradeFilterOptions[$gradeKey] = leads_grade_filter_label($gradeKey);
}
$gradeFilterOptions += $availableGrades;

$parentResponseFilterOptions = leads_unique_filter_options(
    [
        'interested',
        'still_considering',
        'no_response',
        'not_interested',
    ],
    'leads_parent_response_filter_key',
    'leads_parent_response_label_short'
);

require __DIR__ . '/includes/layout_top.php';
?>

<style>
.crm-leads{display:grid;gap:16px}.crm-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}.crm-head p{margin:4px 0 0;color:var(--slate);font-size:.86rem}.crm-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.crm-datebox{display:flex;align-items:center;gap:8px;padding:6px 9px;border:1px solid var(--line);border-radius:8px;background:#fff}.crm-datebox strong{font-size:.72rem}.crm-datebox input{width:130px;padding:6px;border:0;background:transparent;font-size:.76rem}.crm-presets{min-width:115px}.crm-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));padding:0;overflow:hidden}.crm-stat{display:grid;grid-template-columns:46px 1fr;gap:12px;align-items:center;padding:18px;border-right:1px solid var(--line)}.crm-stat:last-child{border-right:0}.crm-stat-icon{display:flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:14px;background:#eaf1ff;color:#145bd7;font-size:20px;font-weight:800}.crm-stat.good .crm-stat-icon{background:#e8f7ed;color:#21844b}.crm-stat.visit .crm-stat-icon{background:#fff2dd;color:#c47300}.crm-stat.done .crm-stat-icon{background:#eee9ff;color:#6841d6}.crm-stat.bad .crm-stat-icon{background:#ffe8e8;color:#cf2c2c}.crm-stat-label{display:block;color:var(--slate);font-size:.64rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.crm-stat-value{display:block;font:800 1.28rem var(--font-mono)}.crm-stat-cap{display:block;color:var(--slate);font-size:.63rem}.crm-filter{padding:18px 20px}.crm-filter-top{display:grid;grid-template-columns:minmax(230px,1.2fr) minmax(160px,.7fr) minmax(145px,.65fr) minmax(260px,1.1fr) auto;gap:14px;align-items:end}.crm-filter-top .field{margin:0}.crm-search{position:relative}.crm-search:before{content:'⌕';position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--slate);font-size:17px}.crm-search input{padding-left:34px}.crm-quality-range{display:grid;grid-template-columns:36px 1fr 36px;align-items:center;gap:8px}.crm-quality-range output{font-size:.72rem;font-weight:700}.crm-slider{position:relative;height:26px}.crm-slider:before{content:'';position:absolute;left:0;right:0;top:11px;height:4px;border-radius:999px;background:#e5e6e8}.crm-slider .fill{position:absolute;top:11px;height:4px;border-radius:999px;background:#d6a62e}.crm-slider input{position:absolute;inset:0;width:100%;height:26px;padding:0;border:0;background:transparent;pointer-events:none;appearance:none}.crm-slider input::-webkit-slider-thumb{width:18px;height:18px;border:2px solid #d6a62e;border-radius:50%;background:#fff;pointer-events:auto;appearance:none;cursor:pointer}.crm-more{min-width:110px;justify-content:center}.crm-expanded{display:none;grid-template-columns:1fr 1fr 1fr auto;gap:20px;margin-top:16px;padding-top:16px;border-top:1px solid var(--line)}.crm-expanded.open{display:grid}.crm-section+.crm-section{border-left:1px solid var(--line);padding-left:20px}.crm-section-title{display:block;margin-bottom:9px;font-size:.75rem;font-weight:700}.crm-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px}.crm-check{display:flex;align-items:center;gap:7px;margin:0;font-size:.73rem;cursor:pointer}.crm-check input{width:auto;accent-color:#d6a62e}.crm-chips{display:flex;gap:8px;flex-wrap:wrap}.crm-chip{position:relative}.crm-chip input{position:absolute;opacity:0}.crm-chip span{display:inline-flex;align-items:center;min-height:34px;padding:7px 11px;border:1px solid var(--line);border-radius:7px;background:#fff;font-size:.7rem;font-weight:700;cursor:pointer}.crm-chip.high input:checked+span{background:#eaf7ee;border-color:#72b887;color:#236b3a}.crm-chip.mid input:checked+span{background:#fff5e6;border-color:#e0ad56;color:#935a06}.crm-chip.low input:checked+span{background:#fff0ee;border-color:#e58b80;color:#a34334}.crm-clear{display:flex;align-items:flex-end;justify-content:flex-end}.crm-clear a{color:#c92d2d;text-decoration:none;font-size:.72rem;font-weight:700}.crm-table-card{padding:0;overflow:hidden}.crm-scroll{overflow-x:auto}.crm-table{min-width:1160px}.crm-table th{padding-top:13px;padding-bottom:13px;font-size:.66rem}.crm-table td{vertical-align:middle}.crm-row.high td{background:#f1faf4}.crm-row.moderate td{background:#fff9ef}.crm-row.low td{background:#fff3f1}.crm-row.overdue td{box-shadow:inset 0 1px 0 #e53b36,inset 0 -1px 0 #e53b36}.crm-person strong,.crm-inquiry strong{display:block}.crm-person small,.crm-inquiry small{display:block;margin-top:2px;color:var(--slate);font-size:.7rem}.crm-inquiry{max-width:190px}.crm-quality{min-width:135px}.crm-quality-top{display:flex;justify-content:space-between;gap:8px}.crm-quality-top strong{font:700 .8rem var(--font-mono)}.crm-quality-top small{color:var(--slate);font-size:.65rem}.crm-bar{height:6px;margin-top:7px;border-radius:999px;background:rgba(27,42,74,.11);overflow:hidden}.crm-bar span{display:block;height:100%;border-radius:inherit}.crm-row.high .crm-bar span{background:#279756}.crm-row.moderate .crm-bar span{background:#e49a13}.crm-row.low .crm-bar span{background:#d52f2f}.crm-rem{text-align:center;min-width:90px}.crm-bell{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border:1px solid #d9dde2;border-radius:9px;background:#fff;color:#8d949d;cursor:pointer}.crm-bell svg{width:16px;height:16px;fill:currentColor}.crm-bell.within-24{border-color:#ea6a61;background:#fff3f2;color:#d8332d}.crm-bell.within-48{border-color:#edae4e;background:#fff8ed;color:#d37b00}.crm-bell.overdue{border-color:#c82020;background:#c82020;color:#fff;animation:bellpulse 1.6s infinite}.crm-badge{position:absolute;top:-7px;right:-7px;min-width:18px;height:18px;padding:0 4px;border:2px solid #fff;border-radius:999px;background:#9099a3;color:#fff;font:800 9px/14px var(--font-mono)}.crm-bell.within-24 .crm-badge,.crm-bell.overdue .crm-badge{background:#c72020}.crm-bell.within-48 .crm-badge{background:#dd8300}.crm-remdate{display:block;margin-top:5px;color:var(--slate);font-size:.61rem}.crm-remdate.overdue{color:#c12626;font-weight:700}@keyframes bellpulse{50%{box-shadow:0 0 0 7px rgba(200,32,32,0)}}.crm-next strong{display:block;font-size:.76rem}.crm-next small{display:block;margin-top:2px;color:var(--slate);font-size:.65rem}.crm-next .overdue{color:#c12626;font-weight:700}.crm-eye{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink)}.crm-eye svg{width:17px;height:17px;fill:currentColor}.crm-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--line);background:#fff}.crm-pageinfo{color:var(--slate);font-size:.7rem}.crm-pages{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.crm-page{display:inline-flex;align-items:center;justify-content:center;min-width:31px;height:31px;padding:0 8px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink);text-decoration:none;font-size:.72rem}.crm-page.active{background:var(--ink);border-color:var(--ink);color:#fff}.crm-page.disabled{opacity:.4;pointer-events:none}.crm-perpage{min-width:92px;padding:6px 8px;font-size:.72rem}.crm-modal{display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;padding:20px}.crm-modal.open{display:flex}.crm-backdrop{position:absolute;inset:0;background:rgba(15,28,50,.62);backdrop-filter:blur(3px)}.crm-dialog{position:relative;z-index:1;width:min(650px,calc(100vw - 32px));max-height:calc(100vh - 40px);overflow:auto;padding:22px;border:1px solid var(--line);border-radius:16px;background:var(--paper);box-shadow:0 26px 80px rgba(15,28,50,.3)}.crm-modalhead{display:flex;justify-content:space-between;gap:14px}.crm-close{width:34px;height:34px;border:1px solid var(--line);border-radius:50%;background:#fff;cursor:pointer}.crm-reminders{display:grid;gap:10px;margin-top:15px}.crm-remitem{padding:13px;border:1px solid var(--line);border-left:4px solid #8f98a4;border-radius:10px;background:#fff}.crm-remitem.within-24,.crm-remitem.overdue{border-left-color:#c82020}.crm-remitem.within-48{border-left-color:#df8500}.crm-remitem h3{margin:0;font-size:.9rem}.crm-remmeta{margin-top:5px;color:var(--slate);font-size:.72rem}.crm-remnote{margin-top:8px;font-size:.78rem}.crm-modalfoot{display:flex;justify-content:flex-end;gap:9px;margin-top:15px}@media(max-width:1200px){.crm-stats{grid-template-columns:repeat(3,1fr)}.crm-filter-top{grid-template-columns:repeat(2,1fr)}.crm-expanded{grid-template-columns:repeat(2,1fr)}.crm-section+.crm-section{border-left:0;padding-left:0}}@media(max-width:760px){.crm-stats,.crm-filter-top,.crm-expanded{grid-template-columns:1fr}.crm-stat{border-right:0}.crm-actions,.crm-datebox{width:100%}.crm-datebox{flex-wrap:wrap}.crm-datebox input{width:100%}.crm-checks{grid-template-columns:1fr}}

/* ============================================================
   UPDATE 7 — COMPACT FACEBOOK-STYLE DATE RANGE CONTROL
   ============================================================ */
.crm-head{align-items:start!important;margin-bottom:22px!important}
.crm-actions{display:grid!important;grid-template-columns:auto minmax(280px,auto) 132px!important;grid-template-areas:"add dates preset"!important;align-items:center!important;justify-content:end!important;gap:10px!important;min-width:0!important}
.crm-add-lead{grid-area:add!important;height:42px!important;justify-content:center!important;white-space:nowrap!important}
.crm-date-picker{grid-area:dates!important;position:relative!important;min-width:0!important}
.crm-date-trigger{display:flex!important;align-items:center!important;gap:10px!important;width:100%!important;min-width:280px!important;height:42px!important;padding:0 13px!important;border:1px solid #aeb9c8!important;border-radius:7px!important;background:#fff!important;color:var(--ink)!important;font-family:var(--font-body)!important;font-size:.84rem!important;font-weight:600!important;cursor:pointer!important;box-shadow:none!important}
.crm-date-trigger:hover{border-color:#8795a8!important;background:#fdfdfd!important}
.crm-date-trigger:focus-visible{outline:2px solid var(--brass)!important;outline-offset:2px!important}
.crm-date-calendar{width:18px!important;height:18px!important;flex:0 0 18px!important;fill:currentColor!important}
.crm-date-trigger span{flex:1!important;text-align:left!important;white-space:nowrap!important}
.crm-date-chevron{width:18px!important;height:18px!important;flex:0 0 18px!important;fill:currentColor!important;transition:transform .18s ease!important}
.crm-date-trigger[aria-expanded="true"] .crm-date-chevron{transform:rotate(180deg)!important}
.crm-date-popover{position:absolute!important;right:0!important;top:calc(100% + 8px)!important;z-index:3000!important;display:grid!important;grid-template-columns:repeat(2,minmax(145px,1fr))!important;gap:12px!important;width:340px!important;padding:14px!important;border:1px solid var(--line)!important;border-radius:11px!important;background:#fff!important;box-shadow:0 18px 48px rgba(17,35,64,.18)!important}
.crm-date-popover[hidden]{display:none!important}
.crm-date-popover label{margin:0!important;color:var(--ink)!important;font-size:.72rem!important;font-weight:700!important}
.crm-date-popover label span{display:block!important;margin-bottom:6px!important}
.crm-date-popover input{height:39px!important;padding:7px 9px!important;border:1px solid var(--line)!important;border-radius:7px!important;background:#fff!important;font-size:.78rem!important}
.crm-presets{grid-area:preset!important;width:132px!important;min-width:132px!important;height:42px!important;padding:0 34px 0 12px!important;border:1px solid var(--line)!important;border-radius:7px!important;background-color:#fff!important;font-size:.8rem!important}
.crm-stats{margin-top:2px!important}
@media(max-width:1050px){.crm-actions{grid-template-columns:auto minmax(260px,1fr) 125px!important}.crm-date-trigger{min-width:260px!important}}
@media(max-width:820px){.crm-head{grid-template-columns:1fr!important}.crm-actions{width:100%!important;grid-template-columns:1fr 125px!important;grid-template-areas:"add add" "dates preset"!important;justify-content:stretch!important}.crm-add-lead{justify-self:end!important}.crm-date-trigger{min-width:0!important}.crm-date-popover{left:0!important;right:auto!important}}
@media(max-width:560px){.crm-actions{grid-template-columns:1fr!important;grid-template-areas:"add" "dates" "preset"!important}.crm-add-lead{justify-self:stretch!important}.crm-presets{width:100%!important}.crm-date-popover{width:min(340px,calc(100vw - 40px))!important;grid-template-columns:1fr!important}.crm-date-trigger span{font-size:.76rem!important}}

</style>
<style>

/* ============================================================
   LEADS REGISTER — IMAGE 1 UI MATCH (FINAL OVERRIDE)
   ============================================================ */
@media (min-width: 1281px) {
  .main { padding: 15px 22px 18px !important; }
  .crm-leads { gap: 10px !important; }
  .crm-head {
    display: grid !important;
    grid-template-columns: minmax(320px, 1fr) auto !important;
    align-items: start !important;
    gap: 24px !important;
    margin-bottom: 0 !important;
  }
  .crm-head h1 { margin: 2px 0 3px !important; font-size: 1.82rem !important; line-height: 1.05 !important; }
  .crm-head p { margin: 0 !important; font-size: .78rem !important; }
  .crm-head .eyebrow { font-size: .69rem !important; letter-spacing: .12em !important; }
  .crm-actions {
    display: grid !important;
    grid-template-columns: minmax(360px, auto) 132px !important;
    grid-template-areas: "add add" "dates preset" !important;
    justify-content: end !important;
    align-items: center !important;
    gap: 10px 10px !important;
    min-width: 510px !important;
  }
  .crm-actions .btn-primary { grid-area: add !important; justify-self: end !important; min-width: 112px !important; height: 38px !important; justify-content: center !important; }
  .crm-datebox { grid-area: dates !important; width: 100% !important; min-width: 360px !important; height: 38px !important; padding: 4px 10px !important; gap: 6px !important; }
  .crm-datebox input { width: 118px !important; padding: 4px 5px !important; }
  .crm-presets { grid-area: preset !important; width: 132px !important; min-width: 132px !important; height: 38px !important; }

  .crm-stats { min-height: 112px !important; border-radius: 12px !important; }
  .crm-stat { grid-template-columns: 48px minmax(0,1fr) !important; gap: 12px !important; padding: 15px 19px !important; }
  .crm-stat-icon { width: 48px !important; height: 48px !important; border-radius: 16px !important; }
  .crm-stat-label { font-size: .62rem !important; }
  .crm-stat-value { margin-top: 2px !important; font-size: 1.18rem !important; }
  .crm-stat-cap { margin-top: 3px !important; font-size: .62rem !important; }

  .crm-filter { padding: 15px 19px 13px !important; border-radius: 12px !important; }
  .crm-filter-top { grid-template-columns: minmax(330px,1.35fr) minmax(380px,1.25fr) 132px !important; gap: 18px !important; }
  .crm-filter-top label { margin-bottom: 5px !important; font-size: .7rem !important; color: var(--ink) !important; }
  .crm-filter-top input, .crm-filter-top select, .crm-more { height: 34px !important; padding-top: 6px !important; padding-bottom: 6px !important; }
  .crm-expanded.open { display: grid !important; grid-template-columns: 1.2fr 1fr 1.15fr !important; gap: 0 !important; margin-top: 14px !important; padding-top: 14px !important; }
  .crm-checks { gap: 5px 12px !important; }
  .crm-check { font-size: .7rem !important; }
  .crm-chip span { min-height: 32px !important; padding: 6px 11px !important; }

  .crm-table-card { border-radius: 12px !important; }
  .crm-table { min-width: 1080px !important; table-layout: fixed !important; }
  .crm-table th { padding: 10px 12px !important; font-size: .61rem !important; }
  .crm-table td { padding: 9px 12px !important; font-size: .75rem !important; }
  .crm-table th:nth-child(1){width:9%}.crm-table th:nth-child(2){width:12%}.crm-table th:nth-child(3){width:14%}.crm-table th:nth-child(4){width:8%}.crm-table th:nth-child(5){width:12%}.crm-table th:nth-child(6){width:13%}.crm-table th:nth-child(7){width:10%}.crm-table th:nth-child(8){width:11%}.crm-table th:nth-child(9){width:7%}.crm-table th:nth-child(10){width:5%}
  .crm-person small,.crm-inquiry small { font-size: .65rem !important; }
  .crm-quality-top small { display: none !important; }
  .crm-row.high td { background: #eef9f2 !important; }
  .crm-row.moderate td { background: #fff8eb !important; }
  .crm-row.low td { background: #fff0ee !important; }
  .crm-bell,.crm-eye { width: 34px !important; height: 34px !important; }
  .crm-pagination { min-height: 50px !important; padding: 8px 16px !important; }
}

@media (max-width: 1280px) {
  .crm-expanded.open { display: grid; }
}

</style>

<style>

/* ============================================================
   LEADS FILTER WORKSPACE V4 — ACTIVE TAGS + SAVED/PINNED SETS
   ============================================================ */
.crm-filter-top-v4{display:grid!important;grid-template-columns:minmax(250px,.82fr) minmax(560px,1.65fr) auto!important;gap:16px!important;align-items:end!important}.crm-search-field{min-width:0}.crm-search-v3{position:relative}.crm-search-v3 svg{position:absolute;left:13px;top:50%;width:17px;height:17px;transform:translateY(-50%);fill:var(--slate);pointer-events:none}.crm-search-v3 input{height:42px!important;padding:9px 38px 9px 42px!important;border:1px solid var(--line)!important;border-radius:10px!important;background:#fff!important}.crm-search-v3 input:focus{border-color:#b8944d!important;box-shadow:0 0 0 3px rgba(184,148,77,.12)}.crm-search-clear{position:absolute;right:8px;top:50%;width:28px;height:28px;transform:translateY(-50%);border:0;background:transparent;color:var(--slate);font-size:19px;cursor:pointer}.crm-quality-pills{display:grid!important;grid-template-columns:repeat(5,minmax(88px,1fr))!important;gap:8px!important}.crm-quality-pill{position:relative;margin:0!important}.crm-quality-pill input{position:absolute;opacity:0;pointer-events:none}.crm-quality-pill span{display:flex;min-height:42px;flex-direction:column;align-items:center;justify-content:center;padding:6px 8px;border:1px solid #d9dde4;border-radius:9px;background:#fff;text-align:center;cursor:pointer;transition:.18s}.crm-quality-pill b{font-size:.72rem;line-height:1.1;white-space:nowrap}.crm-quality-pill small{margin-top:2px;font-size:.6rem;color:var(--slate)}.crm-quality-pill.hot input:checked+span,.crm-quality-pill.strong input:checked+span{border-color:#79c493;background:#edf9f1;color:#16703a}.crm-quality-pill.potential input:checked+span{border-color:#e3b459;background:#fff7e8;color:#9a6300}.crm-quality-pill.low input:checked+span{border-color:#eea45f;background:#fff4ea;color:#b85b00}.crm-quality-pill.unqualified input:checked+span{border-color:#ea8c83;background:#fff0ee;color:#b9342d}.crm-top-filter-actions{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;white-space:nowrap}.crm-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;padding:0;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);cursor:pointer}.crm-icon-btn svg{width:18px;height:18px;fill:currentColor}.crm-icon-btn:hover,.crm-icon-btn.is-open{background:var(--paper-2)}.crm-top-filter-actions .btn{height:42px!important;padding:9px 17px!important}.crm-apply{min-width:82px}.crm-reset{min-width:88px;justify-content:center}.crm-pin-main{flex:0 0 42px}.crm-active-filters{display:none;align-items:center;gap:10px;margin-top:14px;padding:11px 0 0;border-top:1px solid var(--line)}.crm-active-filters.has-filters{display:flex}.crm-active-label{flex:0 0 auto;font-size:.72rem;font-weight:700}.crm-active-tags{display:flex;align-items:center;gap:7px;flex:1;min-width:0;flex-wrap:wrap}.crm-active-tag{display:inline-flex;align-items:center;gap:8px;min-height:30px;padding:5px 9px;border:1px solid #cddbf2;border-radius:7px;background:#eef5ff;color:#1e5eb6;font:600 .68rem var(--font-body);cursor:pointer}.crm-active-tag.grade{border-color:#cfddf6;background:#f1f6ff}.crm-active-tag.quality{border-color:#efd5a3;background:#fff7e9;color:#9c6500}.crm-active-tag.search{border-color:#d7dbe2;background:#f4f5f7;color:#4c5665}.crm-active-tag b{font-size:14px;line-height:1}.crm-clear-all{flex:0 0 auto;color:#cf2929;text-decoration:none;font-size:.7rem;font-weight:700}.crm-expanded-v4{display:none!important;margin-top:12px!important;padding:16px!important;border:1px solid var(--line)!important;border-radius:10px!important;background:#fff!important;grid-template-columns:1.05fr 1fr 1.05fr!important;gap:0!important}.crm-expanded-v4.open{display:grid!important}.crm-expanded-v4>.crm-section,.crm-saved-panel{padding:0 20px}.crm-expanded-v4>.crm-section:first-child{padding-left:0}.crm-saved-panel{border-left:1px solid var(--line);padding-right:0}.crm-saved-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.crm-save-link{border:0;background:transparent;color:#145bd7;font-size:.66rem;font-weight:700;cursor:pointer}.crm-saved-list{display:grid;gap:7px;margin-top:8px}.crm-saved-empty{padding:14px;border:1px dashed var(--line);border-radius:8px;color:var(--slate);font-size:.7rem;text-align:center}.crm-saved-item{display:grid;grid-template-columns:minmax(0,1fr) 32px 28px;align-items:center;border:1px solid var(--line);border-radius:8px;background:#fff;overflow:hidden}.crm-saved-item.is-pinned{border-color:#7ba9e8;background:#f3f7ff}.crm-saved-apply{min-width:0;padding:9px 10px;border:0;background:transparent;text-align:left;cursor:pointer}.crm-saved-apply strong,.crm-saved-apply small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.crm-saved-apply strong{font-size:.72rem;color:var(--ink)}.crm-saved-apply small{margin-top:2px;font-size:.61rem;color:var(--slate)}.crm-saved-pin,.crm-saved-delete{display:flex;align-items:center;justify-content:center;width:100%;height:34px;border:0;background:transparent;color:#6d7683;cursor:pointer}.crm-saved-pin svg{width:16px;height:16px;fill:currentColor}.crm-saved-item.is-pinned .crm-saved-pin{color:#1968d8}.crm-saved-delete{font-size:17px}.crm-saved-pin:hover,.crm-saved-delete:hover{background:rgba(27,42,74,.06)}
@media(max-width:1450px){.crm-filter-top-v4{grid-template-columns:minmax(220px,.75fr) minmax(500px,1.55fr) auto!important}.crm-quality-pill b{font-size:.67rem}.crm-top-filter-actions .btn{padding-left:12px!important;padding-right:12px!important}}
@media(max-width:1180px){.crm-filter-top-v4{grid-template-columns:1fr!important}.crm-top-filter-actions{justify-content:flex-start!important}.crm-expanded-v4.open{grid-template-columns:1fr 1fr!important}.crm-saved-panel{grid-column:1/-1;border-left:0;border-top:1px solid var(--line);margin-top:16px;padding:16px 0 0}}
@media(max-width:720px){.crm-quality-pills{grid-template-columns:repeat(2,1fr)!important}.crm-top-filter-actions{flex-wrap:wrap}.crm-active-filters.has-filters{align-items:flex-start;flex-wrap:wrap}.crm-expanded-v4.open{grid-template-columns:1fr!important}.crm-expanded-v4>.crm-section,.crm-saved-panel{padding:14px 0;border-left:0;border-top:1px solid var(--line)}.crm-expanded-v4>.crm-section:first-child{padding-top:0;border-top:0}.crm-saved-panel{grid-column:auto}.crm-clear-all{margin-left:auto}}

/* LEADS FILTER POLISH V5 — aligned controls, instant filtering, in-app alerts */
.crm-filter-top-v4{grid-template-columns:minmax(260px,.78fr) minmax(520px,1.58fr) auto!important;align-items:center!important}
.crm-search-field,.crm-quality-field{margin:0!important;padding:0!important;border:0!important;background:transparent!important;box-shadow:none!important;min-width:0}
.crm-search-v3{height:46px!important;border:0!important;border-radius:10px!important;background:transparent!important;box-shadow:none!important;padding:0!important}
.crm-search-v3 input{display:block!important;width:100%!important;height:46px!important;margin:0!important;border:1px solid var(--line)!important;border-radius:10px!important;background:#fff!important;box-shadow:none!important;appearance:none!important}
.crm-search-v3 input:hover{border-color:#cbbfaa!important}
.crm-quality-pills{height:46px!important;align-items:stretch!important}
.crm-quality-pill span{height:46px!important;min-height:46px!important}
.crm-top-filter-actions{height:46px!important;align-items:stretch!important}
.crm-icon-btn,.crm-top-filter-actions .btn{height:46px!important;min-height:46px!important}
.crm-filter.is-submitting{opacity:.72;pointer-events:none}
.crm-filter.is-submitting::after{content:"";position:absolute;inset:0;border-radius:inherit;background:rgba(255,255,255,.15)}
.crm-filter{position:relative}
.crm-toast{position:fixed;right:24px;top:24px;z-index:120000;display:flex;align-items:center;gap:10px;max-width:min(390px,calc(100vw - 32px));padding:12px 14px;border:1px solid #c9dfd1;border-radius:11px;background:#f1faf4;color:#205c36;box-shadow:0 14px 38px rgba(20,39,67,.2);font-size:.82rem;font-weight:600;opacity:0;transform:translateY(-12px);pointer-events:none;transition:.22s ease}
.crm-toast.show{opacity:1;transform:translateY(0);pointer-events:auto}.crm-toast.error{border-color:#efc8c3;background:#fff2f0;color:#a43d31}.crm-toast.info{border-color:#cedaf0;background:#f2f6fd;color:#285eaa}.crm-toast-icon{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:currentColor;color:#fff;font-size:13px}.crm-toast-icon::first-letter{color:#fff}.crm-toast #crmToastText{flex:1}.crm-toast button{width:25px;height:25px;border:0;background:transparent;color:inherit;font-size:18px;cursor:pointer}
.crm-small-modal{display:none;position:fixed;inset:0;z-index:119000;align-items:center;justify-content:center;padding:18px}.crm-small-modal.open{display:flex}.crm-small-backdrop{position:absolute;inset:0;background:rgba(12,25,49,.5);backdrop-filter:blur(2px)}.crm-small-dialog{position:relative;z-index:1;width:min(420px,100%);padding:25px;border:1px solid var(--line);border-radius:15px;background:#fff;box-shadow:0 24px 70px rgba(10,24,48,.3);text-align:center}.crm-small-close{position:absolute;right:12px;top:10px;width:30px;height:30px;border:0;background:transparent;color:var(--slate);font-size:22px;cursor:pointer}.crm-small-icon{display:flex;align-items:center;justify-content:center;width:48px;height:48px;margin:0 auto 12px;border-radius:14px;background:#eef4ff;color:#195dbb;font-size:23px}.crm-small-dialog h3{margin:0 0 7px}.crm-small-dialog p{margin:0 0 15px;color:var(--slate);font-size:.82rem}.crm-small-dialog input{height:44px!important;text-align:left}.crm-small-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:17px}.crm-small-actions .btn{min-width:90px;justify-content:center}
@media(max-width:1450px){.crm-filter-top-v4{grid-template-columns:minmax(220px,.68fr) minmax(490px,1.5fr) auto!important;gap:10px!important}.crm-top-filter-actions{gap:6px!important}.crm-top-filter-actions .btn{padding-left:11px!important;padding-right:11px!important}}
@media(max-width:1180px){.crm-filter-top-v4{grid-template-columns:1fr!important;height:auto}.crm-quality-pills,.crm-top-filter-actions{height:auto!important}.crm-top-filter-actions{justify-content:flex-start!important}}
@media(max-width:600px){.crm-toast{right:16px;top:16px}.crm-small-actions{flex-direction:column-reverse}.crm-small-actions .btn{width:100%}}


/* DATE CONTROLS V8 — compact direct range + polished preset menu */
.crm-actions{grid-template-columns:auto 330px 138px!important;grid-template-areas:"add dates preset"!important;gap:12px!important;align-items:center!important}
.crm-date-inline{grid-area:dates!important;display:flex!important;align-items:center!important;width:330px!important;height:42px!important;padding:0 10px!important;border:1px solid #b9c3d0!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}
.crm-date-input-wrap{position:relative!important;display:flex!important;align-items:center!important;flex:1!important;min-width:0!important}
.crm-date-input-wrap svg{width:17px!important;height:17px!important;flex:0 0 17px!important;margin-right:5px!important;fill:var(--ink)!important}
.crm-date-input-wrap input[type=date]{width:100%!important;height:40px!important;min-width:0!important;padding:0 2px!important;border:0!important;background:transparent!important;color:var(--ink)!important;font-size:.78rem!important;font-weight:600!important;box-shadow:none!important;outline:0!important}
.crm-date-input-wrap input[type=date]::-webkit-calendar-picker-indicator{cursor:pointer!important;opacity:.72!important}
.crm-date-separator{flex:0 0 auto!important;padding:0 6px!important;color:#7a8594!important;font-weight:700!important}
.crm-preset-menu{grid-area:preset!important;position:relative!important;width:138px!important}
.crm-preset-trigger{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important;width:100%!important;height:42px!important;padding:0 12px!important;border:1px solid #b9c3d0!important;border-radius:8px!important;background:#fff!important;color:var(--ink)!important;font-family:var(--font-body)!important;font-size:.78rem!important;font-weight:600!important;cursor:pointer!important}
.crm-preset-trigger:hover,.crm-preset-trigger[aria-expanded=true]{border-color:var(--brass)!important;background:#fffdf8!important}
.crm-preset-trigger svg{width:17px!important;height:17px!important;fill:currentColor!important;transition:transform .18s ease!important}
.crm-preset-trigger[aria-expanded=true] svg{transform:rotate(180deg)!important}
.crm-preset-options{position:absolute!important;right:0!important;top:calc(100% + 7px)!important;z-index:5000!important;width:166px!important;padding:6px!important;border:1px solid var(--line)!important;border-radius:10px!important;background:#fff!important;box-shadow:0 16px 40px rgba(17,35,64,.18)!important}
.crm-preset-options[hidden]{display:none!important}
.crm-preset-options button{display:flex!important;width:100%!important;min-height:36px!important;align-items:center!important;padding:8px 10px!important;border:0!important;border-radius:7px!important;background:transparent!important;color:var(--ink)!important;font-family:var(--font-body)!important;font-size:.76rem!important;text-align:left!important;cursor:pointer!important}
.crm-preset-options button:hover{background:var(--paper-2)!important}
.crm-preset-options button.is-active{background:#eef4ff!important;color:#175ebd!important;font-weight:700!important}
.crm-head{margin-bottom:6px!important}.crm-stats{margin-top:6px!important}
@media(max-width:1050px){.crm-actions{grid-template-columns:auto 310px 132px!important}.crm-date-inline{width:310px!important}.crm-preset-menu{width:132px!important}}
@media(max-width:820px){.crm-actions{width:100%!important;grid-template-columns:1fr 132px!important;grid-template-areas:"add add" "dates preset"!important}.crm-add-lead{justify-self:end!important}.crm-date-inline{width:100%!important}}
@media(max-width:560px){.crm-actions{grid-template-columns:1fr!important;grid-template-areas:"add" "dates" "preset"!important}.crm-add-lead{justify-self:stretch!important}.crm-date-inline,.crm-preset-menu{width:100%!important}.crm-preset-options{left:0!important;right:auto!important;width:100%!important}}

/* DATE CONTROLS V10 — tighter spacing and preset ranges anchored to today */
.crm-actions{grid-template-columns:auto 286px 132px!important;gap:10px!important}
.crm-date-inline{width:286px!important;height:42px!important;padding:0 7px!important}
.crm-date-input-wrap{gap:0!important}
.crm-date-input-wrap input[type=date]{height:40px!important;padding:0!important;font-size:.76rem!important}
.crm-date-input-wrap input[type=date]::-webkit-calendar-picker-indicator{margin-left:1px!important;padding:2px!important}
.crm-date-separator{padding:0 3px!important}
.crm-preset-menu{width:132px!important}
.crm-preset-trigger{padding:0 10px!important}
@media(max-width:1050px){.crm-actions{grid-template-columns:auto 276px 128px!important}.crm-date-inline{width:276px!important}.crm-preset-menu{width:128px!important}}

/* CAMPAIGN CONTROLS V1 */
.crm-actions{grid-template-columns:auto minmax(420px,520px)!important;grid-template-areas:"add campaign"!important}
.crm-campaign-controls{grid-area:campaign!important;display:grid!important;grid-template-columns:minmax(170px,.8fr) minmax(230px,1fr)!important;gap:10px!important;align-items:end!important;min-width:0!important}
.crm-campaign-field{display:grid!important;gap:5px!important;min-width:0!important}
.crm-campaign-field span{color:var(--slate)!important;font-size:.64rem!important;font-weight:800!important;letter-spacing:.04em!important;text-transform:uppercase!important}
.crm-campaign-field select{width:100%!important;height:42px!important;padding:0 34px 0 12px!important;border:1px solid rgba(142,156,178,.55)!important;border-radius:8px!important;background:rgba(255,255,255,.68)!important;color:var(--ink)!important;font-family:var(--font-body)!important;font-size:.78rem!important;font-weight:700!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.72),0 8px 24px rgba(55,79,125,.08)!important;backdrop-filter:blur(16px)!important}
.crm-campaign-field select:focus{outline:2px solid rgba(82,109,255,.25)!important;border-color:#8899e8!important}
.crm-campaign-note{grid-column:1/-1!important;margin-top:-2px!important;color:var(--slate)!important;font-size:.68rem!important;font-weight:700!important;text-align:right!important}
.crm-date-cell{white-space:nowrap!important}
.crm-campaign-badge{display:inline-flex;margin-top:5px;padding:3px 7px;border:1px solid rgba(96,116,184,.22);border-radius:999px;background:rgba(234,239,255,.82);color:#4b5ba8;font-size:.56rem;font-weight:800;line-height:1.1}
[data-theme="dark"] .crm-campaign-field select{background:rgba(27,33,58,.72)!important;border-color:rgba(175,187,214,.2)!important;color:#f5f7fb!important}
[data-theme="dark"] .crm-campaign-note{color:rgba(230,236,255,.72)!important}
[data-theme="dark"] .crm-campaign-badge{background:rgba(105,119,221,.2);border-color:rgba(177,187,255,.24);color:#d8ddff}
@media(max-width:1050px){.crm-actions{grid-template-columns:auto minmax(360px,1fr)!important}.crm-campaign-controls{grid-template-columns:minmax(150px,.8fr) minmax(210px,1fr)!important}}
@media(max-width:820px){.crm-actions{width:100%!important;grid-template-columns:1fr!important;grid-template-areas:"add" "campaign"!important}.crm-add-lead{justify-self:end!important}.crm-campaign-controls{grid-template-columns:1fr 1fr!important;width:100%!important}}
@media(max-width:560px){.crm-add-lead{justify-self:stretch!important}.crm-campaign-controls{grid-template-columns:1fr!important}.crm-campaign-note{text-align:left!important}}

/* VISIT PIPELINE OVERVIEW */
.crm-visit-workspace{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(420px,1.3fr);gap:16px;align-items:stretch;padding:16px;border:1px solid rgba(172,184,210,.42);border-radius:14px;background:linear-gradient(135deg,rgba(255,255,255,.72),rgba(238,243,255,.56));box-shadow:0 18px 42px rgba(56,76,122,.1);backdrop-filter:blur(18px)}
.crm-visit-copy{display:grid;align-content:center;gap:4px;min-width:0}
.crm-visit-copy h2{margin:0;font-size:1.18rem;line-height:1.15}
.crm-visit-copy p{margin:4px 0 0;color:var(--slate);font-size:.78rem;line-height:1.5}
.crm-visit-tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.crm-visit-tab{display:grid;grid-template-rows:auto auto 1fr;gap:4px;min-height:98px;padding:13px;border:1px solid rgba(151,164,196,.34);border-radius:10px;background:rgba(255,255,255,.62);color:var(--ink);text-decoration:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.8);transition:.18s ease}
.crm-visit-tab:hover{transform:translateY(-2px);border-color:rgba(91,111,208,.34);box-shadow:0 10px 24px rgba(67,83,130,.12)}
.crm-visit-tab.active{border-color:rgba(67,83,198,.52);background:linear-gradient(180deg,rgba(238,241,255,.92),rgba(255,255,255,.68));box-shadow:0 12px 28px rgba(77,91,181,.16)}
.crm-visit-tab span{font-size:.68rem;font-weight:850;line-height:1.25}
.crm-visit-tab strong{font:850 1.45rem var(--font-mono);line-height:1;color:#4d5bb7}
.crm-visit-tab small{color:var(--slate);font-size:.62rem;line-height:1.25}
[data-theme="dark"] .crm-visit-workspace{background:linear-gradient(135deg,rgba(27,35,62,.72),rgba(39,45,78,.52));border-color:rgba(148,164,205,.22);box-shadow:var(--shadow-card)}
[data-theme="dark"] .crm-visit-tab{background:rgba(18,26,48,.62);border-color:rgba(148,164,205,.2);color:var(--ink)}
[data-theme="dark"] .crm-visit-tab.active{background:rgba(96,108,210,.18);border-color:rgba(177,187,255,.34)}
[data-theme="dark"] .crm-visit-tab strong{color:#d8ddff}
@media(max-width:1180px){.crm-visit-workspace{grid-template-columns:1fr}.crm-visit-tabs{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.crm-visit-tabs{grid-template-columns:1fr}.crm-visit-workspace{padding:13px}}


/* ============================================================
   FINAL TABLE INTERACTION UPDATE — parent response, timeline,
   quality insight, reminder timing, and next-action popups.
   ============================================================ */
.crm-expanded-v4{grid-template-columns:1fr 1fr 1.05fr 1.1fr!important}.crm-parent-filter-section{border-left:1px solid var(--line);padding-left:20px}.crm-active-tag.parent{border-color:#d6c8ee;background:#f6f1ff;color:#6944a0}.crm-table-v12{width:100%!important;min-width:1380px!important;table-layout:fixed}.crm-table-v12 th,.crm-table-v12 td{padding:12px 10px!important}.crm-table-v12 th:nth-child(1){width:8%}.crm-table-v12 th:nth-child(2){width:12%}.crm-table-v12 th:nth-child(3){width:14%}.crm-table-v12 th:nth-child(4){width:6%}.crm-table-v12 th:nth-child(5){width:11%}.crm-table-v12 th:nth-child(6){width:13%}.crm-table-v12 th:nth-child(7){width:12%}.crm-table-v12 th:nth-child(8){width:7%}.crm-table-v12 th:nth-child(9){width:11%}.crm-table-v12 th:nth-child(10){width:3.5%}.crm-table-v12 th:nth-child(11){width:3.5%}.crm-row-v12 td{background:#fff!important;box-shadow:none!important;border-bottom:1px solid #e7e0d2}.crm-person-v12{position:relative}.crm-parent-state{display:inline-flex;margin-top:6px;padding:3px 7px;border-radius:5px;font-size:.61rem;font-weight:750;line-height:1.1}.crm-parent-state.interested{background:#e8f7ed;color:#197142;border:1px solid #c8e8d2}.crm-parent-state.considering{background:#fff4dd;color:#9b6500;border:1px solid #efd49f}.crm-parent-state.no-response{background:#eef1f4;color:#66717d;border:1px solid #d8dde2}.crm-parent-state.not-interested{background:#fff0ee;color:#b63a31;border:1px solid #efc8c3}.crm-status-hover,.crm-followup-open{position:relative;display:inline-grid;gap:4px;max-width:100%}.crm-status-hover>small{display:block;color:var(--slate);font-size:.61rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crm-cell-popover{visibility:hidden;opacity:0;position:absolute;z-index:80;left:0;bottom:calc(100% + 8px);width:260px;padding:12px;border:1px solid #d8dde4;border-radius:9px;background:#fff;box-shadow:0 12px 30px rgba(17,34,62,.16);color:var(--ink);font-style:normal;text-align:left;transition:.16s;pointer-events:none}.crm-cell-popover b,.crm-cell-popover span,.crm-cell-popover p{display:block}.crm-cell-popover span{margin-top:4px;color:var(--slate);font-size:.65rem}.crm-cell-popover p{margin:8px 0 0;font-size:.72rem;line-height:1.45}.crm-status-hover:hover .crm-cell-popover,.crm-followup-open:hover .crm-cell-popover{visibility:visible;opacity:1}.crm-followup-open,.crm-quality-badge,.crm-next-open{padding:0;border:0;background:transparent;text-align:left;cursor:pointer;font-family:inherit;color:inherit}.crm-followup-open strong,.crm-followup-open small,.crm-followup-open>span{display:block}.crm-followup-open strong{font-size:.73rem}.crm-followup-open small{margin-top:2px;color:var(--ink-soft);font-size:.64rem}.crm-followup-open>span{margin-top:4px;color:#2d67ad;font-size:.61rem;font-weight:700}.crm-quality-badge{width:100%;min-width:118px}.crm-quality-badge:hover{transform:translateY(-1px);box-shadow:0 5px 13px rgba(27,42,74,.08)}.crm-remitem-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.crm-remitem-head>span{flex:0 0 auto;padding:4px 7px;border-radius:999px;background:#eef1f4;color:#5d6875;font-size:.62rem;font-weight:700}.crm-remitem.overdue .crm-remitem-head>span,.crm-remitem.within-24 .crm-remitem-head>span{background:#ffe7e5;color:#b72c27}.crm-next-open strong,.crm-next-open small,.crm-next-open span{display:block}.crm-next-open strong{font-size:.72rem}.crm-next-open small{margin-top:2px;font-size:.64rem}.crm-next-open span{margin-top:4px;font-size:.6rem;font-weight:700}.crm-next-open.upcoming strong,.crm-next-open.upcoming small{color:#187a45}.crm-next-open.upcoming span{color:#278752}.crm-next-open.overdue strong,.crm-next-open.overdue small,.crm-next-open.overdue span{color:#c42828}.crm-dialog-wide{width:min(820px,calc(100vw - 32px))}.crm-timeline-modal-list{display:grid;gap:0;margin-top:18px;padding-left:20px;border-left:2px solid #d9c89f}.crm-timeline-modal-item{position:relative;display:grid;grid-template-columns:34px minmax(0,1fr);gap:12px;margin-left:-38px;padding:0 0 18px}.crm-timeline-number{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border:3px solid #fff;border-radius:50%;background:var(--brass);color:#fff;font:700 .68rem var(--font-mono);box-shadow:0 0 0 1px #d9c89f}.crm-timeline-modal-item>div{padding:13px;border:1px solid var(--line);border-radius:10px;background:#fff}.crm-timeline-top{display:flex;justify-content:space-between;gap:12px}.crm-timeline-top strong{font-size:.82rem}.crm-timeline-top span{color:#2d67ad;font-size:.65rem;font-weight:700}.crm-timeline-meta{margin-top:5px;color:var(--slate);font-size:.66rem}.crm-timeline-modal-item p{margin:8px 0 0;font-size:.75rem;line-height:1.5}.crm-quality-insight{margin-top:18px;padding:20px;border:1px solid var(--line);border-radius:12px;background:#fff}.crm-quality-score-large{display:flex;align-items:baseline;gap:10px;margin-bottom:12px}.crm-quality-score-large strong{font:800 2rem var(--font-mono);color:var(--ink)}.crm-quality-score-large span{font-size:.9rem;font-weight:750}.crm-quality-insight p{margin:0;color:var(--ink-soft);font-size:.82rem;line-height:1.65}.crm-next-action-card{margin-top:18px;padding:18px;border:1px solid var(--line);border-radius:12px;background:#fff}.crm-next-action-card strong,.crm-next-action-card span{display:block}.crm-next-action-card strong{font-size:1rem}.crm-next-action-card span{margin-top:5px;color:#c42828;font-size:.72rem;font-weight:700}.crm-next-action-card p{margin:13px 0 0;color:var(--slate);font-size:.8rem;line-height:1.55}
@media(max-width:1400px){.crm-expanded-v4{grid-template-columns:1fr 1fr!important}.crm-parent-filter-section{border-left:1px solid var(--line)}.crm-saved-panel{grid-column:1/-1!important;border-left:0!important;border-top:1px solid var(--line);padding-top:16px!important}}
@media(max-width:760px){.crm-expanded-v4{grid-template-columns:1fr!important}.crm-parent-filter-section{border-left:0;border-top:1px solid var(--line);padding:14px 0}.crm-modalfoot{flex-wrap:wrap}.crm-modalfoot .btn{flex:1 1 140px}}


/* FINAL INTERACTIVE POLISH V13 */
.crm-status-meta-box{display:grid;gap:3px;margin-top:6px;padding:7px 8px;border:1px solid #e3e6ea;border-radius:7px;background:#fff;color:var(--ink-soft);line-height:1.3}.crm-status-meta-box b{font-size:.62rem;color:var(--ink)}.crm-status-meta-box small{font-size:.58rem;color:var(--slate)}
.crm-remdate-v11 small{display:block;margin-top:2px;font-size:.56rem;color:inherit}
.crm-timeline-badges{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:8px}.crm-timeline-badges .tag,.crm-timeline-badges .crm-parent-state{margin:0!important;font-size:.62rem!important}.crm-timeline-meta{line-height:1.55!important}
.crm-quality-badge{padding:7px 9px!important;min-height:48px!important;border-radius:9px!important}.crm-quality-badge span{display:grid;gap:3px}.crm-quality-badge b{line-height:1.2}.crm-quality-badge small{line-height:1.1}
.crm-quality-dialog{width:min(720px,calc(100vw - 32px))}.crm-quality-dialog.tone-hot{--quality-accent:#18864a;--quality-soft:#eaf8ef}.crm-quality-dialog.tone-strong{--quality-accent:#2877b8;--quality-soft:#edf6ff}.crm-quality-dialog.tone-potential{--quality-accent:#c78000;--quality-soft:#fff6e4}.crm-quality-dialog.tone-low{--quality-accent:#d6672c;--quality-soft:#fff1e9}.crm-quality-dialog.tone-unqualified{--quality-accent:#c43b36;--quality-soft:#fff0ef}.crm-quality-insight{border-top:5px solid var(--quality-accent)!important;background:linear-gradient(180deg,var(--quality-soft),#fff 170px)!important}.crm-quality-score-large{padding:4px 0 12px;border-bottom:1px solid rgba(27,42,74,.1)}.crm-quality-score-large strong,.crm-quality-score-large span{color:var(--quality-accent)!important}.crm-insight-section{margin-top:14px;padding:14px 15px;border:1px solid #e1e5ea;border-radius:10px;background:#fff}.crm-insight-section h3{margin:0 0 7px;font-family:var(--font-body);font-size:.78rem}.crm-insight-section p{margin:0!important;font-size:.78rem!important;line-height:1.62!important}.crm-insight-section small{display:block;margin-top:8px;color:var(--slate);font-size:.68rem}.crm-insight-recommendation{border-left:4px solid #2d72c7;background:#f2f7ff}.crm-insight-recommendation h3{color:#1f5da8}.crm-insight-watchout{border-left:4px solid #da5d4a;background:#fff5f2}.crm-insight-watchout h3{color:#b43d30}.crm-insight-encouragement{margin-top:14px;padding:15px;border-radius:10px;background:var(--quality-soft);color:var(--ink)}.crm-insight-encouragement strong{display:block;color:var(--quality-accent);font-size:.8rem}.crm-insight-encouragement p{margin:6px 0 0!important;font-size:.76rem!important;line-height:1.55!important}
.crm-practical-note{display:grid;gap:6px;margin-top:14px;padding:12px 13px;border-left:4px solid #2d72c7;border-radius:8px;background:#f2f7ff}.crm-practical-note b{color:#1f5da8;font-size:.76rem}.crm-practical-note span{margin:0!important;color:var(--ink-soft)!important;font-size:.73rem!important;line-height:1.5}.crm-modalfoot .btn-brass{background:var(--brass);color:#fff}
@media(max-width:760px){.crm-quality-dialog{width:calc(100vw - 20px)}.crm-status-meta-box{min-width:145px}}

/* FINAL CAMPAIGN FILTER POSITIONING */
.crm-head .crm-actions{display:grid!important;grid-template-columns:auto minmax(420px,520px)!important;grid-template-areas:"add campaign"!important;justify-content:end!important;align-items:center!important;min-width:0!important}
.crm-head .crm-campaign-controls{grid-area:campaign!important}
.crm-head .crm-add-lead{grid-area:add!important;align-self:start!important;margin-top:21px!important;height:42px!important}
@media(min-width:1281px){.crm-head .crm-actions{grid-template-columns:auto minmax(440px,540px)!important;grid-template-areas:"add campaign"!important;min-width:0!important}.crm-head .crm-actions .btn-primary{grid-area:add!important;align-self:start!important;margin-top:21px!important}.crm-head .crm-campaign-controls{grid-area:campaign!important}}
@media(max-width:820px){.crm-head .crm-actions{width:100%!important;grid-template-columns:1fr!important;grid-template-areas:"add" "campaign"!important}.crm-head .crm-add-lead{justify-self:end!important;margin-top:0!important}.crm-head .crm-campaign-controls{grid-template-columns:1fr 1fr!important}}
@media(max-width:560px){.crm-head .crm-add-lead{justify-self:stretch!important}.crm-head .crm-campaign-controls{grid-template-columns:1fr!important}}

/* FINAL FILTER SIMPLIFICATION */
.crm-filter-top-v4{grid-template-columns:minmax(260px,1fr) auto!important}
.crm-top-filter-actions{justify-content:flex-end!important}
.crm-expanded-v4.open{grid-template-columns:1fr .85fr 1fr 1.05fr!important}
.crm-expanded-v4>.crm-section{border-left:1px solid var(--line);padding:0 18px!important}
.crm-expanded-v4>.crm-section:first-child{border-left:0!important;padding-left:0!important}
.crm-quality-checks{grid-template-columns:1fr!important;gap:8px!important}
.crm-quality-check span{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;width:100%}
.crm-quality-check b{font-size:.72rem;line-height:1.2}
.crm-quality-check small{color:var(--slate);font-size:.62rem;font-weight:700}
@media(max-width:1180px){.crm-filter-top-v4{grid-template-columns:1fr!important}.crm-top-filter-actions{justify-content:flex-start!important}.crm-expanded-v4.open{grid-template-columns:1fr 1fr!important}.crm-expanded-v4>.crm-section{border-left:0!important;padding:14px 0!important;border-top:1px solid var(--line)}.crm-expanded-v4>.crm-section:first-child{border-top:0!important;padding-top:0!important}}
@media(max-width:720px){.crm-expanded-v4.open{grid-template-columns:1fr!important}.crm-quality-check span{grid-template-columns:1fr}}
</style>

<div class="crm-leads">
  <header class="crm-head">
    <div><div class="eyebrow">Full register</div><h1>All leads</h1><p>Manage and track all admission leads in one place.</p></div>
    <div class="crm-actions">
      <?php if (is_admin()): ?><a href="lead_form.php" class="btn btn-primary crm-add-lead">+ Add lead</a><?php endif; ?>
      <div class="crm-campaign-controls" aria-label="Campaign filters">
        <label class="crm-campaign-field">
          <span>Month</span>
          <select id="topCampaignMonth" aria-label="Campaign month">
            <?php foreach ($monthOptions as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $selectedMonth === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="crm-campaign-field">
          <span>Campaign</span>
          <select id="topCampaign" aria-label="Ad campaign">
            <option value="all" <?= $selectedCampaign === 'all' ? 'selected' : '' ?>>All campaigns</option>
            <?php foreach ($campaignWindows as $window): ?>
              <option value="<?= e($window['key']) ?>" <?= $selectedCampaign === $window['key'] ? 'selected' : '' ?>>
                <?= e($window['label'] . ' (' . $window['display'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="crm-campaign-note"><?= e($campaignRangeLabel) ?> · <?= e(fmt_date($startDate)) ?> to <?= e(fmt_date($endDate)) ?></div>
      </div>
    </div>
  </header>

  <section class="crm-visit-workspace" aria-label="Visit pipeline overview">
    <div class="crm-visit-copy">
      <div class="eyebrow">Visit view</div>
      <h2><?= e($selectedVisitView !== '' ? $visitViewMeta[$selectedVisitView]['label'] : 'Visit Pipeline') ?></h2>
      <p><?= e($selectedVisitView !== '' ? $visitViewMeta[$selectedVisitView]['description'] : 'Use these views to understand who needs a visit date, who is expected at school, who already visited, and who missed an appointment.') ?></p>
    </div>
    <div class="crm-visit-tabs">
      <?php foreach ($visitViewMeta as $key => $meta): ?>
        <a href="<?= e(leads_page_url(['visit_view'=>$key,'page'=>1])) ?>" class="crm-visit-tab <?= $selectedVisitView === $key ? 'active' : '' ?>">
          <span><?= e($meta['label']) ?></span>
          <strong><?= (int)($visitViewCounts[$key] ?? 0) ?></strong>
          <small><?= e($meta['short']) ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <form method="get" id="crmForm" class="card crm-filter">
    <input type="hidden" name="campaign_month" id="campaign_month" value="<?= e($selectedMonth) ?>">
    <input type="hidden" name="campaign" id="campaign" value="<?= e($selectedCampaign) ?>">
    <input type="hidden" name="visit_view" id="visit_view" value="<?= e($selectedVisitView) ?>">
    <input type="hidden" name="start_date" id="start_date" value="<?= e($startDate) ?>">
    <input type="hidden" name="end_date" id="end_date" value="<?= e($endDate) ?>">
    <input type="hidden" name="page" id="pageInput" value="<?= (int)$page ?>">

    <div class="crm-filter-top crm-filter-top-v4">
      <div class="field crm-search-field">
        <div class="crm-search-v3">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 20.3-4.7-4.7a7.7 7.7 0 1 0-.7.7l4.7 4.7.7-.7ZM4.8 10.5a5.7 5.7 0 1 1 11.4 0 5.7 5.7 0 0 1-11.4 0Z"/></svg>
          <input id="q" name="q" value="<?= e($q) ?>" placeholder="Search name, parent, contact..." autocomplete="off">
          <?php if ($q !== ''): ?><button type="button" class="crm-search-clear" id="clearSearch" aria-label="Clear search">×</button><?php endif; ?>
        </div>
      </div>

      <div class="crm-top-filter-actions">
        <button type="button" id="moreFilters" class="crm-icon-btn crm-filter-toggle" aria-expanded="false" aria-controls="expanded" title="More filters" aria-label="More filters">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg>
        </button>
        <button type="submit" class="btn btn-primary crm-apply">Apply</button>
        <a class="btn btn-outline crm-reset" href="leads.php" title="Reset all filters">↻ Reset</a>
        <button type="button" id="saveCurrentFilter" class="crm-icon-btn crm-pin-main" title="Save current filters" aria-label="Save current filters">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h8l1 6 3 3v2h-7v7l-1 1-1-1v-7H4v-2l3-3 1-6Zm2 2-.8 5-.2.5L7.5 12h9L15 10.5l-.2-.5L14 5h-4Z"/></svg>
        </button>
      </div>
    </div>

    <?php
      $qualityNames = [
        'hot'=>'Hot Lead','strong'=>'Strong Lead','potential'=>'Potential Lead',
        'low_engagement'=>'Low Engagement','unqualified'=>'Unqualified Lead',
        'high'=>'High Quality','moderate'=>'Moderate Quality','low'=>'Low Quality'
      ];
      $hasActiveFilters = $selectedVisitView !== '' || $q !== '' || $selectedStatuses || $selectedGrades || $selectedQualities || $selectedParentResponses;
    ?>
    <div class="crm-active-filters <?= $hasActiveFilters ? 'has-filters' : '' ?>" id="activeFilters">
      <span class="crm-active-label">Active filters</span>
      <div class="crm-active-tags">
        <?php if ($q !== ''): ?><button type="button" class="crm-active-tag search" data-filter-name="q" data-filter-value=""><span>Search: <?= e($q) ?></span><b>×</b></button><?php endif; ?>
        <?php if ($selectedVisitView !== ''): ?><button type="button" class="crm-active-tag status" data-filter-name="visit_view" data-filter-value=""><span>View: <?= e($visitViewMeta[$selectedVisitView]['label']) ?></span><b>×</b></button><?php endif; ?>
        <?php foreach ($selectedStatuses as $value): ?><button type="button" class="crm-active-tag status" data-filter-name="status[]" data-filter-value="<?= e($value) ?>"><span>Status: <?= e(leads_workflow_label($value)) ?></span><b>×</b></button><?php endforeach; ?>
        <?php foreach ($selectedParentResponses as $value): ?><button type="button" class="crm-active-tag parent" data-filter-name="parent_response[]" data-filter-value="<?= e($value) ?>"><span>Parent: <?= e(leads_parent_response_label_short($value)) ?></span><b>×</b></button><?php endforeach; ?>
        <?php foreach ($selectedGrades as $value): ?><button type="button" class="crm-active-tag grade" data-filter-name="grade[]" data-filter-value="<?= e($value) ?>"><span>Grade: <?= e(leads_grade_filter_label($value)) ?></span><b>×</b></button><?php endforeach; ?>
        <?php foreach ($selectedQualities as $value): ?><button type="button" class="crm-active-tag quality" data-filter-name="quality[]" data-filter-value="<?= e($value) ?>"><span>Quality: <?= e($qualityNames[$value] ?? ucwords(str_replace('_',' ',$value))) ?></span><b>×</b></button><?php endforeach; ?>
        <?php if (!$hasActiveFilters): ?><span class="crm-no-active">No filters selected</span><?php endif; ?>
      </div>
      <?php if ($hasActiveFilters): ?><a href="leads.php" class="crm-clear-all">Clear all</a><?php endif; ?>
    </div>

    <div id="expanded" class="crm-expanded crm-expanded-v4">
      <section class="crm-section">
        <span class="crm-section-title">Admission status</span>
        <div class="crm-checks"><?php foreach($statusFilterOptions as $v=>$label): ?><label class="crm-check"><input type="checkbox" name="status[]" value="<?= e($v) ?>" <?= in_array($v,$selectedStatuses,true)?'checked':'' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
      </section>
      <section class="crm-section">
        <span class="crm-section-title">Grade</span>
        <div class="crm-checks"><?php foreach($gradeFilterOptions as $v=>$label): ?><label class="crm-check"><input type="checkbox" name="grade[]" value="<?= e($v) ?>" <?= in_array($v,$selectedGrades,true)?'checked':'' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
      </section>
      <section class="crm-section crm-quality-filter-section">
        <span class="crm-section-title">Lead quality</span>
        <div class="crm-checks crm-quality-checks">
          <?php foreach ([
            'hot'=>['Hot Lead','80-100%'],
            'strong'=>['Strong Lead','65-79%'],
            'potential'=>['Potential Lead','45-64%'],
            'low_engagement'=>['Low Engagement','25-44%'],
            'unqualified'=>['Unqualified','0-24%'],
          ] as $key=>$meta): ?>
            <label class="crm-check crm-quality-check">
              <input type="checkbox" name="quality[]" value="<?= e($key) ?>" <?= in_array($key,$selectedQualities,true)?'checked':'' ?>>
              <span><b><?= e($meta[0]) ?></b><small><?= e($meta[1]) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="crm-section crm-parent-filter-section">
        <span class="crm-section-title">Parent response</span>
        <div class="crm-checks crm-parent-checks">
          <?php foreach ($parentResponseFilterOptions as $v=>$label): ?>
            <label class="crm-check"><input type="checkbox" name="parent_response[]" value="<?= e($v) ?>" <?= in_array($v,$selectedParentResponses,true)?'checked':'' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </section>
      <aside class="crm-saved-panel">
        <div class="crm-saved-head"><span class="crm-section-title">Saved filter sets</span><button type="button" id="saveFromPanel" class="crm-save-link">+ Save current</button></div>
        <div id="savedFilterSets" class="crm-saved-list"><div class="crm-saved-empty">No saved filter sets yet.</div></div>
      </aside>
    </div>
  </form>

  <section class="card crm-table-card crm-table-card-v12">
    <?php if(!$visibleRows): ?>
      <div class="empty-state">
        <h3><?= e($selectedVisitView !== '' ? 'No ' . strtolower($visitViewMeta[$selectedVisitView]['label']) . ' found' : 'No leads match this view') ?></h3>
        <p><?= e($selectedVisitView !== '' ? 'This section only shows leads that belong to ' . $visitViewMeta[$selectedVisitView]['label'] . ' for the selected campaign and month.' : 'Try changing the campaign, month, or clearing the filters.') ?></p>
      </div>
    <?php else: ?>
    <div class="crm-scroll">
      <table class="crm-table crm-table-v12">
        <thead><tr>
          <th>Received</th><th>Child / Parent</th><th>Initial Inquiry</th><th>Grade</th><th>Contact Number</th>
          <th>Lead Status</th><th>Lead Quality</th><th>Reminder</th><th>Next Follow-up</th><th class="num">Follow-ups</th><th>View</th>
        </tr></thead>
        <tbody>
        <?php foreach($visibleRows as $row):
          $lead=$row['lead']; $state=$row['reminder_state']; $next=$state['next']; $nf=$row['next_followup'];
          $nfOver=$nf&&$nf<new DateTime(); $qualityScore=(int)$row['quality']; $latest=$row['latest_followup'];
          $qualityTone=$qualityScore>=80?'hot':($qualityScore>=65?'strong':($qualityScore>=45?'potential':($qualityScore>=25?'low':'unqualified')));
          $reminderTone=in_array($state['class'],['overdue','within-24'],true)?'urgent':'muted'; $contact=trim((string)($lead['contact']??''));
          $latestDt=$latest?leads_dt($latest['followup_date']??null,$latest['followup_time']??null):null;
          $latestSummary=$latest?leads_followup_summary($latest):'No follow-up recorded yet.';
          $parentResponse=$row['parent_response']; $parentTone=leads_parent_response_tone($parentResponse);
          $qualityInsight=leads_quality_insight($qualityScore,$row['workflow'],$parentResponse,$row['followups'],$row['inquiries']);
          $leadCampaignLabel=leads_campaign_for_date($lead['received_date']??null,$campaignWindows);
          $payload=[];
          foreach($row['reminders'] as $r){
            if(($r['status']??'pending')!=='pending')continue;
            $d=leads_dt($r['reminder_date']??null,$r['reminder_time']??null); $sc='future';
            if($d){$sec=$d->getTimestamp()-time();if($sec<0)$sc='overdue';elseif($sec<=86400)$sc='within-24';elseif($sec<=172800)$sc='within-48';}
            $payload[]=['title'=>$r['title']??$r['reminder_title']??'Reminder','date'=>!empty($r['reminder_date'])?fmt_date($r['reminder_date']):'Date unavailable','time'=>!empty($r['reminder_time'])?date('g:i A',strtotime($r['reminder_time'])):'10:00 AM','notes'=>$r['notes']??$r['reminder_notes']??'','state'=>$sc,'relative'=>leads_relative_time($d,true)];
          }
          $timeline=[];
          foreach($row['followups'] as $f){
            $fd=leads_dt($f['followup_date']??null,$f['followup_time']??null);
            $createdRaw=$f['created_at']??$f['updated_at']??null;
            $createdText='';
            if($createdRaw){$ct=strtotime((string)$createdRaw);if($ct)$createdText=date('j M Y · g:i A',$ct);}
            $timeline[]=[
              'id'=>(int)($f['id']??0),'number'=>(int)($f['followup_number']??0),
              'date'=>!empty($f['followup_date'])?fmt_date($f['followup_date']):'Date unavailable',
              'time'=>!empty($f['followup_time'])?date('g:i A',strtotime($f['followup_time'])):'',
              'created'=>$createdText,'relative'=>leads_relative_time($fd),
              'type'=>ucwords(str_replace('_',' ',(string)($f['followup_type']??'Follow-up'))),
              'status'=>leads_workflow_label($f['lead_status']??$row['workflow']),
              'response'=>leads_parent_response_label_short($f['outcome']??'pending'),
              'statusTone'=>status_class($f['lead_status']??$row['workflow']),
              'responseTone'=>leads_parent_response_tone($f['outcome']??'pending'),
              'summary'=>leads_followup_summary($f)
            ];
          }
          $inquiryTooltip=[];
          foreach($row['inquiries'] as $inq){$title=trim((string)($inq['inquiry_title']??$inq['title']??'Initial Inquiry'));$detail=trim((string)($inq['inquiry_details']??$inq['details']??$inq['notes']??''));$inquiryTooltip[]=['title'=>$title?:'Initial Inquiry','detail'=>$detail];}
          if(!$inquiryTooltip&&!empty($lead['inquiry_notes']))$inquiryTooltip[]=['title'=>'Initial Inquiry','detail'=>trim(strip_tags((string)$lead['inquiry_notes']))];
          $statusMeta=[];
          $statusScheduleDate=''; $statusScheduleTime=''; $statusCreated='';
          if($latest){
            $statusMeta=['date'=>fmt_date($latest['followup_date']??null),'time'=>!empty($latest['followup_time'])?date('g:i A',strtotime($latest['followup_time'])):'','summary'=>$latestSummary];
            if(!empty($latest['next_action_date']))$statusScheduleDate=fmt_date($latest['next_action_date']);
            if(!empty($latest['next_action_time']))$statusScheduleTime=date('g:i A',strtotime($latest['next_action_time']));
            $createdRaw=$latest['created_at']??$latest['updated_at']??null;
            if($createdRaw){$ct=strtotime((string)$createdRaw);if($ct)$statusCreated=date('j M Y · g:i A',$ct);}
          }
        ?>
          <tr class="crm-row crm-row-v12">
            <td class="crm-date-cell"><?= e(fmt_date($lead['received_date']??null)) ?><?php if($leadCampaignLabel!==''):?><br><span class="crm-campaign-badge"><?= e($leadCampaignLabel) ?></span><?php endif;?></td>
            <td class="crm-person crm-person-v12"><strong><?= e($lead['child_name']?:'—') ?></strong><small><?= e($lead['parent_name']?:'Parent not added') ?></small><span class="crm-parent-state <?= e($parentTone) ?>"><?= e(leads_parent_response_label_short($parentResponse)) ?></span></td>
            <td class="crm-inquiry crm-inquiry-v12"><?php if($inquiryTooltip):$firstInquiry=$inquiryTooltip[0];?><button type="button" class="crm-inquiry-hover"><strong><?= e($firstInquiry['title']) ?></strong><?php if($firstInquiry['detail']!==''):?><small><?= e(leads_trim_text($firstInquiry['detail'],44,'...')) ?></small><?php endif;?><?php if(count($inquiryTooltip)>1):?><span class="crm-more-count">+<?= count($inquiryTooltip)-1 ?> more</span><?php endif;?><span class="crm-inquiry-popover" role="tooltip"><b>Initial inquiries</b><?php foreach($inquiryTooltip as $item):?><span class="crm-inquiry-popover-item"><strong><?= e($item['title']) ?></strong><?php if($item['detail']!==''):?><small><?= e($item['detail']) ?></small><?php endif;?></span><?php endforeach;?></span></button><?php else:?>—<?php endif;?></td>
            <td><?= e($lead['grade']?:'—') ?></td>
            <td class="crm-contact-cell"><?php if($contact!==''):?><a href="tel:<?= e(preg_replace('/[^0-9+]/','',$contact)) ?>" class="crm-contact-link"><svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.08.36 2.24.55 3.42.55a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.54 21 3 13.46 3 4.18a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.18.19 2.34.55 3.42a1 1 0 0 1-.25 1l-2.2 2.2Z"/></svg><span><?= e($contact) ?></span></a><?php else:?>—<?php endif;?></td>
            <td class="crm-status-cell"><span class="crm-status-hover"><span class="tag <?= e(status_class($row['workflow'])) ?>"><?= e(leads_workflow_label($row['workflow'])) ?></span><?php if($latest):?><span class="crm-status-meta-box"><b><?= e(($statusMeta['date']??'') . (($statusMeta['time']??'')?' · '.$statusMeta['time']:'')) ?></b></span><span class="crm-cell-popover"><b>Latest status update</b><span><?= e(($statusMeta['date']??'') . (($statusMeta['time']??'')?' · '.$statusMeta['time']:'')) ?></span><?php if($statusCreated!==''):?><span>Created: <?= e($statusCreated) ?></span><?php endif;?><?php if($statusScheduleDate!==''):?><span>Scheduled for <?= e($statusScheduleDate . ($statusScheduleTime!==''?' · '.$statusScheduleTime:'')) ?></span><?php endif;?><p><?= e($latestSummary) ?></p></span><?php endif;?></span></td>
            <td class="crm-quality-cell"><button type="button" class="crm-quality-badge <?= e($qualityTone) ?>" onclick='openQualityModal(<?= json_encode($lead['child_name']?:$lead['parent_name']?:'Lead',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= json_encode($row['quality_label'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= $qualityScore ?>,<?= json_encode($qualityInsight,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= (int)$lead['id'] ?>)'><i></i><span><b><?= e($row['quality_label']) ?></b><small><?= $qualityScore ?>%</small></span></button></td>
            <td class="crm-rem crm-rem-v12"><?php if($state['count']>0):?><button type="button" class="crm-bell-v11 <?= e($reminderTone) ?>" onclick='openReminder(<?= json_encode($lead['child_name']?:$lead['parent_name']?:'Lead',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= (int)$lead['id'] ?>,<?= json_encode($payload,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><svg viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.6-2V9a5.4 5.4 0 0 0-4.4-5.3V3a1 1 0 1 0-2 0v.7A5.4 5.4 0 0 0 6.6 9v5.5l-1.6 2V18h14v-1.5Z"/></svg><span class="crm-badge-v11"><?= (int)$state['count'] ?></span></button><?php if($next):?><span class="crm-remdate-v11 <?= e($reminderTone) ?>"><?= e(fmt_date($next['reminder_date']??null)) ?><?php if(!empty($next['reminder_time'])):?><small><?= e(date('g:i A',strtotime($next['reminder_time']))) ?></small><?php endif;?></span><?php endif;?><?php else:?><span class="crm-bell-empty">—</span><?php endif;?></td>
            <td class="crm-next crm-next-v12"><?php if($nf):?><button type="button" class="crm-next-open <?= $nfOver?'overdue':'upcoming' ?>" onclick='openNextFollowup(<?= json_encode($lead['child_name']?:$lead['parent_name']?:'Lead',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= (int)$lead['id'] ?>,<?= (int)($latest['id']??0) ?>,<?= json_encode($nf->format('j M Y'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= json_encode($nf->format('H:i')==='00:00'?'10:00 AM':$nf->format('g:i A'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>,<?= json_encode(leads_relative_time($nf,true),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><strong><?= e($nf->format('j M Y')) ?></strong><small><?= e($nf->format('H:i')==='00:00'?'10:00 AM':$nf->format('g:i A')) ?></small><span><?= e(leads_relative_time($nf,true)) ?></span></button><?php else:?>—<?php endif;?></td>
            <td class="num crm-followup-count"><?= count($row['followups']) ?></td>
            <td><a class="crm-eye" href="lead_view.php?id=<?= (int)$lead['id'] ?>"><svg viewBox="0 0 24 24"><path d="M12 5c-5.5 0-9.6 4.5-10.8 6a1.6 1.6 0 0 0 0 2c1.2 1.5 5.3 6 10.8 6s9.6-4.5 10.8-6a1.6 1.6 0 0 0 0-2C21.6 9.5 17.5 5 12 5Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg></a></td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <footer class="crm-pagination"><div class="crm-pageinfo">Showing <?= (int)$firstVisible ?> to <?= (int)$lastVisible ?> of <?= (int)$total ?> leads</div><div class="crm-pages"><a class="crm-page <?= $page<=1?'disabled':'' ?>" href="<?= e(leads_page_url(['page'=>max(1,$page-1)])) ?>">‹</a><?php $sp=max(1,$page-2);$ep=min($totalPages,$page+2);if($sp>1):?><a class="crm-page" href="<?= e(leads_page_url(['page'=>1])) ?>">1</a><?php if($sp>2):?><span>…</span><?php endif;endif;for($n=$sp;$n<=$ep;$n++):?><a class="crm-page <?= $n===$page?'active':'' ?>" href="<?= e(leads_page_url(['page'=>$n])) ?>"><?= $n ?></a><?php endfor;if($ep<$totalPages):if($ep<$totalPages-1):?><span>…</span><?php endif;?><a class="crm-page" href="<?= e(leads_page_url(['page'=>$totalPages])) ?>"><?= $totalPages ?></a><?php endif;?><a class="crm-page <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(leads_page_url(['page'=>min($totalPages,$page+1)])) ?>">›</a><select id="perPage" class="crm-perpage"><?php foreach([10,20,50] as $n):?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?> / page</option><?php endforeach;?></select></div></footer>
    <?php endif;?>
  </section>
</div>

<div id="crmToast" class="crm-toast" role="status" aria-live="polite" aria-hidden="true">
  <span class="crm-toast-icon" aria-hidden="true">✓</span>
  <span id="crmToastText"></span>
  <button type="button" id="crmToastClose" aria-label="Close notification">×</button>
</div>

<div id="filterDialog" class="crm-small-modal" aria-hidden="true">
  <div class="crm-small-backdrop" data-dialog-cancel></div>
  <div class="crm-small-dialog" role="dialog" aria-modal="true" aria-labelledby="filterDialogTitle">
    <button type="button" class="crm-small-close" data-dialog-cancel aria-label="Close">×</button>
    <div class="crm-small-icon" aria-hidden="true">⌖</div>
    <h3 id="filterDialogTitle">Save filter set</h3>
    <p id="filterDialogMessage">Give this filter combination a name.</p>
    <input type="text" id="filterDialogInput" maxlength="60" placeholder="My lead view">
    <div class="crm-small-actions">
      <button type="button" class="btn btn-outline" data-dialog-cancel>Cancel</button>
      <button type="button" class="btn btn-primary" id="filterDialogConfirm">Save</button>
    </div>
  </div>
</div>

<div id="reminderModal" class="crm-modal" aria-hidden="true"><div class="crm-backdrop" onclick="closeReminder()"></div><div class="crm-dialog"><div class="crm-modalhead"><div><div class="eyebrow">Read-only reminder preview</div><h2 id="reminderTitle">Reminders</h2></div><button type="button" class="crm-close" onclick="closeReminder()">×</button></div><div id="reminderList" class="crm-reminders"></div><div class="crm-modalfoot"><a id="viewLead" class="btn btn-primary" href="#">View Lead</a><button type="button" class="btn btn-outline" onclick="closeReminder()">Close</button></div></div></div>
<div id="followupTimelineModal" class="crm-modal" aria-hidden="true"><div class="crm-backdrop" onclick="closeFollowupTimeline()"></div><div class="crm-dialog crm-dialog-wide"><div class="crm-modalhead"><div><div class="eyebrow">Complete activity history</div><h2 id="followupTimelineTitle">Follow-up Timeline</h2></div><button type="button" class="crm-close" onclick="closeFollowupTimeline()">×</button></div><div id="followupTimelineList" class="crm-timeline-modal-list"></div><div class="crm-modalfoot"><a id="followupViewLead" class="btn btn-primary" href="#">View Full Lead</a><button type="button" class="btn btn-outline" onclick="closeFollowupTimeline()">Close</button></div></div></div>
<div id="qualityModal" class="crm-modal" aria-hidden="true"><div class="crm-backdrop" onclick="closeQualityModal()"></div><div id="qualityDialog" class="crm-dialog crm-quality-dialog"><div class="crm-modalhead"><div><div class="eyebrow">Lead quality insight</div><h2 id="qualityModalTitle">Lead Quality</h2></div><button type="button" class="crm-close" onclick="closeQualityModal()">×</button></div><div class="crm-quality-insight"><div id="qualityModalScore" class="crm-quality-score-large"></div><div class="crm-insight-section crm-insight-summary"><h3>Current assessment</h3><p id="qualityModalText"></p><small id="qualityModalActivity"></small></div><div class="crm-insight-section crm-insight-recommendation"><h3>Recommended next step</h3><p id="qualityModalRecommendation"></p></div><div class="crm-insight-section crm-insight-watchout"><h3>What to avoid</h3><p id="qualityModalWatchout"></p></div><div class="crm-insight-encouragement"><strong>Keep moving the conversation forward</strong><p id="qualityModalEncouragement"></p></div></div><div class="crm-modalfoot"><a id="qualityViewLead" class="btn btn-primary" href="#">View Full Lead</a><button type="button" class="btn btn-outline" onclick="closeQualityModal()">Close</button></div></div></div>
<div id="nextFollowupModal" class="crm-modal" aria-hidden="true"><div class="crm-backdrop" onclick="closeNextFollowup()"></div><div class="crm-dialog"><div class="crm-modalhead"><div><div class="eyebrow">Follow-up action</div><h2 id="nextFollowupTitle">Next Follow-up</h2></div><button type="button" class="crm-close" onclick="closeNextFollowup()">×</button></div><div class="crm-next-action-card"><strong id="nextFollowupDate"></strong><span id="nextFollowupRelative"></span><p>This follow-up needs a clear outcome. Record what happened, update the parent response and lead status, then set a realistic next action only when another follow-up is genuinely required.</p><div class="crm-practical-note"><b>Practical note</b><span>Use <em>Update Follow-up</em> to complete the scheduled action, <em>Reschedule</em> to edit the existing scheduled record, or <em>Create New Follow-up</em> when this is a separate new contact attempt.</span></div></div><div class="crm-modalfoot"><a id="completeFollowupLink" class="btn btn-primary" href="#">Update Follow-up</a><a id="newFollowupLink" class="btn btn-brass" href="#">Create New Follow-up</a><a id="rescheduleFollowupLink" class="btn btn-outline" href="#">Reschedule</a><button type="button" class="btn btn-outline" onclick="closeNextFollowup()">Close</button></div></div></div>

<script>
(function(){
  const form=document.getElementById('crmForm');
  const q=document.getElementById('q');
  const campaignMonthSelect=document.getElementById('topCampaignMonth');
  const campaignSelect=document.getElementById('topCampaign');
  const campaignMonthHidden=document.getElementById('campaign_month');
  const campaignHidden=document.getElementById('campaign');
  const visitView=document.getElementById('visit_view');
  const hs=document.getElementById('start_date');
  const he=document.getElementById('end_date');
  const more=document.getElementById('moreFilters');
  const expanded=document.getElementById('expanded');
  const pp=document.getElementById('perPage');
  const page=document.getElementById('pageInput');
  const clearSearch=document.getElementById('clearSearch');
  const activeFilters=document.getElementById('activeFilters');
  const saveButtons=[document.getElementById('saveCurrentFilter'),document.getElementById('saveFromPanel')].filter(Boolean);
  const savedList=document.getElementById('savedFilterSets');
  const STORAGE_KEY='lakelandLeadSavedFiltersV1';
  const DEFAULT_KEY='lakelandLeadDefaultFilterV1';

  function submit(){page.value='1';form.requestSubmit();}
  function fmt(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
  function parseLocalDate(value){
    if(!value)return null;
    const parts=value.split('-').map(Number);
    if(parts.length!==3||parts.some(Number.isNaN))return null;
    return new Date(parts[0],parts[1]-1,parts[2]);
  }
  function inclusiveDays(startValue,endValue){
    const start=parseLocalDate(startValue),end=parseLocalDate(endValue);
    if(!start||!end||end<start)return 0;
    return Math.round((end-start)/86400000)+1;
  }
  function setExpanded(open){
    expanded.classList.toggle('open',open);
    more.classList.toggle('is-open',open);
    more.setAttribute('aria-expanded',open?'true':'false');
  }
  function checkedValues(name){return [...form.querySelectorAll('input[name="'+name+'"]:checked')].map(x=>x.value);}
  function currentFilter(){return {q:q.value.trim(),campaignMonth:campaignMonthHidden?.value||'',campaign:campaignHidden?.value||'all',visitView:visitView?.value||'',qualities:checkedValues('quality[]'),statuses:checkedValues('status[]'),grades:checkedValues('grade[]'),parentResponses:checkedValues('parent_response[]')};}
  function readSaved(){try{return JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]')}catch(e){return []}}
  function writeSaved(items){localStorage.setItem(STORAGE_KEY,JSON.stringify(items));renderSaved();}
  function setInputValues(name,values){form.querySelectorAll('input[name="'+name+'"]').forEach(x=>x.checked=values.includes(x.value));}
  function applyFilterSet(item){q.value=item.q||'';if(campaignMonthHidden&&item.campaignMonth)campaignMonthHidden.value=item.campaignMonth;if(campaignHidden&&item.campaign)campaignHidden.value=item.campaign;if(visitView)visitView.value=item.visitView||'';setInputValues('quality[]',item.qualities||[]);setInputValues('status[]',item.statuses||[]);setInputValues('grade[]',item.grades||[]);setInputValues('parent_response[]',item.parentResponses||[]);submit();}
  let toastTimer=null;
  let dialogResolver=null;
  const toast=document.getElementById('crmToast');
  const toastText=document.getElementById('crmToastText');
  const toastClose=document.getElementById('crmToastClose');
  const filterDialog=document.getElementById('filterDialog');
  const dialogTitle=document.getElementById('filterDialogTitle');
  const dialogMessage=document.getElementById('filterDialogMessage');
  const dialogInput=document.getElementById('filterDialogInput');
  const dialogConfirm=document.getElementById('filterDialogConfirm');

  function showToast(message,type='success'){
    clearTimeout(toastTimer);
    toast.className='crm-toast show '+type;
    toastText.textContent=message;
    toast.setAttribute('aria-hidden','false');
    toastTimer=setTimeout(hideToast,3200);
  }
  function hideToast(){toast.classList.remove('show');toast.setAttribute('aria-hidden','true');}
  function openDialog({title,message,value='',confirmText='Save',showInput=true}={}){
    dialogTitle.textContent=title||'Confirm';
    dialogMessage.textContent=message||'';
    dialogInput.value=value;
    dialogInput.style.display=showInput?'block':'none';
    dialogConfirm.textContent=confirmText;
    filterDialog.classList.add('open');
    filterDialog.setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
    setTimeout(()=>showInput?dialogInput.focus():dialogConfirm.focus(),30);
    return new Promise(resolve=>{dialogResolver=resolve;});
  }
  function closeDialog(result=null){
    filterDialog.classList.remove('open');filterDialog.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');
    if(dialogResolver){const resolve=dialogResolver;dialogResolver=null;resolve(result);}
  }
  async function saveCurrent(){
    const data=currentFilter();
    if(!data.q&&!data.qualities.length&&!data.statuses.length&&!data.grades.length&&!data.parentResponses.length){showToast('Select at least one filter before saving.','error');return;}
    const name=await openDialog({title:'Save filter set',message:'Give this filter combination a name.',value:'My lead view',confirmText:'Save',showInput:true});
    if(!name||!name.trim())return;
    const items=readSaved();
    const id='filter_'+Date.now();
    items.unshift({id,name:name.trim(),pinned:false,...data});
    writeSaved(items);
    setExpanded(true);
    showToast('Filter set saved successfully.');
  }
  function summary(item){
    const bits=[];
    if(item.campaignMonth)bits.push('Month: '+item.campaignMonth);
    if(item.campaign&&item.campaign!=='all')bits.push('Campaign: '+item.campaign.replace('_',' '));
    if(item.visitView)bits.push('View: '+item.visitView.replaceAll('_',' '));
    if(item.q)bits.push('Search: '+item.q);
    if(item.qualities?.length)bits.push('Quality: '+item.qualities.length);
    if(item.statuses?.length)bits.push('Status: '+item.statuses.length);
    if(item.grades?.length)bits.push('Grade: '+item.grades.length);if(item.parentResponses?.length)bits.push('Parent: '+item.parentResponses.length);
    return bits.join(' · ')||'No filters';
  }
  function renderSaved(){
    if(!savedList)return;
    const items=readSaved();
    const defaultId=localStorage.getItem(DEFAULT_KEY)||'';
    if(!items.length){savedList.innerHTML='<div class="crm-saved-empty">No saved filter sets yet.</div>';return;}
    savedList.innerHTML=items.map(item=>'<article class="crm-saved-item '+(item.id===defaultId?'is-pinned':'')+'" data-id="'+esc(item.id)+'"><button type="button" class="crm-saved-apply"><strong>'+esc(item.name)+'</strong><small>'+esc(summary(item))+'</small></button><button type="button" class="crm-saved-pin" title="'+(item.id===defaultId?'Unpin default':'Pin as default')+'" aria-label="Pin saved filter"><svg viewBox="0 0 24 24"><path d="M8 3h8l1 6 3 3v2h-7v7l-1 1-1-1v-7H4v-2l3-3 1-6Z"/></svg></button><button type="button" class="crm-saved-delete" title="Delete saved filter" aria-label="Delete saved filter">×</button></article>').join('');
  }

  campaignMonthSelect?.addEventListener('change',()=>{
    if(campaignMonthHidden)campaignMonthHidden.value=campaignMonthSelect.value;
    if(campaignHidden)campaignHidden.value='all';
    submit();
  });
  campaignSelect?.addEventListener('change',()=>{
    if(campaignHidden)campaignHidden.value=campaignSelect.value;
    submit();
  });
  more.addEventListener('click',()=>setExpanded(!expanded.classList.contains('open')));
  if(clearSearch)clearSearch.addEventListener('click',()=>{q.value='';submit()});
  if(pp)pp.addEventListener('change',()=>{const u=new URL(location.href);u.searchParams.set('per_page',pp.value);u.searchParams.set('page','1');location.href=u});
  activeFilters?.addEventListener('click',e=>{const chip=e.target.closest('.crm-active-tag');if(!chip)return;const name=chip.dataset.filterName;const value=chip.dataset.filterValue;if(name==='q'){q.value='';}else if(name==='visit_view'&&visitView){visitView.value='';}else{const input=[...form.querySelectorAll('input[name="'+name+'"]')].find(x=>x.value===value);if(input)input.checked=false;}submit();});
  saveButtons.forEach(b=>b.addEventListener('click',saveCurrent));
  savedList?.addEventListener('click',async e=>{const itemEl=e.target.closest('.crm-saved-item');if(!itemEl)return;const id=itemEl.dataset.id;const items=readSaved();const item=items.find(x=>x.id===id);if(!item)return;if(e.target.closest('.crm-saved-apply')){applyFilterSet(item);return;}if(e.target.closest('.crm-saved-pin')){const current=localStorage.getItem(DEFAULT_KEY)||'';if(current===id){localStorage.removeItem(DEFAULT_KEY);showToast('Default filter unpinned.','info');}else{localStorage.setItem(DEFAULT_KEY,id);showToast('Filter pinned as your default view.');}renderSaved();return;}if(e.target.closest('.crm-saved-delete')){const ok=await openDialog({title:'Delete saved filter?',message:'This saved filter set will be removed permanently.',confirmText:'Delete',showInput:false});if(ok===true){writeSaved(items.filter(x=>x.id!==id));if(localStorage.getItem(DEFAULT_KEY)===id)localStorage.removeItem(DEFAULT_KEY);showToast('Saved filter deleted.','info');}}});

  toastClose?.addEventListener('click',hideToast);
  filterDialog?.querySelectorAll('[data-dialog-cancel]').forEach(el=>el.addEventListener('click',()=>closeDialog(null)));
  dialogConfirm?.addEventListener('click',()=>closeDialog(dialogInput.style.display==='none'?true:dialogInput.value.trim()));
  dialogInput?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();dialogConfirm.click();}});

  let autoTimer=null;
  const SEARCH_FOCUS_KEY='lakelandLeadSearchFocus';
  function autoSubmit(delay=180, preserveSearchFocus=false){
    clearTimeout(autoTimer);
    autoTimer=setTimeout(()=>{
      if(preserveSearchFocus){
        sessionStorage.setItem(SEARCH_FOCUS_KEY, JSON.stringify({
          value:q.value,
          cursor:q.selectionStart ?? q.value.length
        }));
      }
      form.classList.add('is-submitting');
      submit();
    },delay);
  }
  q.addEventListener('input',()=>autoSubmit(1100,true));
  form.querySelectorAll('input[name="quality[]"],input[name="status[]"],input[name="grade[]"],input[name="parent_response[]"]').forEach(input=>input.addEventListener('change',()=>autoSubmit(120)));

  renderSaved();

  // Keep the search field active after the automatic refresh so the user can
  // continue typing without clicking the box again.
  try{
    const savedSearchFocus=sessionStorage.getItem(SEARCH_FOCUS_KEY);
    if(savedSearchFocus){
      sessionStorage.removeItem(SEARCH_FOCUS_KEY);
      const state=JSON.parse(savedSearchFocus);
      requestAnimationFrame(()=>{
        q.focus({preventScroll:true});
        const position=Math.min(Number(state.cursor)||q.value.length,q.value.length);
        q.setSelectionRange(position,position);
      });
    }
  }catch(_error){sessionStorage.removeItem(SEARCH_FOCUS_KEY);}

  const url=new URL(location.href);
  const hasUserFilters=url.searchParams.has('campaign_month')||url.searchParams.has('campaign')||url.searchParams.has('visit_view')||url.searchParams.has('q')||url.searchParams.has('status[]')||url.searchParams.has('grade[]')||url.searchParams.has('quality[]')||url.searchParams.has('parent_response[]');
  const defaultId=localStorage.getItem(DEFAULT_KEY);
  if(!hasUserFilters&&defaultId&&!sessionStorage.getItem('lakelandDefaultApplied')){
    const item=readSaved().find(x=>x.id===defaultId);
    if(item){sessionStorage.setItem('lakelandDefaultApplied','1');applyFilterSet(item);}
  }
})();
function esc(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
function openReminder(name,id,items){const m=document.getElementById('reminderModal'),t=document.getElementById('reminderTitle'),l=document.getElementById('reminderList'),v=document.getElementById('viewLead');t.textContent=name+' — Reminders';v.href='lead_view.php?id='+encodeURIComponent(id);items.sort((a,b)=>({overdue:0,'within-24':1,'within-48':2,future:3}[a.state]??9)-({overdue:0,'within-24':1,'within-48':2,future:3}[b.state]??9));l.innerHTML=!items.length?'<div class="empty-state">No active reminders.</div>':items.map(r=>'<article class="crm-remitem '+esc(r.state)+'"><div class="crm-remitem-head"><h3>'+esc(r.title)+'</h3><span>'+esc(r.relative||'')+'</span></div><div class="crm-remmeta">'+esc(r.date)+(r.time?' · '+esc(r.time):'')+'</div>'+(r.notes?'<div class="crm-remnote">'+esc(r.notes)+'</div>':'')+'</article>').join('');m.classList.add('open');m.setAttribute('aria-hidden','false');document.body.classList.add('modal-open')}
function openFollowupTimeline(name,id,items){const m=document.getElementById('followupTimelineModal'),t=document.getElementById('followupTimelineTitle'),l=document.getElementById('followupTimelineList'),v=document.getElementById('followupViewLead');t.textContent=name+' — Follow-up Timeline';v.href='lead_view.php?id='+encodeURIComponent(id);l.innerHTML=!items.length?'<div class="empty-state">No follow-up records yet.</div>':items.map((f,i)=>'<article class="crm-timeline-modal-item"><span class="crm-timeline-number">'+esc(f.number||items.length-i)+'</span><div><div class="crm-timeline-top"><strong>'+esc(f.type)+'</strong><span>'+esc(f.relative)+'</span></div><div class="crm-timeline-badges"><span class="tag '+esc(f.statusTone||'tag-neutral')+'">'+esc(f.status)+'</span><span class="crm-parent-state '+esc(f.responseTone||'no-response')+'">'+esc(f.response)+'</span></div><div class="crm-timeline-meta">Follow-up: '+esc(f.date)+(f.time?' · '+esc(f.time):'')+(f.created?'<br>Record created: '+esc(f.created):'')+'</div><p>'+esc(f.summary)+'</p></div></article>').join('');m.classList.add('open');m.setAttribute('aria-hidden','false');document.body.classList.add('modal-open')}
function closeFollowupTimeline(){closeCrmModal('followupTimelineModal')}
function openQualityModal(name,label,score,insight,id){const dialog=document.getElementById('qualityDialog');dialog.className='crm-dialog crm-quality-dialog tone-'+esc(insight.tone||'potential');document.getElementById('qualityModalTitle').textContent=name+' — '+label;document.getElementById('qualityModalScore').innerHTML='<strong>'+esc(score)+'%</strong><span>'+esc(label)+'</span>';document.getElementById('qualityModalText').textContent=insight.summary||'';document.getElementById('qualityModalActivity').textContent=insight.activity||'';document.getElementById('qualityModalRecommendation').textContent=insight.recommendation||'';document.getElementById('qualityModalWatchout').textContent=insight.watchout||'';document.getElementById('qualityModalEncouragement').textContent=insight.encouragement||'';document.getElementById('qualityViewLead').href='lead_view.php?id='+encodeURIComponent(id);openCrmModal('qualityModal')}
function closeQualityModal(){closeCrmModal('qualityModal')}
function openNextFollowup(name,id,followupId,date,time,relative){document.getElementById('nextFollowupTitle').textContent=name+' — Next Follow-up';document.getElementById('nextFollowupDate').textContent=date+' · '+time;document.getElementById('nextFollowupRelative').textContent=relative;const base='lead_view.php?id='+encodeURIComponent(id);document.getElementById('completeFollowupLink').href=base+'&action=complete_followup&followup_id='+encodeURIComponent(followupId)+'#add-followup';document.getElementById('newFollowupLink').href=base+'&action=new_followup#add-followup';document.getElementById('rescheduleFollowupLink').href=base+'&action=reschedule&followup_id='+encodeURIComponent(followupId)+'#add-followup';openCrmModal('nextFollowupModal')}
function closeNextFollowup(){closeCrmModal('nextFollowupModal')}
function openCrmModal(id){const m=document.getElementById(id);m.classList.add('open');m.setAttribute('aria-hidden','false');document.body.classList.add('modal-open')}
function closeCrmModal(id){const m=document.getElementById(id);m.classList.remove('open');m.setAttribute('aria-hidden','true');if(!document.querySelector('.crm-modal.open,.crm-small-modal.open'))document.body.classList.remove('modal-open')}
function closeReminder(){closeCrmModal('reminderModal')}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeReminder();closeFollowupTimeline();closeQualityModal();closeNextFollowup();const d=document.getElementById('filterDialog');if(d?.classList.contains('open'))d.querySelector('[data-dialog-cancel]')?.click();}});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
