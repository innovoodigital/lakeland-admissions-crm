<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

/* Update 5 display helpers */
function crm_dt(?string $date, ?string $time = null): ?DateTime
{
    if (!$date) return null;
    try { return new DateTime($date . ' ' . ($time ?: '00:00:00')); }
    catch (Throwable $e) { return null; }
}

function crm_time_ago(?string $date, ?string $time = null): string
{
    $then = crm_dt($date, $time);
    if (!$then) return '—';

    $seconds = max(0, time() - $then->getTimestamp());
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    if ($days === 0 && $hours === 0) return 'Less than 1 hour ago';

    $parts = [];
    if ($days > 0) $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    if ($hours > 0) $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    return implode(' ', $parts) . ' ago';
}

function crm_duration(?string $startDate, ?string $startTime, ?string $endDate, ?string $endTime): string
{
    $start = crm_dt($startDate, $startTime);
    $end = crm_dt($endDate, $endTime);
    if (!$start || !$end) return '—';

    $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    if ($days === 0 && $hours === 0) return 'Less than 1 hour';

    $parts = [];
    if ($days > 0) $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    if ($hours > 0) $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    return implode(' ', $parts);
}

function crm_contact_label(string $status): string
{
    $labels = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'follow_up_needed' => 'Follow-up Needed',
          'visit_interested' => 'Visit Interested',
         'visit_requested' => 'Visit Requested',
        'visit_scheduled' => 'Visit Scheduled',
        'visited' => 'Visited',
        'not_reached' => 'Not Reached',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function crm_contact_class(string $status): string
{
    $classes = [
        'new' => 'tag-neutral',
        'contacted' => 'tag-green-light',
        'follow_up_needed' => 'tag-orange-light',
        'visit_scheduled' => 'tag-green-strong',
        'visited' => 'tag-green-dark',
        'not_reached' => 'tag-grey',
    ];
    return $classes[$status] ?? 'tag-neutral';
}


function crm_workflow_priority(string $status): int
{
    $status = normalize_workflow_status($status);

    $priorities = [
        'new' => 10,
        'contacted' => 20,
        'follow_up_required' => 30,
        'visit_interested' => 40,
        'visit_requested' => 50,
        'visit_scheduled' => 60,
        'visited' => 70,
        'placement_test_scheduled' => 80,
        'placement_test_completed' => 90,
        'joined' => 100,
        'closed' => 0,
    ];

    return $priorities[$status] ?? 0;
}


function crm_table_exists(PDO $db, string $table): bool
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

function crm_schedule_type_label(string $type): string
{
    $labels = [
        'visit_preference' => 'Preferred Visit Option',
        'confirmed_visit' => 'Confirmed Visit',
        'placement_test' => 'Placement Test',
        'enrollment' => 'Enrollment',
    ];

    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function crm_schedule_date(?string $date): string
{
    return $date ? fmt_date($date) : 'Date not added';
}

function crm_schedule_summary(array $option): string
{
    $date = crm_schedule_date($option['option_date'] ?? null);

    $startTime = trim((string)($option['start_time'] ?? ''));
    $endTime = trim((string)($option['end_time'] ?? ''));
    $timeNote = trim((string)($option['time_note'] ?? ''));
    $legacyTimeWindow = trim((string)($option['time_window'] ?? ''));

    $timeParts = [];

    if ($startTime !== '') {
        $startTimestamp = strtotime($startTime);
        $timeParts[] = $startTimestamp
            ? date('g:i A', $startTimestamp)
            : $startTime;
    }

    if ($endTime !== '') {
        $endTimestamp = strtotime($endTime);
        $formattedEndTime = $endTimestamp
            ? date('g:i A', $endTimestamp)
            : $endTime;

        if ($timeParts) {
            $timeParts[0] .= '–' . $formattedEndTime;
        } else {
            $timeParts[] = 'Until ' . $formattedEndTime;
        }
    }

    if (!$timeParts && $legacyTimeWindow !== '') {
        $timeParts[] = $legacyTimeWindow;
    }

    if ($timeNote !== '') {
        $timeParts[] = $timeNote;
    }

    return $timeParts
        ? $date . ' · ' . implode(' · ', $timeParts)
        : $date;
}


function crm_reminder_datetime(?string $date, ?string $time = null): ?DateTime
{
    if (!$date) {
        return null;
    }

    try {
        return new DateTime(
            $date . ' ' . ($time ?: '23:59:59')
        );
    } catch (Throwable $error) {
        return null;
    }
}

function crm_reminder_urgency(?string $date, ?string $time = null): array
{
    $due = crm_reminder_datetime($date, $time);

    if (!$due) {
        return [
            'label' => 'Upcoming',
            'class' => 'reminder-upcoming',
            'relative' => 'Date unavailable',
        ];
    }

    $now = new DateTime();
    $seconds = $due->getTimestamp() - $now->getTimestamp();
    $absoluteSeconds = abs($seconds);

    if ($seconds < 0) {
        if ($absoluteSeconds < 3600) {
            $minutes = max(1, (int)floor($absoluteSeconds / 60));

            return [
                'label' => 'Overdue',
                'class' => 'reminder-overdue',
                'relative' => 'Overdue by ' . $minutes
                    . ' minute' . ($minutes === 1 ? '' : 's'),
            ];
        }

        if ($absoluteSeconds < 86400) {
            $hours = max(1, (int)floor($absoluteSeconds / 3600));

            return [
                'label' => 'Overdue',
                'class' => 'reminder-overdue',
                'relative' => 'Overdue by ' . $hours
                    . ' hour' . ($hours === 1 ? '' : 's'),
            ];
        }

        $days = max(1, (int)floor($absoluteSeconds / 86400));

        return [
            'label' => 'Overdue',
            'class' => 'reminder-overdue',
            'relative' => 'Overdue by ' . $days
                . ' day' . ($days === 1 ? '' : 's'),
        ];
    }

    if ($seconds <= 3600) {
        $minutes = max(1, (int)ceil($seconds / 60));

        return [
            'label' => 'Urgent',
            'class' => 'reminder-urgent',
            'relative' => 'Due in ' . $minutes
                . ' minute' . ($minutes === 1 ? '' : 's'),
        ];
    }

    if ($seconds <= 86400) {
        $hours = max(1, (int)ceil($seconds / 3600));

        return [
            'label' => 'Due soon',
            'class' => 'reminder-due-soon',
            'relative' => 'Due in ' . $hours
                . ' hour' . ($hours === 1 ? '' : 's'),
        ];
    }

    $days = max(1, (int)ceil($seconds / 86400));

    return [
        'label' => 'Upcoming',
        'class' => 'reminder-upcoming',
        'relative' => 'Due in ' . $days
            . ' day' . ($days === 1 ? '' : 's'),
    ];
}

function crm_reminder_type_label(string $type): string
{
    $labels = [
        'follow_up' => 'Follow-up',
        'phone_call' => 'Phone Call',
        'whatsapp' => 'WhatsApp',
        'visit' => 'Visit',
        'placement_test' => 'Placement Test',
        'document_collection' => 'Document Collection',
        'payment' => 'Payment',
        'enrollment' => 'Enrollment',
        'general' => 'General',
    ];

    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}


function crm_reminder_source_key(array $reminder): string
{
    $sourceType = strtolower(trim((string)($reminder['source_type'] ?? '')));
    $reminderType = strtolower(trim((string)($reminder['reminder_type'] ?? 'general')));
    $followupId = (int)($reminder['followup_id'] ?? 0);

    if ($sourceType === 'manual' || $followupId <= 0) {
        return 'manual';
    }

    return match ($reminderType) {
        'follow_up' => 'next-followup',
        'visit' => 'visit',
        'placement_test' => 'placement-test',
        'enrollment' => 'enrollment',
        default => 'workflow',
    };
}

function crm_reminder_source_label(array $reminder): string
{
    return match (crm_reminder_source_key($reminder)) {
        'manual' => 'Manual Task',
        'next-followup' => 'Next Follow-up',
        'visit' => 'Visit Reminder',
        'placement-test' => 'Placement Test',
        'enrollment' => 'Enrollment',
        default => 'Workflow Reminder',
    };
}

function crm_reminder_source_icon(array $reminder): string
{
    return match (crm_reminder_source_key($reminder)) {
        'manual' => '📝',
        'next-followup' => '📞',
        'visit' => '🏫',
        'placement-test' => '📋',
        'enrollment' => '🎓',
        default => '🔔',
    };
}


function crm_next_followup_status(
    array $currentFollowup,
    array $allFollowups
): array {
    $nextDate = trim((string)($currentFollowup['next_action_date'] ?? ''));
    $nextTime = trim((string)($currentFollowup['next_action_time'] ?? ''));

    if ($nextDate === '') {
        return [
            'key' => 'none',
            'label' => '',
            'class' => '',
        ];
    }

    $currentFollowupId = (int)($currentFollowup['id'] ?? 0);
    $currentTimestamp = crm_dt(
        $currentFollowup['followup_date'] ?? null,
        $currentFollowup['followup_time'] ?? null
    );

    /*
    |--------------------------------------------------------------------------
    | Completed
    |--------------------------------------------------------------------------
    | A later follow-up recorded on the scheduled next-follow-up date marks
    | this next action as completed. Time is intentionally optional.
    */

    foreach ($allFollowups as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $currentFollowupId) {
            continue;
        }

        if (($candidate['followup_date'] ?? '') !== $nextDate) {
            continue;
        }

        $candidateTimestamp = crm_dt(
            $candidate['followup_date'] ?? null,
            $candidate['followup_time'] ?? null
        );

        if (
            !$currentTimestamp
            || !$candidateTimestamp
            || $candidateTimestamp->getTimestamp()
                > $currentTimestamp->getTimestamp()
        ) {
            return [
                'key' => 'completed',
                'label' => 'Completed',
                'class' => 'next-followup-completed',
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Missed
    |--------------------------------------------------------------------------
    | If the date has already passed and no matching follow-up was recorded
    | on that date, the next follow-up is marked as missed.
    */

    $today = new DateTime('today');

    try {
        $scheduledDate = new DateTime($nextDate . ' 00:00:00');
    } catch (Throwable $error) {
        return [
            'key' => 'active',
            'label' => 'Active',
            'class' => 'next-followup-active',
        ];
    }

    if ($scheduledDate < $today) {
        return [
            'key' => 'missed',
            'label' => 'Missed',
            'class' => 'next-followup-missed',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Active
    |--------------------------------------------------------------------------
    | Today and future dates remain active until a matching follow-up exists.
    */

    return [
        'key' => 'active',
        'label' => 'Active',
        'class' => 'next-followup-active',
    ];
}

function crm_inquiry_label(string $status): string
{
    $labels = [
        'possible' => 'Possible',
        'not_possible' => 'Not Possible',
        'can_consider' => 'Can Consider',
        'adjustable' => 'Can Adjust',
        'recommended' => 'Recommended',
        'management_approval' => 'Management Approval',
        'alternative_available' => 'Alternative Available',
        'pending' => 'Pending',
        'completed' => 'Completed',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function crm_inquiry_class(string $status): string
{
    $classes = [
        'possible' => 'tag-green-light',
        'recommended' => 'tag-green-strong',
        'completed' => 'tag-green-dark',
        'can_consider' => 'tag-orange-light',
        'adjustable' => 'tag-orange-light',
        'management_approval' => 'tag-brass',
        'alternative_available' => 'tag-green-light',
        'pending' => 'tag-grey',
        'not_possible' => 'tag-red-strong',
    ];
    return $classes[$status] ?? 'tag-neutral';
}

function crm_quality(
    string $workflowStatus,
    string $parentResponse,
    array $inquiries,
    array $followups,
    ?string $lastDate,
    ?string $lastTime
): array
{
    /*
    |--------------------------------------------------------------------------
    | Parent response is the main lead-quality indicator
    |--------------------------------------------------------------------------
    */

    $responseScores = [
        'interested' => 70,
        'positive' => 70,

        'still_considering' => 50,
        'pending' => 40,
        'will_call_back' => 45,
        'call_back_later' => 30,

        'no_response' => 25,
        'not_reached' => 20,
        'number_not_working' => 5,

        'not_interested' => 10,
        'rejected' => 5,
        'wrong_lead' => 0,
        'accidental_lead' => 0,
        'job_inquiry' => 0,

        // Legacy values
        'neutral' => 50,
        'negative' => 10,
    ];

    $score = $responseScores[$parentResponse] ?? 20;

    /*
    |--------------------------------------------------------------------------
    | Admissions progress bonus
    |--------------------------------------------------------------------------
    */

    $workflowBonuses = [
        'new' => 5,
        'contacted' => 2,
        'follow_up_required' => 0,
        'visit_interested' => 8,
        'visit_requested' => 12,
        'visit_scheduled' => 15,
        'visited' => 20,
        'placement_test_scheduled' => 20,
        'placement_test_completed' => 25,
        'joined' => 100,
        'closed' => 0,
    ];

    if ($workflowStatus === 'joined') {
        $score = 100;
    } else {
        $score += $workflowBonuses[$workflowStatus] ?? 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Parent inquiry / requirement signal
    |--------------------------------------------------------------------------
    */

    $inquiryBonus = 0;

    foreach ($inquiries as $inquiry) {
        $inquiryStatus = $inquiry['inquiry_status'] ?? 'pending';

        $inquiryBonus += match ($inquiryStatus) {
            'possible',
            'recommended',
            'completed' => 4,

            'can_consider',
            'adjustable',
            'alternative_available',
            'management_approval' => 2,

            'not_possible' => -15,

            default => 0,
        };
    }

    $score += max(-30, min(15, $inquiryBonus));

    /*
    |--------------------------------------------------------------------------
    | Follow-up engagement bonus
    |--------------------------------------------------------------------------
    | Follow-up quantity has only a small influence. Parent response and
    | admissions progress remain the main indicators.
    */

    $followupBonus = 0;

    foreach ($followups as $followup) {
        $type = $followup['followup_type'] ?? '';

        $followupBonus += match ($type) {
            'physical_followup' => 4,
            'phone_call',
            'call_engagement' => 3,
            'whatsapp_admission',
            'whatsapp_engagement',
            'general_followup' => 1,
            default => 0,
        };
    }

    $score += min($followupBonus, 8);

    /*
    |--------------------------------------------------------------------------
    | Inactivity penalty
    |--------------------------------------------------------------------------
    */

    $lastContact = crm_dt($lastDate, $lastTime);

    if ($lastContact) {
        $daysInactive = intdiv(
            max(0, time() - $lastContact->getTimestamp()),
            86400
        );

        if ($daysInactive >= 14) {
            $score -= 20;
        } elseif ($daysInactive >= 7) {
            $score -= 10;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Parent-response score caps
    |--------------------------------------------------------------------------
    | Negative or weak responses can never appear as a strong lead simply
    | because many follow-ups were recorded.
    */

    $maximumScores = [
        'still_considering' => 65,
        'neutral' => 65,
        'pending' => 55,
        'will_call_back' => 55,
        'call_back_later' => 40,

        'no_response' => 30,
        'not_reached' => 25,
        'number_not_working' => 10,

        'not_interested' => 15,
        'negative' => 15,
        'rejected' => 10,
        'wrong_lead' => 0,
        'accidental_lead' => 0,
        'job_inquiry' => 0,
    ];

    if (isset($maximumScores[$parentResponse])) {
        $score = min(
            $score,
            $maximumScores[$parentResponse]
        );
    }

    $score = max(0, min(100, $score));

    if ($score >= 80) {
        return [
            'score' => $score,
            'label' => 'Hot Lead',
            'class' => 'quality-hot',
        ];
    }

    if ($score >= 65) {
        return [
            'score' => $score,
            'label' => 'Strong Lead',
            'class' => 'quality-strong',
        ];
    }

    if ($score >= 45) {
        return [
            'score' => $score,
            'label' => 'Potential Lead',
            'class' => 'quality-potential',
        ];
    }

    if ($score >= 25) {
        return [
            'score' => $score,
            'label' => 'Low Engagement',
            'class' => 'quality-low',
        ];
    }

    return [
        'score' => $score,
        'label' => 'Unqualified Lead',
        'class' => 'quality-unqualified',
    ];
}


$stmt = $db->prepare(
    'SELECT *
     FROM leads
     WHERE id = ?'
);
$stmt->execute([$id]);
$lead = $stmt->fetch();

if (!$lead) {
    flash_set('Lead not found.', 'error');
    header('Location: leads.php');
    exit;
}

$fu = $db->prepare(
    'SELECT *
     FROM follow_ups
     WHERE lead_id = ?
     ORDER BY followup_number DESC, followup_date DESC, followup_time DESC, id DESC'
);
$fu->execute([$id]);
$followups = $fu->fetchAll();

$followupCount = count($followups);

// Follow-ups are loaded newest first.
$latestFollowup = $followupCount ? $followups[0] : null;
$oldestFollowup = $followupCount ? $followups[$followupCount - 1] : null;

$inquiries = [];

if (crm_table_exists($db, 'lead_inquiries')) {
    $inquiryStmt = $db->prepare(
        'SELECT * FROM lead_inquiries WHERE lead_id = ? ORDER BY created_at ASC, id ASC'
    );
    $inquiryStmt->execute([$id]);
    $inquiries = $inquiryStmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Follow-up scheduling information
|--------------------------------------------------------------------------
| The page remains usable before the schedule table is created. Once the
| table exists, preferred visit options, confirmed visits, placement tests,
| and enrollment dates are loaded and attached to their follow-ups.
*/

$scheduleOptionsByFollowup = [];

if (crm_table_exists($db, 'followup_schedule_options')) {
    $scheduleStmt = $db->prepare(
        'SELECT *
         FROM followup_schedule_options
         WHERE lead_id = ?
         ORDER BY followup_id ASC, option_number ASC, id ASC'
    );
    $scheduleStmt->execute([$id]);

    foreach ($scheduleStmt->fetchAll(PDO::FETCH_ASSOC) as $scheduleOption) {
        $scheduleFollowupId = (int)($scheduleOption['followup_id'] ?? 0);

        if ($scheduleFollowupId > 0) {
            $scheduleOptionsByFollowup[$scheduleFollowupId][] = $scheduleOption;
        }
    }
}



/*
|--------------------------------------------------------------------------
| Lead reminders
|--------------------------------------------------------------------------
| Reminders are optional so the lead page still works before the
| lead_reminders table is created.
*/

$reminders = [];
$reminderCount = 0;

if (crm_table_exists($db, 'lead_reminders')) {
    $reminderStmt = $db->prepare(
        'SELECT *
         FROM lead_reminders
         WHERE lead_id = ?
         ORDER BY
            CASE status
                WHEN "pending" THEN 1
                WHEN "completed" THEN 2
                WHEN "dismissed" THEN 3
                ELSE 4
            END ASC,
            reminder_date ASC,
            CASE
                WHEN reminder_time IS NULL THEN 1
                ELSE 0
            END ASC,
            reminder_time ASC,
            id DESC'
    );

    $reminderStmt->execute([$id]);

    $reminders = $reminderStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reminders as $reminderRecord) {
        if (($reminderRecord['status'] ?? '') === 'pending') {
            $reminderCount++;
        }
    }
}

$activeReminders = [];
$completedReminders = [];
$dismissedReminders = [];

foreach ($reminders as $reminderRecord) {
    $recordStatus = $reminderRecord['status'] ?? 'pending';

    if ($recordStatus === 'completed') {
        $completedReminders[] = $reminderRecord;
    } elseif ($recordStatus === 'dismissed') {
        $dismissedReminders[] = $reminderRecord;
    } else {
        $activeReminders[] = $reminderRecord;
    }
}

/*
|--------------------------------------------------------------------------
| Follow-up labels
|--------------------------------------------------------------------------
*/

$followupTypeLabels = [
    'phone_call' => 'Phone Follow-up',
    'whatsapp_admission' => 'WhatsApp Admission Reminder',
    'whatsapp_engagement' => 'WhatsApp Engagement Content',
    'physical_followup' => 'Physical Follow-up',
    'call_engagement' => 'Call Engagement',
    'general_followup' => 'General Follow-up',
];

$outcomeLabels = [
    'interested' => 'Parent Interested',
    'positive' => 'Parent Interested',
    'still_considering' => 'Parent Still Considering',
    'pending' => 'Parent Response Pending',
    'call_back_later' => 'Parent Asked to Call Later',
    'will_call_back' => 'Parent Will Call Back',
    'no_response' => 'Parent Did Not Respond',
    'not_reached' => 'Parent Not Reached',
    'number_not_working' => 'Parent Number Not Working',
    'not_interested' => 'Parent Not Interested',
    'wrong_lead' => 'Wrong Lead',
    'accidental_lead' => 'Accidental Lead',
    'job_inquiry' => 'Job Inquiry',
    'rejected' => 'Rejected',

    // Legacy values
    'neutral' => 'Parent Still Considering',
    'negative' => 'Parent Not Interested',
];

$page_title = $lead['child_name']
    ?: $lead['parent_name']
    ?: 'Lead';

$active = 'leads';

/*
|--------------------------------------------------------------------------
| Main lead status
|--------------------------------------------------------------------------
| Calculate the highest admissions milestone directly from the complete
| follow-up timeline. This keeps the top card correct even when an older
| leads.status database value has not yet been recalculated.
*/

$currentStatus = normalize_workflow_status(
    $lead['status'] ?? 'new'
);

$highestTimelineStatus = 'new';
$highestTimelinePriority = crm_workflow_priority('new');

foreach ($followups as $followup) {
    $candidateStatus = normalize_workflow_status(
        $followup['lead_status'] ?? 'new'
    );

    if ($candidateStatus === 'closed') {
        continue;
    }

    $candidatePriority = crm_workflow_priority($candidateStatus);

    if ($candidatePriority > $highestTimelinePriority) {
        $highestTimelineStatus = $candidateStatus;
        $highestTimelinePriority = $candidatePriority;
    }
}

/*
| A latest explicit Closed status is allowed to close the lead. Otherwise,
| the highest non-closed milestone in the timeline wins.
*/
$latestTimelineStatus = normalize_workflow_status(
    $latestFollowup['lead_status'] ?? 'new'
);

if ($latestTimelineStatus === 'closed') {
    $currentStatus = 'closed';
} elseif (
    $highestTimelinePriority >= crm_workflow_priority($currentStatus)
) {
    $currentStatus = $highestTimelineStatus;
}

/*
|--------------------------------------------------------------------------
| Separate parent response from admissions workflow
|--------------------------------------------------------------------------
| Parent response comes from the latest follow-up outcome. If a future
| parent_response column exists, it will automatically take priority.
*/
$parentResponse = !empty($lead['parent_response'])
    ? $lead['parent_response']
    : ($latestFollowup['outcome'] ?? 'pending');

$workflowStatusMap = [
    'new' => 'new',
    'contacted' => 'contacted',
    'follow_up_required' => 'follow_up_required',
    'follow_up' => 'follow_up_required',
    'still_considering' => 'follow_up_required',
    'pending' => 'follow_up_required',
    'interested' => 'contacted',
    'positive' => 'contacted',
    'no_response' => 'follow_up_required',
    'not_reached' => 'follow_up_required',
    'number_not_working' => 'follow_up_required',
    'visit_interested' => 'visit_interested',
    'visit_requested' => 'visit_requested',
    'visit_scheduled' => 'visit_scheduled',
    'visited' => 'visited',
    'placement_test_scheduled' => 'placement_test_scheduled',
    'placement_test_completed' => 'placement_test_completed',
    'converted' => 'joined',
    'joined' => 'joined',
    'not_interested' => 'closed',
    'wrong_lead' => 'closed',
    'accidental_lead' => 'closed',
    'random_click' => 'closed',
    'job_inquiry' => 'closed',
    'rejected' => 'closed',
];

$workflowStatus = $workflowStatusMap[$currentStatus] ?? 'new';

/*
|--------------------------------------------------------------------------
| Current milestone details for the top card
|--------------------------------------------------------------------------
*/

$currentMilestoneFollowup = null;
$currentMilestoneSchedules = [];

foreach ($followups as $followup) {
    $savedWorkflowStatus = normalize_workflow_status(
        $followup['lead_status'] ?? 'new'
    );

    if ($savedWorkflowStatus !== $workflowStatus) {
        continue;
    }

    $currentMilestoneFollowup = $followup;
    $currentMilestoneSchedules =
        $scheduleOptionsByFollowup[(int)($followup['id'] ?? 0)] ?? [];

    break;
}

$workflowLabels = [
    'new' => 'New',
    'contacted' => 'Contacted',
    'follow_up_required' => 'Follow-up Required',
    'visit_interested' => 'Visit Interested',
    'visit_requested' => 'Visit Requested',
       'visit_scheduled' => 'Visit Scheduled',
    'visited' => 'Visited',
    'placement_test_scheduled' => 'Placement Test Scheduled',
    'placement_test_completed' => 'Placement Test Completed',
    'joined' => 'Joined / Enrolled',
    'closed' => 'Closed',
];

$parentResponseLabels = [
    'interested' => 'Parent Interested',
    'positive' => 'Parent Interested',
    'still_considering' => 'Parent Still Considering',
    'pending' => 'Parent Response Pending',
    'call_back_later' => 'Parent Asked to Call Later',
    'will_call_back' => 'Parent Will Call Back',
    'no_response' => 'Parent Did Not Respond',
    'not_reached' => 'Parent Not Reached',
    'number_not_working' => 'Parent Number Not Working',
    'not_interested' => 'Parent Not Interested',
    'wrong_lead' => 'Wrong Lead',
    'accidental_lead' => 'Accidental Lead',
    'job_inquiry' => 'Job Inquiry',
    'rejected' => 'Rejected',
];

$parentResponseLabel = $parentResponseLabels[$parentResponse]
    ?? ucwords(str_replace('_', ' ', $parentResponse));

$parentResponseClassMap = [
    'interested' => 'parent-interested',
    'positive' => 'parent-interested',
    'still_considering' => 'parent-considering',
    'pending' => 'parent-considering',
    'call_back_later' => 'parent-considering',
    'will_call_back' => 'parent-considering',
    'no_response' => 'parent-no-response',
    'not_reached' => 'parent-no-response',
    'number_not_working' => 'parent-no-response',
    'not_interested' => 'parent-not-interested',
    'wrong_lead' => 'parent-not-interested',
    'accidental_lead' => 'parent-not-interested',
    'job_inquiry' => 'parent-not-interested',
    'rejected' => 'parent-rejected',
];

$parentResponseClass = $parentResponseClassMap[$parentResponse]
    ?? 'parent-neutral';

$contactStatus = $lead['contact_status'] ?? 'new';
$receivedTime = $lead['received_time'] ?? null;

$quality = crm_quality(
    $workflowStatus,
    $parentResponse,
    $inquiries,
    $followups,
    $latestFollowup['followup_date'] ?? null,
    $latestFollowup['followup_time'] ?? null
);

$leadAge = crm_duration(
    $lead['received_date'] ?? null,
    $receivedTime,
    date('Y-m-d'),
    date('H:i:s')
);

$followupDuration = ($oldestFollowup && $latestFollowup)
    ? crm_duration(
        $oldestFollowup['followup_date'] ?? null,
        $oldestFollowup['followup_time'] ?? null,
        $latestFollowup['followup_date'] ?? null,
        $latestFollowup['followup_time'] ?? null
    )
    : '—';


require __DIR__ . '/includes/layout_top.php';
?>

<style>
.current-milestone-details {
    width: min(100%, 300px);
    margin-top: 9px;
    display: grid;
    gap: 6px;
}

.current-milestone-option,
.current-milestone-single {
    display: grid;
    gap: 2px;
    padding: 7px 9px;
    border: 1px solid rgba(15, 39, 76, 0.11);
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.64);
    line-height: 1.3;
}

.current-milestone-option span,
.current-milestone-single span {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    opacity: .62;
}

.current-milestone-option strong,
.current-milestone-single strong {
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}


.reminder-panel {
    margin-bottom: 18px;
    overflow: hidden;
}

.reminder-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 14px;
}

.reminder-heading-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}

.reminder-bell {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 39, 76, .08);
    font-size: 18px;
}

.reminder-count-badge {
    min-width: 28px;
    height: 28px;
    padding: 0 9px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0f274c;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
}

.reminder-list {
    display: grid;
    gap: 12px;
}

.reminder-item {
    border: 1px solid rgba(15, 39, 76, .12);
    border-left-width: 5px;
    border-radius: 12px;
    padding: 14px;
    background: #fff;
}

.reminder-upcoming {
    border-left-color: #2d6cdf;
}

.reminder-due-soon {
    border-left-color: #e39021;
    background: #fffaf2;
}

.reminder-urgent {
    border-left-color: #d84a3a;
    background: #fff5f3;
}

.reminder-overdue {
    border-left-color: #9e1f1f;
    background: #fff1f1;
}

.reminder-item-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.reminder-copy {
    min-width: 0;
}

.reminder-copy h3 {
    margin: 0;
    font-size: 15px;
}

.reminder-meta {
    margin-top: 5px;
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    align-items: center;
    font-size: 12px;
    color: rgba(15, 39, 76, .72);
}

.reminder-urgency-badge {
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    background: rgba(15, 39, 76, .08);
}

.reminder-note {
    margin-top: 10px;
    padding: 9px 10px;
    border-radius: 9px;
    background: rgba(15, 39, 76, .045);
    font-size: 12px;
    line-height: 1.5;
}

.reminder-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.reminder-empty {
    padding: 18px;
    border: 1px dashed rgba(15, 39, 76, .2);
    border-radius: 12px;
    text-align: center;
    color: rgba(15, 39, 76, .66);
}

.reminder-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}


.reminder-list-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.reminder-add-dialog {
    width: min(620px, calc(100vw - 32px)) !important;
    max-width: 620px !important;
}

.reminder-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.reminder-priority {
    display: inline-flex;
    margin-top: 7px;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(15, 39, 76, .07);
    color: rgba(15, 39, 76, .72);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.reminder-priority-high {
    background: #fff1d6;
    color: #9a5b00;
}

.reminder-priority-urgent {
    background: #fee2e2;
    color: #b91c1c;
}

.reminder-open-followup {
    background: #1b2a4a;
    color: #fff;
}

.reminder-open-followup:hover {
    background: #2e4166;
}

.reminder-popup-badges {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
    flex-wrap: wrap;
}

.reminder-status-completed,
.reminder-status-dismissed {
    opacity: .84;
}

.reminder-status-completed {
    border-left-color: #3f6b4f !important;
    background: #f3faf5 !important;
}

.reminder-status-dismissed {
    border-left-color: #8a929d !important;
    background: #f5f6f7 !important;
}

.reminder-history-meta {
    margin-top: 10px;
    color: rgba(15, 39, 76, .65);
    font-size: 11px;
}

.reminder-history-meta strong {
    color: #0f274c;
}

.reminder-active-heading {
    margin: 2px 0 10px;
    color: rgba(15, 39, 76, .62);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.reminder-source-manual {
    border-left-color: #7c3aed !important;
    background: #faf7ff !important;
}

.reminder-source-next-followup {
    border-left-color: #1b2a4a !important;
    background: #f7f9fd !important;
}

.reminder-source-visit {
    border-left-color: #16855b !important;
    background: #f3fbf7 !important;
}

.reminder-source-placement-test {
    border-left-color: #d97706 !important;
    background: #fff9ef !important;
}

.reminder-source-enrollment {
    border-left-color: #0f8f8b !important;
    background: #f1fbfb !important;
}

.reminder-source-workflow {
    border-left-color: #3b82f6 !important;
    background: #f5f9ff !important;
}

.reminder-source-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 7px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 850;
    letter-spacing: .05em;
    line-height: 1;
    text-transform: uppercase;
}

.reminder-source-manual .reminder-source-badge {
    background: #ede9fe;
    color: #6d28d9;
}

.reminder-source-next-followup .reminder-source-badge {
    background: #dce4f2;
    color: #1b2a4a;
}

.reminder-source-visit .reminder-source-badge {
    background: #dff5e8;
    color: #166b49;
}

.reminder-source-placement-test .reminder-source-badge {
    background: #ffedcc;
    color: #a65400;
}

.reminder-source-enrollment .reminder-source-badge {
    background: #d8f2f1;
    color: #08716d;
}

.reminder-source-workflow .reminder-source-badge {
    background: #dbeafe;
    color: #1d4ed8;
}

.reminder-history-section {
    margin-top: 14px;
    border: 1px solid rgba(15, 39, 76, .12);
    border-radius: 11px;
    overflow: hidden;
    background: #fff;
}

.reminder-history-section summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 15px;
    cursor: pointer;
    color: #0f274c;
    font-size: 13px;
    font-weight: 800;
    list-style: none;
}

.reminder-history-section summary::-webkit-details-marker {
    display: none;
}

.reminder-history-section summary::after {
    content: '▾';
    transition: transform .18s ease;
}

.reminder-history-section:not([open]) summary::after {
    transform: rotate(-90deg);
}

.reminder-history-count {
    margin-left: auto;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(15, 39, 76, .08);
    font-size: 10px;
}

.reminder-history-list {
    display: grid;
    gap: 10px;
    padding: 0 12px 12px;
}

.reminder-history-list .reminder-popup-item {
    margin: 0;
}

.reminder-history-empty {
    padding: 14px;
    color: rgba(15, 39, 76, .62);
    font-size: 12px;
    text-align: center;
}

@media (max-width: 780px) {
    .reminder-list-header-actions {
        width: 100%;
        justify-content: space-between;
    }

    .reminder-form-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 780px) {
    .current-milestone-details {
        width: 100%;
    }

    .reminder-panel-header,
    .reminder-item-top {
        flex-direction: column;
    }

    .reminder-modal-grid {
        grid-template-columns: 1fr;
    }
}


/* ============================================================
   REDESIGNED LEAD PROFILE HEADER
   ============================================================ */

.lead-profile-redesign {
    display: grid;
    grid-template-columns:
        minmax(300px, 1.25fr)
        minmax(245px, .95fr)
        minmax(245px, .9fr);
    align-items: stretch;
    gap: 0;
    min-width: 0;
    padding: 28px 30px;
    border-radius: 16px;
    overflow: hidden;
}

.lead-profile-redesign .lead-profile-column {
    min-width: 0;
}

.lead-profile-identity {
    padding: 0 30px 0 0;
}

.lead-profile-status {
    padding: 0 30px;
    border-left: 1px solid rgba(15, 39, 76, .10);
    border-right: 1px solid rgba(15, 39, 76, .10);
}

.lead-profile-contact {
    padding: 0 0 0 30px;
}

.lead-profile-info-row {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.lead-profile-icon,
.lead-profile-phone-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    border-radius: 12px;
    background: rgba(43, 151, 101, .10);
    color: #16855b;
    font-size: 18px;
}

.lead-profile-icon-student {
    background: #e9f7ef;
}

.lead-profile-icon-parent {
    background: #edf7f2;
}

.lead-profile-icon-date {
    background: #edf8f3;
}

.lead-profile-copy {
    min-width: 0;
}

.lead-profile-kicker {
    display: block;
    margin-bottom: 4px;
    color: #16855b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .07em;
    line-height: 1.2;
    text-transform: uppercase;
}

.lead-profile-primary-name {
    display: block;
    color: #0f274c;
    font-family: var(--font-display);
    font-size: 1.75rem;
    font-weight: 650;
    line-height: 1.12;
    overflow-wrap: anywhere;
}

.lead-profile-secondary-name,
.lead-profile-received-date {
    display: block;
    color: #0f274c;
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.lead-profile-received-time {
    color: rgba(15, 39, 76, .64);
    font-size: .84rem;
    font-weight: 600;
}

.lead-profile-divider {
    height: 1px;
    margin: 18px 0;
    background: rgba(15, 39, 76, .09);
}

.lead-profile-response {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 15px;
    border: 1px solid #efd488;
    border-radius: 13px;
    background: linear-gradient(135deg, #fff9df 0%, #fff4c4 100%);
    color: #8b5d00;
}

.lead-profile-response-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border-radius: 10px;
    background: rgba(222, 166, 29, .14);
    color: #c38700;
    font-size: 17px;
}

.lead-profile-response .lead-profile-kicker {
    margin-bottom: 2px;
    color: #9a6a00;
}

.lead-profile-response strong {
    display: block;
    color: #805300;
    font-size: .95rem;
    font-weight: 800;
    line-height: 1.3;
}

.lead-profile-status-block {
    display: grid;
    gap: 7px;
    margin-top: 22px;
}

.lead-profile-status-block .lead-profile-kicker {
    color: rgba(15, 39, 76, .62);
}

.lead-profile-status-tag {
    width: fit-content;
    padding: 6px 13px;
    font-size: .75rem;
}

.lead-profile-schedule-list {
    display: grid;
    gap: 9px;
    margin-top: 16px;
}

.lead-profile-schedule-card {
    display: grid;
    gap: 6px;
    padding: 11px 12px;
    border: 1px solid rgba(15, 39, 76, .11);
    border-radius: 10px;
    background: rgba(255, 255, 255, .78);
}

.lead-profile-schedule-card > span {
    color: rgba(15, 39, 76, .58);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.lead-profile-schedule-card > strong {
    color: #0f274c;
    font-size: .78rem;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.lead-profile-schedule-detail {
    display: flex;
    align-items: center;
    gap: 7px;
}

.lead-profile-schedule-detail strong {
    color: #0f274c;
    font-size: .82rem;
    line-height: 1.35;
}

.lead-profile-schedule-icon {
    width: 18px;
    color: #59708f;
    text-align: center;
}

.lead-profile-phone-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 2px 0 20px;
    border-bottom: 1px solid rgba(15, 39, 76, .10);
}

.lead-profile-phone-icon {
    width: 48px;
    height: 48px;
    flex-basis: 48px;
    border-radius: 14px;
    background: #e4f4e7;
    color: #16855b;
    font-size: 21px;
}

.lead-profile-phone-number {
    display: block;
    color: #0f274c;
    font-family: var(--font-mono);
    font-size: 1.15rem;
    font-weight: 800;
    line-height: 1.3;
    text-decoration: none;
    overflow-wrap: anywhere;
}

.lead-profile-phone-number:hover {
    color: #16855b;
}

.lead-profile-quality {
    margin-top: 20px;
    padding: 16px;
    border: 1px solid #b9e2c8;
    border-radius: 13px;
    background: linear-gradient(135deg, #effbf3 0%, #e4f7ea 100%);
}

.lead-profile-quality-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.lead-profile-quality-header span {
    color: #24734d;
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.lead-profile-quality-header strong {
    color: #0f274c;
    font-family: var(--font-mono);
    font-size: 1.15rem;
}

.lead-profile-quality-bar {
    height: 8px;
    margin-top: 12px;
    overflow: hidden;
    border-radius: 999px;
    background: rgba(15, 39, 76, .12);
}

.lead-profile-quality-bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #20b768;
}

.lead-profile-quality-label {
    display: block;
    margin-top: 9px;
    color: #24734d;
    font-size: .78rem;
    font-weight: 750;
}

@media (max-width: 1180px) {
    .lead-profile-redesign {
        grid-template-columns: minmax(280px, 1.1fr) minmax(250px, .9fr);
    }

    .lead-profile-contact {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(230px, .75fr);
        align-items: center;
        gap: 22px;
        margin-top: 22px;
        padding: 22px 0 0;
        border-top: 1px solid rgba(15, 39, 76, .10);
    }

    .lead-profile-status {
        border-right: none;
    }

    .lead-profile-phone-row {
        padding: 0;
        border-bottom: none;
    }

    .lead-profile-quality {
        margin-top: 0;
    }
}

@media (max-width: 760px) {
    .lead-profile-redesign {
        grid-template-columns: 1fr;
        padding: 22px;
    }

    .lead-profile-identity,
    .lead-profile-status,
    .lead-profile-contact {
        padding: 0;
        border: none;
    }

    .lead-profile-status,
    .lead-profile-contact {
        margin-top: 22px;
        padding-top: 22px;
        border-top: 1px solid rgba(15, 39, 76, .10);
    }

    .lead-profile-contact {
        display: block;
    }

    .lead-profile-phone-row {
        padding-bottom: 18px;
        border-bottom: 1px solid rgba(15, 39, 76, .10);
    }

    .lead-profile-quality {
        margin-top: 18px;
    }
}

@media (max-width: 460px) {
    .lead-profile-info-row {
        align-items: flex-start;
    }

    .lead-profile-primary-name {
        font-size: 1.45rem;
    }

    .lead-profile-response {
        align-items: flex-start;
    }
}


/* ============================================================
   TIMELINE ICON ACTIONS
   Compact edit/delete controls aligned to the bottom-right.
   ============================================================ */

.timeline-item {
    position: relative;
}

.timeline-card-actions {
    display: flex !important;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
    width: 100%;
    margin-top: 10px;
}

.timeline-icon-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    padding: 0;
    border: 1px solid rgba(15, 39, 76, .15);
    border-radius: 8px;
    background: rgba(255, 255, 255, .78);
    color: #0f274c;
    cursor: pointer;
    transition:
        transform .16s ease,
        border-color .16s ease,
        background .16s ease,
        color .16s ease,
        box-shadow .16s ease;
}

.timeline-icon-action svg {
    width: 15px;
    height: 15px;
    fill: currentColor;
}

.timeline-icon-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(15, 39, 76, .08);
}

.timeline-edit-action:hover {
    border-color: rgba(15, 39, 76, .35);
    background: #f3f6fa;
    color: #173d73;
}

.timeline-delete-action {
    border-color: rgba(168, 67, 47, .22);
    color: #a8432f;
}

.timeline-delete-action:hover {
    border-color: rgba(168, 67, 47, .42);
    background: #fff1ed;
    color: #922f20;
}

.timeline-icon-action:focus-visible {
    outline: 3px solid rgba(184, 148, 77, .25);
    outline-offset: 2px;
}

</style>

<div class="topbar">

    <div>
        <div class="eyebrow">
            Lead #<?= (int)$lead['id'] ?>
            ·
            <?= source_label($lead['source']) ?>
        </div>

        <h1>
            <?= e($lead['child_name'] ?: 'Unnamed child') ?>
        </h1>
    </div>

    <div class="lead-top-actions">

        <button
            type="button"
            class="reminder-bell-button"
            onclick="openReminderListModal()"
            aria-label="Open reminders"
            title="Reminders"
        >
            <span class="reminder-bell-icon" aria-hidden="true">🔔</span>

            <?php if ($reminderCount > 0): ?>
                <span
                    class="reminder-bell-count"
                    aria-label="<?= $reminderCount ?> pending reminders"
                >
                    <?= $reminderCount > 99 ? '99+' : $reminderCount ?>
                </span>
            <?php endif; ?>
        </button>

        <?php if (is_admin()): ?>

            <button
                type="button"
                class="btn btn-brass"
                onclick="openFollowupModal()"
            >
                + Add Follow-up
            </button>

            <a
                href="lead_form.php?id=<?= $id ?>"
                class="btn btn-outline"
            >
                Edit details
            </a>

            <form
                method="post"
                action="lead_action.php"
                onsubmit="return confirm(
                    'Delete this lead and all its follow-ups? This cannot be undone.'
                );"
            >
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $id ?>">

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    Delete
                </button>
            </form>

        <?php endif; ?>

    </div>

</div>

<!-- Two-column lead workspace -->
<div class="lead-workspace">

    <!-- Left: lead information -->
    <main class="lead-main-column">

        <section class="card crm-lead-overview <?= e($parentResponseClass) ?>">

    <div class="crm-lead-overview-column crm-lead-person-column">

        <div class="crm-overview-person">
            <span class="crm-overview-label">Student</span>
            <strong class="crm-overview-student-name">
                <?= e($lead['child_name'] ?: 'Unnamed child') ?>
            </strong>
        </div>

        <div class="crm-overview-divider"></div>

        <div class="crm-overview-person">
            <span class="crm-overview-label">Parent / Guardian</span>
            <strong class="crm-overview-parent-name">
                <?= e($lead['parent_name'] ?: 'Not added') ?>
            </strong>
        </div>

        <div class="crm-overview-received">
            <span class="crm-overview-label">Received on</span>
            <strong>
                <?= e(fmt_date($lead['received_date'] ?? null)) ?>

                <?php if ($receivedTime): ?>
                    <span class="crm-overview-muted">
                        · <?= e(date('g:i A', strtotime($receivedTime))) ?>
                    </span>
                <?php endif; ?>
            </strong>
        </div>

    </div>

    <div class="crm-lead-overview-column crm-lead-progress-column">

        <div class="crm-overview-status-section">
            <span class="crm-overview-label">Lead status</span>

            <span class="tag crm-overview-status-tag <?= e(status_class($workflowStatus)) ?>">
                <?= e($workflowLabels[$workflowStatus] ?? 'New') ?>
            </span>
        </div>

        <?php if (
            in_array(
                $workflowStatus,
                ['visit_interested', 'visit_requested'],
                true
            )
            && $currentMilestoneSchedules
        ): ?>
            <div class="crm-overview-schedule">
                <span class="crm-overview-label">
                    <?= $workflowStatus === 'visit_requested'
                        ? 'Visit request options'
                        : 'Preferred visit options' ?>
                </span>

                <div class="crm-overview-schedule-options">
                    <?php foreach ($currentMilestoneSchedules as $index => $option): ?>
                        <div class="crm-overview-schedule-card">
                            <small>
                                Option #<?= (int)($option['option_number'] ?? ($index + 1)) ?>
                            </small>
                            <strong><?= e(crm_schedule_summary($option)) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif (
            in_array(
                $workflowStatus,
                [
                    'visit_scheduled',
                    'placement_test_scheduled',
                    'joined',
                ],
                true
            )
            && $currentMilestoneSchedules
        ): ?>
            <div class="crm-overview-schedule">
                <?php foreach ($currentMilestoneSchedules as $option): ?>
                    <?php
                    $overviewDate = trim((string)($option['option_date'] ?? ''));
                    $overviewStart = trim((string)($option['start_time'] ?? ''));
                    $overviewEnd = trim((string)($option['end_time'] ?? ''));
                    ?>

                    <div class="crm-overview-schedule-card">
                        <small>
                            <?= e(crm_schedule_type_label(
                                $option['schedule_type'] ?? ''
                            )) ?>
                        </small>

                        <strong class="crm-overview-schedule-date">
                            <?= e(crm_schedule_date($overviewDate ?: null)) ?>
                        </strong>

                        <?php if ($overviewStart !== '' || $overviewEnd !== ''): ?>
                            <strong class="crm-overview-schedule-time">
                                <?php if ($overviewStart !== ''): ?>
                                    <?= e(date('g:i A', strtotime($overviewStart))) ?>
                                <?php endif; ?>

                                <?php if ($overviewEnd !== ''): ?>
                                    <?= $overviewStart !== '' ? ' – ' : 'Until ' ?>
                                    <?= e(date('g:i A', strtotime($overviewEnd))) ?>
                                <?php endif; ?>
                            </strong>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif (
            in_array(
                $workflowStatus,
                ['visited', 'placement_test_completed'],
                true
            )
            && $currentMilestoneFollowup
        ): ?>
            <div class="crm-overview-schedule">
                <div class="crm-overview-schedule-card">
                    <small>
                        <?= $workflowStatus === 'visited'
                            ? 'Visited on'
                            : 'Completed on' ?>
                    </small>

                    <strong>
                        <?= e(fmt_datetime(
                            $currentMilestoneFollowup['followup_date'] ?? null,
                            $currentMilestoneFollowup['followup_time'] ?? null
                        )) ?>
                    </strong>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="crm-lead-overview-column crm-lead-contact-column">

        <div class="crm-overview-contact">
            <span class="crm-overview-label">Contact number</span>

            <?php if (!empty($lead['contact'])): ?>
                <a
                    href="tel:<?= e($lead['contact']) ?>"
                    class="crm-overview-phone"
                >
                    <?= e($lead['contact']) ?>
                </a>
            <?php else: ?>
                <strong class="crm-overview-phone">—</strong>
            <?php endif; ?>
        </div>

        <div class="crm-overview-quality <?= e($quality['class']) ?>">
            <div class="crm-overview-quality-head">
                <span>Lead quality</span>
                <strong><?= (int)$quality['score'] ?>%</strong>
            </div>

            <div
                class="crm-overview-quality-bar"
                role="progressbar"
                aria-label="Lead Quality Score"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= (int)$quality['score'] ?>"
            >
                <span style="width: <?= (int)$quality['score'] ?>%;"></span>
            </div>

            <span class="crm-overview-quality-text">
                <?= e($quality['label']) ?>
            </span>
        </div>

    </div>

</section>


                <!-- Initial inquiries appear immediately after student and parent information -->
        <section class="card inquiry-priority-card" id="inquiries">
            <div class="section-title-row compact-title-row">
                <div>
                    <div class="eyebrow">Parent requirements</div>
                    <h2>📌 Initial inquiries</h2>
                </div>
                <?php if (is_admin()): ?>
                    <a href="lead_form.php?id=<?= $id ?>#inquiries" class="btn btn-sm btn-outline">Edit inquiries</a>
                <?php endif; ?>
            </div>

            <?php if ($inquiries): ?>
                <div class="inquiry-list">
                    <?php foreach ($inquiries as $inquiry): ?>
                        <article class="inquiry-item">
                            <div class="inquiry-copy">
                                <strong><?= e($inquiry['inquiry_title']) ?></strong>
                                <?php if (!empty($inquiry['inquiry_details'])): ?>
                                    <div class="inquiry-details"><?= nl2br(e($inquiry['inquiry_details'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="tag <?= crm_inquiry_class($inquiry['inquiry_status']) ?>">
                                <?= e(crm_inquiry_label($inquiry['inquiry_status'])) ?>
                            </span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($lead['inquiry_notes'])): ?>
                <article class="inquiry-item">
                    <div class="inquiry-copy">
                        <strong>Initial inquiry</strong>
                        <div class="inquiry-details"><?= nl2br(e($lead['inquiry_notes'])) ?></div>
                    </div>
                    <span class="tag tag-grey">Pending</span>
                </article>
            <?php else: ?>
                <div class="empty-inquiry-state">No initial inquiries have been recorded.</div>
            <?php endif; ?>
        </section>

        <section class="card key-details-card">
            <div class="section-title-row compact-title-row">
                <div>
                    <div class="eyebrow">Admission information</div>
                    <h2>Key details</h2>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-row"><span>Grade</span><strong><?= e($lead['grade']) ?: '—' ?></strong></div>
                <div class="detail-row"><span>Current school</span><strong><?= e($lead['current_school']) ?: '—' ?></strong></div>
                <div class="detail-row"><span>Location</span><strong><?= e($lead['location']) ?: '—' ?></strong></div>
                <?php if (!empty($lead['fb_name'])): ?>
                    <div class="detail-row"><span>Facebook name</span><strong><?= e($lead['fb_name']) ?></strong></div>
                <?php endif; ?>
                <div class="detail-row"><span>Transfer timing</span><strong><?= e(ucwords(str_replace('_', ' ', $lead['transfer_period'] ?: '—'))) ?></strong></div>
                <div class="detail-row"><span>Reason for transfer</span><strong><?= e(ucwords(str_replace('_', ' ', $lead['reason'] ?: '—'))) ?></strong></div>
                <div class="detail-row"><span>Visit date</span><strong><?= fmt_date($lead['visit_date']) ?></strong></div>
                <div class="detail-row"><span>Converted date</span><strong><?= fmt_date($lead['converted_date']) ?></strong></div>
            </div>
        </section>

    </main>

    <!-- Right: full-height follow-up plan -->
    <aside class="lead-followup-column">
        <section class="card followup-timeline-card">
            <div class="followup-panel-header">
                <div>
                    <div class="eyebrow">Activity history</div>
                    <h2>Follow-up timeline</h2>
                    <div class="timeline-summary-text">
                        <?= $followupCount ?> follow-up<?= $followupCount === 1 ? '' : 's' ?>
                    </div>
                </div>
                <?php if ($latestFollowup): ?>
                    <span class="timeline-last-contact-chip">
                        Last contact <?= e(crm_time_ago($latestFollowup['followup_date'], $latestFollowup['followup_time'])) ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$followups): ?>
                <div class="empty-followup-state">No follow-ups logged yet.</div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($followups as $f): ?>
                        <?php
                        $followupType = $f['followup_type'] ?? 'phone_call';
                        $outcome = $f['outcome'] ?? 'still_considering';
                        $savedStatus = $f['lead_status'] ?? 'new';
                        $followupTypeLabel = $followupTypeLabels[$followupType] ?? 'Follow-up';
                        $outcomeLabel = $outcomeLabels[$outcome] ?? ucwords(str_replace('_', ' ', $outcome));
                        $timeAgo = crm_time_ago($f['followup_date'], $f['followup_time']);

                        $timelineResponseClassMap = [
                            'interested' => 'timeline-response-interested',
                            'positive' => 'timeline-response-interested',
                            'still_considering' => 'timeline-response-considering',
                            'pending' => 'timeline-response-considering',
                            'call_back_later' => 'timeline-response-considering',
                            'will_call_back' => 'timeline-response-considering',
                            'no_response' => 'timeline-response-no-response',
                            'not_reached' => 'timeline-response-no-response',
                            'number_not_working' => 'timeline-response-no-response',
                            'not_interested' => 'timeline-response-negative',
                            'wrong_lead' => 'timeline-response-negative',
                            'accidental_lead' => 'timeline-response-negative',
                            'job_inquiry' => 'timeline-response-negative',
                            'rejected' => 'timeline-response-negative',
                        ];

                        $timelineResponseClass = $timelineResponseClassMap[$outcome]
                            ?? 'timeline-response-neutral';

                        $nextFollowupStatus = crm_next_followup_status(
                            $f,
                            $followups
                        );
                        ?>

                        <article
                            class="timeline-item followup-<?= e($followupType) ?> <?= e($timelineResponseClass) ?>"
                            data-num="<?= (int)$f['followup_number'] ?>"
                        >
                           

                            <div class="followup-card-topline">
                                <div class="followup-method-wrap">
                                    <span class="followup-type"><?= e($followupTypeLabel) ?></span>
                                    <span class="followup-age-chip"><?= e($timeAgo) ?></span>
                                </div>
                                <span class="tag <?= status_class($savedStatus) ?>"><?= status_label($savedStatus) ?></span>
                            </div>

                            <div class="followup-meta-line">
                                <span><?= fmt_datetime($f['followup_date'], $f['followup_time']) ?></span>
                                <span class="outcome-badge outcome-<?= e($outcome) ?>"><?= e($outcomeLabel) ?></span>
                            </div>

                            <?php if (!empty($f['notes'])): ?>
                                <div class="notes"><?= nl2br(e($f['notes'])) ?></div>
                            <?php endif; ?>

                            <?php
                            $savedScheduleOptions = $scheduleOptionsByFollowup[(int)$f['id']] ?? [];
                            ?>

                            <?php if ($savedScheduleOptions): ?>
                                <div class="followup-schedule-summary">
                                    <?php foreach ($savedScheduleOptions as $scheduleOption): ?>
                                        <div class="followup-schedule-entry">
                                            <span class="followup-schedule-label">
                                                <?= e(crm_schedule_type_label($scheduleOption['schedule_type'] ?? '')) ?>
                                                <?php if (
                                                    ($scheduleOption['schedule_type'] ?? '') === 'visit_preference'
                                                    && !empty($scheduleOption['option_number'])
                                                ): ?>
                                                    #<?= (int)$scheduleOption['option_number'] ?>
                                                <?php endif; ?>
                                            </span>

                                            <strong>
                                                <?= e(crm_schedule_summary($scheduleOption)) ?>
                                            </strong>

                                            <?php if (!empty($scheduleOption['notes'])): ?>
                                                <small><?= nl2br(e($scheduleOption['notes'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($f['next_action_date'])): ?>
                                <div class="next-followup <?= e($nextFollowupStatus['class']) ?>">
                                    <div class="next-followup-heading">
                                        <span>Next follow-up</span>

                                        <span class="next-followup-status">
                                            <?= e($nextFollowupStatus['label']) ?>
                                        </span>
                                    </div>

                                    <strong>
                                        <?= e(fmt_date($f['next_action_date'])) ?>

                                        <?php if (!empty($f['next_action_time'])): ?>
                                            <span class="next-followup-time">
                                                · <?= e(date(
                                                    'g:i A',
                                                    strtotime($f['next_action_time'])
                                                )) ?>
                                            </span>
                                        <?php endif; ?>
                                    </strong>
                                </div>
                            <?php endif; ?>

                            <?php if (is_admin()): ?>
                                <div class="actions timeline-card-actions">
                                    <button
                                        type="button"
                                        class="timeline-icon-action timeline-edit-action"
                                        aria-label="Edit follow-up"
                                        title="Edit follow-up"
                                        onclick='openFollowupModal(<?= json_encode([
                                            "id" => (int)$f["id"],
                                            "date" => $f["followup_date"] ?? "",
                                            "time" => $f["followup_time"] ?? "",
                                            "type" => $followupType,
                                            "outcome" => $outcome,
                                            "lead_status" => $savedStatus,
                                            "notes" => $f["notes"] ?? "",
                                            "next_date" => $f["next_action_date"] ?? "",
                                            "next_time" => $f["next_action_time"] ?? "",
                                            "schedule_options" => array_values($savedScheduleOptions),
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                            focusable="false"
                                        >
                                            <path
                                                d="M4 20h4.2L19 9.2 14.8 5 4 15.8V20Zm2-3.4 8.8-8.8 1.4 1.4-8.8 8.8H6v-1.4ZM16.2 3.6l1.2-1.2a1.4 1.4 0 0 1 2 0l2.2 2.2a1.4 1.4 0 0 1 0 2l-1.2 1.2-4.2-4.2Z"
                                            />
                                        </svg>
                                    </button>

                                    <button
                                        type="button"
                                        class="timeline-icon-action timeline-delete-action"
                                        aria-label="Delete follow-up"
                                        title="Delete follow-up"
                                        onclick="openRemoveFollowupModal(
                                            <?= (int)$f['id'] ?>,
                                            <?= (int)$f['followup_number'] ?>
                                        )"
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                            focusable="false"
                                        >
                                            <path
                                                d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-2 6h10l-.8 11H7.8L7 9Zm3 2v7h2v-7h-2Zm4 0v7h2v-7h-2Z"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </aside>

</div>

<?php if (is_admin()): ?>

<style>
.schedule-time-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, .8fr) minmax(0, .8fr);
    gap: 12px;
}

@media (max-width: 780px) {
    .schedule-time-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div id="followupModal" class="followup-modal" aria-hidden="true">

    <div
        class="followup-modal-backdrop"
        onclick="closeFollowupModal()"
    ></div>

    <div
        class="followup-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="followupModalTitle"
    >

        <div class="followup-modal-header">

            <div>
                <div class="eyebrow">
                    Lead #<?= (int)$lead['id'] ?>
                </div>

                <h2 id="followupModalTitle">
                    Add follow-up
                </h2>
            </div>

            <button
                type="button"
                class="modal-close"
                aria-label="Close"
                onclick="closeFollowupModal()"
            >
                ×
            </button>

        </div>

        <form
            method="post"
            action="followup_action.php"
            class="followup-main-form"
            id="followupForm"
        >

            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="followup_action" value="add">
            <input type="hidden" name="lead_id" value="<?= $id ?>">
            <input type="hidden" name="followup_id" id="followup_id" value="">
            <input type="hidden" name="source_reminder_id" id="source_reminder_id" value="">

            <div class="followup-two-column">

                <section class="followup-box">

                    <div class="followup-box-header">
                        <span class="followup-step">1</span>

                        <div>
                            <h3>Follow-up activity</h3>
                            <p>How and when the parent was contacted.</p>
                        </div>
                    </div>

                    <div class="followup-row">

                        <div class="field">
                            <label for="followup_date">Follow-up date</label>

                            <input
                                type="date"
                                id="followup_date"
                                name="followup_date"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="followup_time">Follow-up time</label>

                            <input
                                type="time"
                                id="followup_time"
                                name="followup_time"
                            >
                        </div>

                    </div>

                    <div class="field">
                        <label for="followup_type">Follow-up type</label>

                        <select id="followup_type" name="followup_type" required>
                            <option value="phone_call">Phone Follow-up</option>
                            <option value="whatsapp_admission">WhatsApp Admission Reminder</option>
                            <option value="whatsapp_engagement">WhatsApp Engagement Content</option>
                            <option value="physical_followup">Physical Follow-up</option>
                            <option value="call_engagement">Call Engagement</option>
                            <option value="general_followup">General Follow-up</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="notes">What happened / content sent</label>

                        <textarea
                            id="notes"
                            name="notes"
                            rows="7"
                            placeholder="Example: Called the parent and explained admission fees, transport and school facilities."
                            required
                        ></textarea>
                    </div>

                </section>

                <section class="followup-box followup-box-secondary">

                    <div class="followup-box-header">
                        <span class="followup-step">2</span>

                        <div>
                            <h3>Response and next action</h3>
                            <p>Update the lead and schedule the next step.</p>
                        </div>
                    </div>

                    <div class="field">
                        <label for="outcome">Parent response</label>

                        <select id="outcome" name="outcome" required>
                            <option value="interested">Interested</option>
                            <option value="still_considering" selected>Still Considering</option>
                            <option value="call_back_later">Call Back Later</option>
                            <option value="will_call_back">Will Call Back</option>
                            <option value="no_response">No Response</option>
                            <option value="not_reached">Not Reached</option>
                            <option value="number_not_working">Number Not Working</option>
                            <option value="not_interested">Not Interested</option>
                            <option value="wrong_lead">Wrong Lead</option>
                            <option value="accidental_lead">Accidental Lead</option>
                            <option value="job_inquiry">Job Inquiry</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="lead_status">Update lead status</label>

                        <select id="lead_status" name="lead_status" required>
                            <?php foreach ($workflowLabels as $key => $label): ?>
                                <option
                                    value="<?= e($key) ?>"
                                    <?= $workflowStatus === $key ? 'selected' : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="workflowScheduleFields" class="workflow-schedule-fields" hidden>

                        <div
                            id="visitPreferenceFields"
                            class="workflow-schedule-section"
                            hidden
                        >
                            <div class="workflow-schedule-heading">
                                <div>
                                    <h4>Preferred visit options</h4>
                                    <p>
                                        Add the dates, start times and end times the parent is available.
                                        You can add more than one option.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline"
                                    id="addVisitOptionButton"
                                    onclick="addVisitPreferenceRow()"
                                >
                                    + Add option
                                </button>
                            </div>

                            <div id="visitPreferenceRows"></div>
                        </div>

                        <div
                            id="confirmedVisitFields"
                            class="workflow-schedule-section"
                            hidden
                        >
                            <h4>Confirmed visit schedule</h4>

                            <div class="schedule-time-grid">
                                <div class="field">
                                    <label for="confirmed_visit_date">
                                        Confirmed visit date
                                    </label>
                                    <input
                                        type="date"
                                        id="confirmed_visit_date"
                                        name="confirmed_visit_date"
                                    >
                                </div>

                                <div class="field">
                                    <label for="confirmed_visit_start_time">
                                        Start time
                                    </label>
                                    <input
                                        type="time"
                                        id="confirmed_visit_start_time"
                                        name="confirmed_visit_start_time"
                                    >
                                </div>

                                <div class="field">
                                    <label for="confirmed_visit_end_time">
                                        End time
                                    </label>
                                    <input
                                        type="time"
                                        id="confirmed_visit_end_time"
                                        name="confirmed_visit_end_time"
                                    >
                                </div>
                            </div>

                            <div class="field">
                                <label for="confirmed_visit_time_note">
                                    Flexible time note (optional)
                                </label>
                                <input
                                    type="text"
                                    id="confirmed_visit_time_note"
                                    name="confirmed_visit_time_note"
                                    maxlength="150"
                                    placeholder="Example: Parent prefers morning if possible"
                                >
                            </div>
                        </div>

                        <div
                            id="placementTestFields"
                            class="workflow-schedule-section"
                            hidden
                        >
                            <h4>Placement test schedule</h4>

                            <div class="schedule-time-grid">
                                <div class="field">
                                    <label for="placement_test_date">
                                        Placement test date
                                    </label>
                                    <input
                                        type="date"
                                        id="placement_test_date"
                                        name="placement_test_date"
                                    >
                                </div>

                                <div class="field">
                                    <label for="placement_test_start_time">
                                        Start time
                                    </label>
                                    <input
                                        type="time"
                                        id="placement_test_start_time"
                                        name="placement_test_start_time"
                                    >
                                </div>

                                <div class="field">
                                    <label for="placement_test_end_time">
                                        End time
                                    </label>
                                    <input
                                        type="time"
                                        id="placement_test_end_time"
                                        name="placement_test_end_time"
                                    >
                                </div>
                            </div>

                            <div class="field">
                                <label for="placement_test_time_note">
                                    Flexible time note (optional)
                                </label>
                                <input
                                    type="text"
                                    id="placement_test_time_note"
                                    name="placement_test_time_note"
                                    maxlength="150"
                                    placeholder="Example: Student is available before 1:00 PM"
                                >
                            </div>
                        </div>

                        <div
                            id="enrollmentFields"
                            class="workflow-schedule-section"
                            hidden
                        >
                            <h4>Enrollment information</h4>

                            <div class="field">
                                <label for="enrollment_date">Enrollment date</label>
                                <input
                                    type="date"
                                    id="enrollment_date"
                                    name="enrollment_date"
                                >
                            </div>
                        </div>

                    </div>

                    <div class="next-followup-box">
                        <h4>Schedule next follow-up</h4>

                        <div class="followup-row">

                            <div class="field">
                                <label for="next_action_date">Date</label>

                                <input
                                    type="date"
                                    id="next_action_date"
                                    name="next_action_date"
                                >
                            </div>

                            <div class="field">
                                <label for="next_action_time">Time</label>

                                <input
                                    type="time"
                                    id="next_action_time"
                                    name="next_action_time"
                                >
                            </div>

                        </div>
                    </div>

                </section>

            </div>

            <div class="followup-form-actions">

                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeFollowupModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-brass"
                    id="followupSubmitButton"
                >
                    Save follow-up
                </button>

            </div>

        </form>

    </div>

</div>




<div id="reminderListModal" class="followup-modal reminder-list-modal" aria-hidden="true">
    <div
        class="followup-modal-backdrop"
        onclick="closeReminderListModal()"
    ></div>

    <div
        class="followup-modal-dialog reminder-list-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reminderListTitle"
    >
        <div class="followup-modal-header reminder-list-header">
            <div class="reminder-popup-heading">
                <span class="reminder-popup-bell" aria-hidden="true">🔔</span>
                <div>
                    <h2 id="reminderListTitle">Reminders</h2>
                    <p>
                        <?= $reminderCount ?>
                        pending reminder<?= $reminderCount === 1 ? '' : 's' ?>
                    </p>
                </div>
            </div>

            <div class="reminder-list-header-actions">
                <?php if (is_admin()): ?>
                    <button
                        type="button"
                        class="btn btn-sm btn-brass"
                        onclick="openAddReminderModal()"
                    >
                        + Add Reminder
                    </button>
                <?php endif; ?>

                <button
                    type="button"
                    class="modal-close"
                    aria-label="Close"
                    onclick="closeReminderListModal()"
                >
                    ×
                </button>
            </div>
        </div>

        <div class="reminder-popup-content">
            <?php if (!$reminders): ?>
                <div class="reminder-popup-empty">
                    <span aria-hidden="true">✓</span>
                    <strong>No reminders found</strong>
                    <p>There are no reminder records for this lead.</p>
                </div>
            <?php else: ?>

                <div class="reminder-active-heading">
                    Active reminders (<?= count($activeReminders) ?>)
                </div>

                <?php if (!$activeReminders): ?>
                    <div class="reminder-popup-empty">
                        <span aria-hidden="true">✓</span>
                        <strong>No active reminders</strong>
                        <p>All reminders have been completed or dismissed.</p>
                    </div>
                <?php else: ?>
                    <div class="reminder-popup-list">
                        <?php foreach ($activeReminders as $reminder): ?>
                            <?php
                            $urgency = crm_reminder_urgency(
                                $reminder['reminder_date'] ?? null,
                                $reminder['reminder_time'] ?? null
                            );

                            $reminderDisplayDate = fmt_datetime(
                                $reminder['reminder_date'] ?? null,
                                $reminder['reminder_time'] ?? null
                            );

                            $reminderPriority = $reminder['priority'] ?? 'normal';
                            $reminderSourceKey = crm_reminder_source_key($reminder);
                            $isManualReminder = $reminderSourceKey === 'manual';
                            $canCompleteFollowup = !$isManualReminder
                                && !empty($reminder['followup_id']);
                            ?>

                            <article class="reminder-popup-item <?= e($urgency['class']) ?> reminder-source-<?= e($reminderSourceKey) ?>">
                                <div class="reminder-popup-item-top">
                                    <div class="reminder-popup-copy">
                                        <span class="reminder-source-badge">
                                            <span aria-hidden="true"><?= e(crm_reminder_source_icon($reminder)) ?></span>
                                            <?= e(crm_reminder_source_label($reminder)) ?>
                                        </span>

                                        <h3><?= e($reminder['title'] ?: 'Reminder') ?></h3>

                                        <div class="reminder-popup-date">
                                            <strong><?= e($reminderDisplayDate) ?></strong>
                                            <span>•</span>
                                            <span><?= e($urgency['relative']) ?></span>
                                        </div>

                                        <span class="reminder-priority reminder-priority-<?= e($reminderPriority) ?>">
                                            <?= e(ucwords($reminderPriority)) ?> priority
                                        </span>
                                    </div>

                                    <div class="reminder-popup-badges">
                                        <span class="tag tag-orange-light">Pending</span>
                                        <span class="reminder-urgency-badge">
                                            <?= e($urgency['label']) ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if (!empty($reminder['notes'])): ?>
                                    <div class="reminder-popup-note">
                                        <?= nl2br(e($reminder['notes'])) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (is_admin()): ?>
                                    <div class="reminder-popup-actions">
                                        <?php if ($canCompleteFollowup): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm reminder-open-followup"
                                                onclick='openFollowupFromReminder(<?= json_encode([
                                                    "id" => (int)$reminder["id"],
                                                    "title" => $reminder["title"] ?? "Follow up with parent",
                                                    "notes" => $reminder["notes"] ?? "",
                                                    "date" => $reminder["reminder_date"] ?? "",
                                                    "time" => $reminder["reminder_time"] ?? "",
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                            >
                                                Complete Follow-up
                                            </button>
                                        <?php endif; ?>

                                        <form method="post" action="reminder_action.php">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="lead_id" value="<?= $id ?>">
                                            <input type="hidden" name="reminder_id" value="<?= (int)$reminder['id'] ?>">

                                            <button type="submit" class="btn btn-sm btn-brass">
                                                Acknowledge
                                            </button>
                                        </form>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline"
                                            onclick='openReminderRescheduleFromList(<?= json_encode([
                                                "id" => (int)$reminder["id"],
                                                "date" => $reminder["reminder_date"] ?? "",
                                                "time" => $reminder["reminder_time"] ?? "",
                                                "notes" => $reminder["notes"] ?? "",
                                                "title" => $reminder["title"] ?? "Reminder",
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                        >
                                            Reschedule
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            onclick='openReminderDismissFromList(<?= json_encode([
                                                "id" => (int)$reminder["id"],
                                                "title" => $reminder["title"] ?? "Reminder",
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'
                                        >
                                            Dismiss
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <details class="reminder-history-section">
                    <summary>
                        <span>Completed</span>
                        <span class="reminder-history-count"><?= count($completedReminders) ?></span>
                    </summary>

                    <?php if (!$completedReminders): ?>
                        <div class="reminder-history-empty">No completed reminders.</div>
                    <?php else: ?>
                        <div class="reminder-history-list">
                            <?php foreach ($completedReminders as $reminder): ?>
                                <?php $sourceKey = crm_reminder_source_key($reminder); ?>
                                <article class="reminder-popup-item reminder-status-completed reminder-source-<?= e($sourceKey) ?>">
                                    <div class="reminder-popup-item-top">
                                        <div class="reminder-popup-copy">
                                            <span class="reminder-source-badge">
                                                <span aria-hidden="true"><?= e(crm_reminder_source_icon($reminder)) ?></span>
                                                <?= e(crm_reminder_source_label($reminder)) ?>
                                            </span>
                                            <h3><?= e($reminder['title'] ?: 'Reminder') ?></h3>
                                            <div class="reminder-popup-date">
                                                <strong><?= e(fmt_datetime($reminder['reminder_date'] ?? null, $reminder['reminder_time'] ?? null)) ?></strong>
                                            </div>
                                        </div>
                                        <span class="tag tag-green-light">Completed</span>
                                    </div>

                                    <?php if (!empty($reminder['notes'])): ?>
                                        <div class="reminder-popup-note"><?= nl2br(e($reminder['notes'])) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($reminder['completed_at'])): ?>
                                        <div class="reminder-history-meta">
                                            Completed:
                                            <strong><?= e(fmt_datetime(
                                                substr((string)$reminder['completed_at'], 0, 10),
                                                substr((string)$reminder['completed_at'], 11, 8)
                                            )) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>

                <details class="reminder-history-section">
                    <summary>
                        <span>Dismissed</span>
                        <span class="reminder-history-count"><?= count($dismissedReminders) ?></span>
                    </summary>

                    <?php if (!$dismissedReminders): ?>
                        <div class="reminder-history-empty">No dismissed reminders.</div>
                    <?php else: ?>
                        <div class="reminder-history-list">
                            <?php foreach ($dismissedReminders as $reminder): ?>
                                <?php $sourceKey = crm_reminder_source_key($reminder); ?>
                                <article class="reminder-popup-item reminder-status-dismissed reminder-source-<?= e($sourceKey) ?>">
                                    <div class="reminder-popup-item-top">
                                        <div class="reminder-popup-copy">
                                            <span class="reminder-source-badge">
                                                <span aria-hidden="true"><?= e(crm_reminder_source_icon($reminder)) ?></span>
                                                <?= e(crm_reminder_source_label($reminder)) ?>
                                            </span>
                                            <h3><?= e($reminder['title'] ?: 'Reminder') ?></h3>
                                            <div class="reminder-popup-date">
                                                <strong><?= e(fmt_datetime($reminder['reminder_date'] ?? null, $reminder['reminder_time'] ?? null)) ?></strong>
                                            </div>
                                        </div>
                                        <span class="tag tag-grey">Dismissed</span>
                                    </div>

                                    <?php if (!empty($reminder['notes'])): ?>
                                        <div class="reminder-popup-note"><?= nl2br(e($reminder['notes'])) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($reminder['dismissed_at'])): ?>
                                        <div class="reminder-history-meta">
                                            Dismissed:
                                            <strong><?= e(fmt_datetime(
                                                substr((string)$reminder['dismissed_at'], 0, 10),
                                                substr((string)$reminder['dismissed_at'], 11, 8)
                                            )) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>

            <?php endif; ?>
        </div>
    </div>
</div>


<div id="addReminderModal" class="followup-modal" aria-hidden="true">
    <div class="followup-modal-backdrop" onclick="closeAddReminderModal()"></div>

    <div
        class="followup-modal-dialog reminder-add-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="addReminderTitle"
    >
        <div class="followup-modal-header">
            <div>
                <div class="eyebrow">New task</div>
                <h2 id="addReminderTitle">Add reminder</h2>
                <p class="remove-followup-reference">
                    Create a standalone reminder for this lead.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                aria-label="Close"
                onclick="closeAddReminderModal()"
            >×</button>
        </div>

        <form method="post" action="reminder_action.php" id="addReminderForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="lead_id" value="<?= $id ?>">

            <div class="reminder-form-grid">
                <div class="field">
                    <label for="new_reminder_type">Reminder type *</label>
                    <select name="reminder_type" id="new_reminder_type" required>
                        <option value="general">General</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="phone_call">Phone Call</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="visit">Visit</option>
                        <option value="placement_test">Placement Test</option>
                        <option value="document_collection">Document Collection</option>
                        <option value="payment">Payment</option>
                    </select>
                </div>

                <div class="field">
                    <label for="new_reminder_priority">Priority *</label>
                    <select name="priority" id="new_reminder_priority" required>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="new_reminder_title">Reminder title *</label>
                <input
                    type="text"
                    name="title"
                    id="new_reminder_title"
                    maxlength="150"
                    required
                    placeholder="Example: Send market analysis"
                >
            </div>

            <div class="reminder-form-grid">
                <div class="field">
                    <label for="new_reminder_date">Date *</label>
                    <input
                        type="date"
                        name="reminder_date"
                        id="new_reminder_date"
                        required
                    >
                </div>

                <div class="field">
                    <label for="new_reminder_time">Time</label>
                    <input
                        type="time"
                        name="reminder_time"
                        id="new_reminder_time"
                    >
                </div>
            </div>

            <div class="field">
                <label for="new_reminder_notes">Notes</label>
                <textarea
                    name="notes"
                    id="new_reminder_notes"
                    rows="5"
                    maxlength="500"
                    placeholder="Add instructions or useful details."
                ></textarea>
            </div>

            <div class="remove-followup-actions">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeAddReminderModal()"
                >Cancel</button>

                <button type="submit" class="btn btn-brass">
                    Save reminder
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reminderRescheduleModal" class="followup-modal" aria-hidden="true">
    <div
        class="followup-modal-backdrop"
        onclick="closeReminderRescheduleModal()"
    ></div>

    <div
        class="followup-modal-dialog remove-followup-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reminderRescheduleTitle"
    >
        <div class="followup-modal-header">
            <div>
                <div class="eyebrow">Reminder</div>
                <h2 id="reminderRescheduleTitle">Reschedule reminder</h2>
                <p
                    id="reminderRescheduleReference"
                    class="remove-followup-reference"
                ></p>
            </div>

            <button
                type="button"
                class="modal-close"
                aria-label="Close"
                onclick="closeReminderRescheduleModal()"
            >
                ×
            </button>
        </div>

        <form method="post" action="reminder_action.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="lead_id" value="<?= $id ?>">
            <input
                type="hidden"
                name="reminder_id"
                id="reschedule_reminder_id"
            >

            <div class="reminder-modal-grid">
                <div class="field">
                    <label for="reschedule_reminder_date">New date *</label>
                    <input
                        type="date"
                        name="reminder_date"
                        id="reschedule_reminder_date"
                        required
                    >
                </div>

                <div class="field">
                    <label for="reschedule_reminder_time">New time</label>
                    <input
                        type="time"
                        name="reminder_time"
                        id="reschedule_reminder_time"
                    >
                </div>
            </div>

            <div class="field">
                <label for="reschedule_reminder_notes">Reminder note</label>
                <textarea
                    name="notes"
                    id="reschedule_reminder_notes"
                    rows="4"
                    maxlength="500"
                    placeholder="Optional reminder note"
                ></textarea>
            </div>

            <div class="remove-followup-actions">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeReminderRescheduleModal()"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-brass">
                    Save new time
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reminderDismissModal" class="followup-modal" aria-hidden="true">
    <div
        class="followup-modal-backdrop"
        onclick="closeReminderDismissModal()"
    ></div>

    <div
        class="followup-modal-dialog remove-followup-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reminderDismissTitle"
    >
        <div class="followup-modal-header">
            <div>
                <div class="eyebrow">Reminder</div>
                <h2 id="reminderDismissTitle">Dismiss reminder</h2>
                <p
                    id="reminderDismissReference"
                    class="remove-followup-reference"
                ></p>
            </div>

            <button
                type="button"
                class="modal-close"
                aria-label="Close"
                onclick="closeReminderDismissModal()"
            >
                ×
            </button>
        </div>

        <form method="post" action="reminder_action.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="lead_id" value="<?= $id ?>">
            <input
                type="hidden"
                name="reminder_id"
                id="dismiss_reminder_id"
            >

            <div class="field">
                <label for="dismiss_reason">Dismissal reason</label>
                <select name="dismiss_reason" id="dismiss_reason">
                    <option value="">No reason selected</option>
                    <option value="already_completed">Already completed</option>
                    <option value="duplicate">Duplicate reminder</option>
                    <option value="no_longer_required">No longer required</option>
                    <option value="parent_unavailable">Parent unavailable</option>
                    <option value="created_by_mistake">Created by mistake</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="field">
                <label for="dismiss_note">Optional note</label>
                <textarea
                    name="dismiss_note"
                    id="dismiss_note"
                    rows="4"
                    maxlength="500"
                    placeholder="Add more details if needed."
                ></textarea>
            </div>

            <div class="remove-followup-actions">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeReminderDismissModal()"
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-danger">
                    Dismiss reminder
                </button>
            </div>
        </form>
    </div>
</div>

<div id="removeFollowupModal" class="followup-modal" aria-hidden="true">
    <div class="followup-modal-backdrop" onclick="closeRemoveFollowupModal()"></div>

    <div
        class="followup-modal-dialog remove-followup-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="removeFollowupTitle"
    >
        <div class="followup-modal-header">
            <div>
                <div class="eyebrow">Remove follow-up</div>
                <h2 id="removeFollowupTitle">
                    Are you sure you want to permanently remove this follow-up?
                </h2>
                <p id="removeFollowupReference" class="remove-followup-reference"></p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeRemoveFollowupModal()"
                aria-label="Close"
            >×</button>
        </div>

        <form method="post" action="followup_action.php" id="removeFollowupForm">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="lead_id" value="<?= $id ?>">
            <input type="hidden" name="followup_id" id="remove_followup_id">

            <div class="remove-followup-warning">
                This action cannot be undone. Remaining follow-ups will be
                renumbered automatically.
            </div>

            <div class="field">
                <label for="removal_reason">Removal reason *</label>
                <select
                    name="removal_reason"
                    id="removal_reason"
                    required
                    onchange="toggleRemovalOtherNote()"
                >
                    <option value="">Select a reason</option>
                    <option value="added_by_mistake">Added by mistake</option>
                    <option value="wrong_followup_details">Wrong follow-up details</option>
                    <option value="duplicate_followup">Duplicate follow-up</option>
                    <option value="wrong_lead">Added to the wrong lead</option>
                    <option value="test_entry">Test entry</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="field" id="removalOtherField" hidden>
                <label for="removal_note">Optional note</label>
                <textarea
                    name="removal_note"
                    id="removal_note"
                    rows="4"
                    maxlength="500"
                    placeholder="Add more details about the removal."
                ></textarea>
            </div>

            <div class="remove-followup-actions">
                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeRemoveFollowupModal()"
                >Cancel</button>

                <button
                    type="submit"
                    class="btn remove-confirm-button"
                >Permanently remove</button>
            </div>
        </form>
    </div>
</div>

<script>
function localDateValue(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0')
    ].join('-');
}

function localTimeValue(date) {
    return (
        String(date.getHours()).padStart(2, '0')
        + ':'
        + String(date.getMinutes()).padStart(2, '0')
    );
}


let visitPreferenceRowCount = 0;

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function normalizeTimeValue(value) {
    const time = String(value || '').trim();

    if (time === '') {
        return '';
    }

    return time.substring(0, 5);
}

function addVisitPreferenceRow(option = {}) {
    const container = document.getElementById('visitPreferenceRows');

    if (!container) {
        return;
    }

    visitPreferenceRowCount += 1;

    const row = document.createElement('div');
    row.className = 'visit-preference-row';
    row.dataset.rowId = String(visitPreferenceRowCount);

    row.innerHTML = `
        <div class="visit-preference-row-heading">
            <strong class="visit-option-title">Option</strong>

            <button
                type="button"
                class="btn btn-sm btn-danger remove-visit-option"
                aria-label="Remove this visit option"
            >
                Remove
            </button>
        </div>

        <div class="schedule-time-grid">
            <div class="field">
                <label>Preferred date</label>
                <input
                    type="date"
                    name="visit_preference_date[]"
                    value="${escapeHtml(option.option_date || '')}"
                >
            </div>

            <div class="field">
                <label>Start time</label>
                <input
                    type="time"
                    name="visit_preference_start_time[]"
                    value="${escapeHtml(normalizeTimeValue(option.start_time || ''))}"
                >
            </div>

            <div class="field">
                <label>End time</label>
                <input
                    type="time"
                    name="visit_preference_end_time[]"
                    value="${escapeHtml(normalizeTimeValue(option.end_time || ''))}"
                >
            </div>
        </div>

        <div class="field">
            <label>Flexible time note (optional)</label>
            <input
                type="text"
                name="visit_preference_time_note[]"
                maxlength="150"
                value="${escapeHtml(option.time_note || option.time_window || '')}"
                placeholder="Example: Before 1:00 PM or morning preferred"
            >
        </div>

        <div class="field">
            <label>General note (optional)</label>
            <input
                type="text"
                name="visit_preference_notes[]"
                maxlength="500"
                value="${escapeHtml(option.notes || '')}"
                placeholder="Example: Parent prefers Saturday if possible"
            >
        </div>
    `;

    row.querySelector('.remove-visit-option').addEventListener(
        'click',
        function () {
            row.remove();
            refreshVisitPreferenceRows();
        }
    );

    container.appendChild(row);
    refreshVisitPreferenceRows();
}

function refreshVisitPreferenceRows() {
    const rows = Array.from(
        document.querySelectorAll('#visitPreferenceRows .visit-preference-row')
    );

    rows.forEach(function (row, index) {
        const title = row.querySelector('.visit-option-title');
        const removeButton = row.querySelector('.remove-visit-option');

        if (title) {
            title.textContent = 'Option ' + (index + 1);
        }

        if (removeButton) {
            removeButton.hidden = rows.length === 1;
        }
    });
}

function resetWorkflowScheduleFields() {
    const rows = document.getElementById('visitPreferenceRows');

    if (rows) {
        rows.innerHTML = '';
    }

    visitPreferenceRowCount = 0;

    [
        'confirmed_visit_date',
        'confirmed_visit_start_time',
        'confirmed_visit_end_time',
        'confirmed_visit_time_note',
        'placement_test_date',
        'placement_test_start_time',
        'placement_test_end_time',
        'placement_test_time_note',
        'enrollment_date'
    ].forEach(function (id) {
        const input = document.getElementById(id);

        if (input) {
            input.value = '';
        }
    });
}

function groupScheduleOptions(options) {
    const grouped = {
        visit_preference: [],
        confirmed_visit: [],
        placement_test: [],
        enrollment: []
    };

    (Array.isArray(options) ? options : []).forEach(function (option) {
        const type = option.schedule_type;

        if (grouped[type]) {
            grouped[type].push(option);
        }
    });

    return grouped;
}

function loadWorkflowScheduleFields(options) {
    resetWorkflowScheduleFields();

    const grouped = groupScheduleOptions(options);

    if (grouped.visit_preference.length) {
        grouped.visit_preference.forEach(addVisitPreferenceRow);
    } else {
        addVisitPreferenceRow();
    }

    const confirmedVisit = grouped.confirmed_visit[0] || {};
    const placementTest = grouped.placement_test[0] || {};
    const enrollment = grouped.enrollment[0] || {};

    document.getElementById('confirmed_visit_date').value =
        confirmedVisit.option_date || '';

    document.getElementById('confirmed_visit_start_time').value =
        normalizeTimeValue(confirmedVisit.start_time || '');

    document.getElementById('confirmed_visit_end_time').value =
        normalizeTimeValue(confirmedVisit.end_time || '');

    document.getElementById('confirmed_visit_time_note').value =
        confirmedVisit.time_note || confirmedVisit.time_window || '';

    document.getElementById('placement_test_date').value =
        placementTest.option_date || '';

    document.getElementById('placement_test_start_time').value =
        normalizeTimeValue(placementTest.start_time || '');

    document.getElementById('placement_test_end_time').value =
        normalizeTimeValue(placementTest.end_time || '');

    document.getElementById('placement_test_time_note').value =
        placementTest.time_note || placementTest.time_window || '';

    document.getElementById('enrollment_date').value =
        enrollment.option_date || '';
}

function updateWorkflowScheduleVisibility() {
    const statusSelect = document.getElementById('lead_status');
    const wrapper = document.getElementById('workflowScheduleFields');

    if (!statusSelect || !wrapper) {
        return;
    }

    const status = statusSelect.value;

    const visibility = {
        visitPreferenceFields:
            status === 'visit_interested'
            || status === 'visit_requested',

        confirmedVisitFields:
            status === 'visit_scheduled',

        placementTestFields:
            status === 'placement_test_scheduled',

        enrollmentFields:
            status === 'joined'
    };

    let showWrapper = false;

    Object.entries(visibility).forEach(function ([id, shouldShow]) {
        const section = document.getElementById(id);

        if (section) {
            section.hidden = !shouldShow;
        }

        showWrapper = showWrapper || shouldShow;
    });

    wrapper.hidden = !showWrapper;
}

function openFollowupModal(followup = null, sourceReminder = null) {
    const modal = document.getElementById('followupModal');
    const form = document.getElementById('followupForm');

    if (!modal || !form) {
        return;
    }

    form.reset();

    const now = new Date();
    const isEditing = followup && followup.id;

    document.getElementById('followup_action').value =
        isEditing ? 'edit' : 'add';

    document.getElementById('followup_id').value =
        isEditing ? followup.id : '';

    document.getElementById('source_reminder_id').value =
        sourceReminder && sourceReminder.id
            ? sourceReminder.id
            : '';

    document.getElementById('followupModalTitle').textContent =
        isEditing
            ? 'Edit follow-up'
            : (
                sourceReminder
                    ? 'Complete reminder follow-up'
                    : 'Add follow-up'
            );

    document.getElementById('followupSubmitButton').textContent =
        isEditing
            ? 'Update follow-up'
            : (
                sourceReminder
                    ? 'Save follow-up and acknowledge'
                    : 'Save follow-up'
            );

    document.getElementById('followup_date').value =
        isEditing && followup.date
            ? followup.date
            : localDateValue(now);

    document.getElementById('followup_time').value =
        isEditing && followup.time
            ? followup.time.substring(0, 5)
            : localTimeValue(now);

    document.getElementById('followup_type').value =
        isEditing && followup.type
            ? followup.type
            : 'phone_call';

    document.getElementById('outcome').value =
        isEditing && followup.outcome
            ? followup.outcome
            : 'still_considering';

    document.getElementById('lead_status').value =
        isEditing && followup.lead_status
            ? followup.lead_status
            : <?= json_encode($workflowStatus) ?>;

    document.getElementById('notes').value =
        isEditing && followup.notes
            ? followup.notes
            : (
                sourceReminder && sourceReminder.notes
                    ? sourceReminder.notes
                    : ''
            );

    document.getElementById('next_action_date').value =
        isEditing && followup.next_date
            ? followup.next_date
            : '';

    document.getElementById('next_action_time').value =
        isEditing && followup.next_time
            ? followup.next_time.substring(0, 5)
            : '';

    loadWorkflowScheduleFields(
        isEditing && Array.isArray(followup.schedule_options)
            ? followup.schedule_options
            : []
    );

    updateWorkflowScheduleVisibility();

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    const dialog = modal.querySelector('.followup-modal-dialog');

    if (dialog) {
        dialog.scrollTop = 0;
    }

    setTimeout(function () {
        document.getElementById('notes').focus();
    }, 50);
}

function closeFollowupModal() {
    const modal = document.getElementById('followupModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}




function openAddReminderModal() {
    closeReminderListModal();

    const modal = document.getElementById('addReminderModal');
    const form = document.getElementById('addReminderForm');

    if (!modal || !form) {
        return;
    }

    form.reset();

    const now = new Date();

    document.getElementById('new_reminder_date').value =
        localDateValue(now);

    document.getElementById('new_reminder_time').value =
        localTimeValue(now);

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    setTimeout(function () {
        document.getElementById('new_reminder_title')?.focus();
    }, 50);
}

function closeAddReminderModal() {
    const modal = document.getElementById('addReminderModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    const openModal = document.querySelector('.followup-modal.is-open');

    if (!openModal) {
        document.body.classList.remove('modal-open');
    }
}

function openFollowupFromReminder(reminder) {
    closeReminderListModal();
    openFollowupModal(null, reminder);
}

function openReminderListModal() {
    const modal = document.getElementById('reminderListModal');

    if (!modal) {
        return;
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    const dialog = modal.querySelector('.reminder-list-dialog');

    if (dialog) {
        dialog.scrollTop = 0;
    }
}

function closeReminderListModal() {
    const modal = document.getElementById('reminderListModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    const openModal = document.querySelector('.followup-modal.is-open');

    if (!openModal) {
        document.body.classList.remove('modal-open');
    }
}

function openReminderRescheduleFromList(reminder) {
    closeReminderListModal();
    openReminderRescheduleModal(reminder);
}

function openReminderDismissFromList(reminder) {
    closeReminderListModal();
    openReminderDismissModal(reminder);
}

function openReminderRescheduleModal(reminder) {
    const modal = document.getElementById('reminderRescheduleModal');

    if (!modal || !reminder) {
        return;
    }

    document.getElementById('reschedule_reminder_id').value =
        reminder.id || '';

    document.getElementById('reschedule_reminder_date').value =
        reminder.date || '';

    document.getElementById('reschedule_reminder_time').value =
        normalizeTimeValue(reminder.time || '');

    document.getElementById('reschedule_reminder_notes').value =
        reminder.notes || '';

    document.getElementById('reminderRescheduleReference').textContent =
        reminder.title || 'Reminder';

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    setTimeout(function () {
        document.getElementById('reschedule_reminder_date')?.focus();
    }, 50);
}

function closeReminderRescheduleModal() {
    const modal = document.getElementById('reminderRescheduleModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    const openModal = document.querySelector('.followup-modal.is-open');

    if (!openModal) {
        document.body.classList.remove('modal-open');
    }
}

function openReminderDismissModal(reminder) {
    const modal = document.getElementById('reminderDismissModal');

    if (!modal || !reminder) {
        return;
    }

    document.getElementById('dismiss_reminder_id').value =
        reminder.id || '';

    document.getElementById('dismiss_reason').value = '';
    document.getElementById('dismiss_note').value = '';

    document.getElementById('reminderDismissReference').textContent =
        reminder.title || 'Reminder';

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    setTimeout(function () {
        document.getElementById('dismiss_reason')?.focus();
    }, 50);
}

function closeReminderDismissModal() {
    const modal = document.getElementById('reminderDismissModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    const openModal = document.querySelector('.followup-modal.is-open');

    if (!openModal) {
        document.body.classList.remove('modal-open');
    }
}

function openRemoveFollowupModal(followupId, followupNumber) {
    const modal = document.getElementById('removeFollowupModal');
    const form = document.getElementById('removeFollowupForm');

    if (!modal || !form) return;

    form.reset();
    document.getElementById('remove_followup_id').value = followupId;
    document.getElementById('removeFollowupReference').textContent =
        'Follow-up #' + followupNumber;

    toggleRemovalOtherNote();

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    setTimeout(function () {
        document.getElementById('removal_reason').focus();
    }, 50);
}

function closeRemoveFollowupModal() {
    const modal = document.getElementById('removeFollowupModal');

    if (!modal) return;

    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');

    const followupModal = document.getElementById('followupModal');
    if (!followupModal || !followupModal.classList.contains('is-open')) {
        document.body.classList.remove('modal-open');
    }
}

function toggleRemovalOtherNote() {
    const reason = document.getElementById('removal_reason');
    const field = document.getElementById('removalOtherField');
    const note = document.getElementById('removal_note');

    if (!reason || !field || !note) return;

    const isOther = reason.value === 'other';
    field.hidden = !isOther;

    if (!isOther) note.value = '';
}

function validateTimeRange(startTime, endTime, label) {
    if (!startTime && !endTime) {
        return true;
    }

    if (!startTime || !endTime) {
        alert('Please enter both the start time and end time for ' + label + '.');
        return false;
    }

    if (endTime <= startTime) {
        alert('The end time must be later than the start time for ' + label + '.');
        return false;
    }

    return true;
}

document.getElementById('followupForm')?.addEventListener(
    'submit',
    function (event) {
        const status = document.getElementById('lead_status')?.value || '';

        if (
            status === 'visit_interested'
            || status === 'visit_requested'
        ) {
            const rows = Array.from(
                document.querySelectorAll(
                    '#visitPreferenceRows .visit-preference-row'
                )
            );

            for (let index = 0; index < rows.length; index++) {
                const start = rows[index].querySelector(
                    '[name="visit_preference_start_time[]"]'
                )?.value || '';

                const end = rows[index].querySelector(
                    '[name="visit_preference_end_time[]"]'
                )?.value || '';

                if (!validateTimeRange(start, end, 'visit option ' + (index + 1))) {
                    event.preventDefault();
                    return;
                }
            }
        }

        if (status === 'visit_scheduled') {
            const start = document.getElementById(
                'confirmed_visit_start_time'
            )?.value || '';

            const end = document.getElementById(
                'confirmed_visit_end_time'
            )?.value || '';

            if (!validateTimeRange(start, end, 'the confirmed visit')) {
                event.preventDefault();
                return;
            }
        }

        if (status === 'placement_test_scheduled') {
            const start = document.getElementById(
                'placement_test_start_time'
            )?.value || '';

            const end = document.getElementById(
                'placement_test_end_time'
            )?.value || '';

            if (!validateTimeRange(start, end, 'the placement test')) {
                event.preventDefault();
            }
        }
    }
);

document.getElementById('lead_status')?.addEventListener(
    'change',
    updateWorkflowScheduleVisibility
);

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeFollowupModal();
        closeRemoveFollowupModal();
        closeReminderRescheduleModal();
        closeReminderDismissModal();
        closeReminderListModal();
        closeAddReminderModal();
    }
});
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
