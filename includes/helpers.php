<?php

/*
|--------------------------------------------------------------------------
| Workflow status
|--------------------------------------------------------------------------
| This tracks the admissions process only.
*/

const STATUS_LABELS = [
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

    // Legacy workflow values retained for older records
    'follow_up_needed' => 'Follow-up Required',
    'follow_up' => 'Follow-up Required',
    'converted' => 'Joined / Enrolled',
    'high_quality' => 'Contacted',
];

const STATUS_CLASSES = [
    'new' => 'tag-neutral',
    'contacted' => 'tag-neutral',
    'follow_up_required' => 'tag-orange-light',
    'visit_interested' => 'tag-purple-light',
    'visit_requested' => 'tag-purple-strong',
    'visit_scheduled' => 'tag-green-strong',
    'visited' => 'tag-green-dark',
    'placement_test_scheduled' => 'tag-green-dark',
    'placement_test_completed' => 'tag-green-deep',
    'joined' => 'tag-green-joined',
    'closed' => 'tag-grey',

    // Legacy workflow values
    'follow_up_needed' => 'tag-orange-light',
    'follow_up' => 'tag-orange-light',
    'converted' => 'tag-green-joined',
    'high_quality' => 'tag-neutral',
];

const FOLLOWUP_STATUS_CLASSES = [
    'new' => 'status-card-neutral',
    'contacted' => 'status-card-neutral',
    'follow_up_required' => 'status-card-still_considering',
    'visit_interested' => 'status-card-visit_interested',
    'visit_requested' => 'status-card-visit_requested',
    'visit_scheduled' => 'status-card-visit_scheduled',
    'visited' => 'status-card-visited',
    'placement_test_scheduled' => 'status-card-placement_test_scheduled',
    'placement_test_completed' => 'status-card-placement_test_completed',
    'joined' => 'status-card-joined',
    'closed' => 'status-card-no_response',

    // Legacy workflow values
    'follow_up_needed' => 'status-card-still_considering',
    'follow_up' => 'status-card-still_considering',
    'converted' => 'status-card-joined',
    'high_quality' => 'status-card-neutral',
];

/*
|--------------------------------------------------------------------------
| Parent response
|--------------------------------------------------------------------------
| This tracks what the parent actually said.
*/

const PARENT_RESPONSE_LABELS = [
    'interested' => 'Parent Interested',
    'still_considering' => 'Parent Still Considering',
    'call_back_later' => 'Parent Asked to Call Later',
    'will_call_back' => 'Parent Will Call Back',
    'pending' => 'Pending',
    'no_response' => 'Parent Did Not Respond',
    'not_reached' => 'Parent Not Reached',
    'number_not_working' => 'Parent Number Not Working',
    'not_interested' => 'Parent Not Interested',
    'wrong_lead' => 'Wrong Lead',
    'accidental_lead' => 'Accidental Lead',
    'job_inquiry' => 'Job Inquiry',
    'rejected' => 'Rejected',

    // Legacy values retained for old follow-ups
    'positive' => 'Parent Interested',
    'neutral' => 'Parent Still Considering',
    'negative' => 'Parent Not Interested',
    'random_click' => 'Accidental Lead',
];

const PARENT_RESPONSE_CLASSES = [
    'interested' => 'outcome-interested',
    'still_considering' => 'outcome-still_considering',
    'call_back_later' => 'outcome-call_back_later',
    'will_call_back' => 'outcome-will_call_back',
    'pending' => 'outcome-pending',
    'no_response' => 'outcome-no_response',
    'not_reached' => 'outcome-not_reached',
    'number_not_working' => 'outcome-number_not_working',
    'not_interested' => 'outcome-not_interested',
    'wrong_lead' => 'outcome-wrong_lead',
    'accidental_lead' => 'outcome-accidental_lead',
    'job_inquiry' => 'outcome-job_inquiry',
    'rejected' => 'outcome-rejected',

    // Legacy values
    'positive' => 'outcome-interested',
    'neutral' => 'outcome-still_considering',
    'negative' => 'outcome-not_interested',
    'random_click' => 'outcome-accidental_lead',
];

const PARENT_RESPONSE_HEADER_CLASSES = [
    'interested' => 'parent-interested',
    'still_considering' => 'parent-considering',
    'call_back_later' => 'parent-considering',
    'will_call_back' => 'parent-considering',
    'pending' => 'parent-considering',
    'no_response' => 'parent-no-response',
    'not_reached' => 'parent-no-response',
    'number_not_working' => 'parent-no-response',
    'not_interested' => 'parent-not-interested',
    'wrong_lead' => 'parent-not-interested',
    'accidental_lead' => 'parent-not-interested',
    'job_inquiry' => 'parent-not-interested',
    'rejected' => 'parent-rejected',

    // Legacy values
    'positive' => 'parent-interested',
    'neutral' => 'parent-considering',
    'negative' => 'parent-not-interested',
    'random_click' => 'parent-not-interested',
];

/*
|--------------------------------------------------------------------------
| Lead sources
|--------------------------------------------------------------------------
*/

const SOURCE_LABELS = [
    'lead_form' => 'Lead Form',
    'whatsapp' => 'WhatsApp',
    'facebook' => 'Facebook',
    'call_in' => 'Phone Call',
    'walk_in' => 'Walk-in',
    'referral' => 'Referral',
    'other' => 'Other',
];

/*
|--------------------------------------------------------------------------
| Normalisation helpers
|--------------------------------------------------------------------------
*/

function normalize_workflow_status(?string $status): string
{
    $status = strtolower(trim((string) $status));

    $map = [
        'follow_up_needed' => 'follow_up_required',
        'follow_up' => 'follow_up_required',
        'converted' => 'joined',
        'high_quality' => 'contacted',

        // Current visit coordination workflow values
        'visit_interested' => 'visit_interested',
        'visit_requested' => 'visit_requested',
        'visit_scheduled' => 'visit_scheduled',
        'visited' => 'visited',

        // Old parent-response values incorrectly stored in status
        'still_considering' => 'follow_up_required',
        'pending' => 'follow_up_required',
        'call_back_later' => 'follow_up_required',
        'will_call_back' => 'follow_up_required',

        'interested' => 'contacted',
        'positive' => 'contacted',

        'no_response' => 'follow_up_required',
        'not_reached' => 'follow_up_required',
        'number_not_working' => 'follow_up_required',

        'not_interested' => 'closed',
        'wrong_lead' => 'closed',
        'accidental_lead' => 'closed',
        'random_click' => 'closed',
        'job_inquiry' => 'closed',
        'rejected' => 'closed',
        'negative' => 'closed',
    ];

    $status = $map[$status] ?? $status;

    return array_key_exists($status, STATUS_LABELS)
        ? $status
        : 'new';
}



function workflow_status_priority(string $status): int
{
    $status = normalize_workflow_status($status);

    $priority = [
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

    return $priority[$status] ?? 0;
}

function normalize_parent_response(?string $response): string
{
    $response = strtolower(trim((string) $response));

    $map = [
        'positive' => 'interested',
        'neutral' => 'still_considering',
        'negative' => 'not_interested',
        'random_click' => 'accidental_lead',
        '' => 'pending',
    ];

    $response = $map[$response] ?? $response;

    return array_key_exists($response, PARENT_RESPONSE_LABELS)
        ? $response
        : 'pending';
}

/*
|--------------------------------------------------------------------------
| Display helpers
|--------------------------------------------------------------------------
*/

function status_label(string $status): string
{
    $status = normalize_workflow_status($status);

    return STATUS_LABELS[$status]
        ?? ucwords(str_replace('_', ' ', $status));
}

function status_class(string $status): string
{
    $status = normalize_workflow_status($status);

    return STATUS_CLASSES[$status] ?? 'tag-neutral';
}

function followup_status_class(string $status): string
{
    $status = normalize_workflow_status($status);

    return FOLLOWUP_STATUS_CLASSES[$status]
        ?? 'status-card-neutral';
}

function parent_response_label(string $response): string
{
    $response = normalize_parent_response($response);

    return PARENT_RESPONSE_LABELS[$response]
        ?? ucwords(str_replace('_', ' ', $response));
}

function parent_response_class(string $response): string
{
    $response = normalize_parent_response($response);

    return PARENT_RESPONSE_CLASSES[$response]
        ?? 'outcome-pending';
}

function parent_response_header_class(string $response): string
{
    $response = normalize_parent_response($response);

    return PARENT_RESPONSE_HEADER_CLASSES[$response]
        ?? 'parent-neutral';
}

function parent_response_timeline_class(string $response): string
{
    $response = normalize_parent_response($response);

    $classes = [
        'interested' => 'timeline-response-interested',

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

    return $classes[$response]
        ?? 'timeline-response-neutral';
}

function source_label(string $source): string
{
    $source = strtolower(trim($source));

    return SOURCE_LABELS[$source]
        ?? ucwords(str_replace('_', ' ', $source));
}

/*
|--------------------------------------------------------------------------
| Date and time helpers
|--------------------------------------------------------------------------
*/

function fmt_date(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);

    return $timestamp
        ? date('j M Y', $timestamp)
        : '—';
}

function fmt_datetime(?string $date, ?string $time): string
{
    if (!$date) {
        return '—';
    }

    $output = fmt_date($date);

    if ($time) {
        $timestamp = strtotime($time);

        if ($timestamp) {
            $output .= ' · ' . date('g:i A', $timestamp);
        }
    }

    return $output;
}

/*
|--------------------------------------------------------------------------
| Flash helpers
|--------------------------------------------------------------------------
*/

function flash_set(
    string $message,
    string $type = 'success'
): void {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

/*
|--------------------------------------------------------------------------
| General helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Follow-up scheduling helpers
|--------------------------------------------------------------------------
*/

function schedule_type_label(string $type): string
{
    $labels = [
        'visit_preference' => 'Preferred Visit Option',
        'confirmed_visit' => 'Confirmed Visit',
        'placement_test' => 'Placement Test',
        'enrollment' => 'Enrollment',
    ];

    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function schedule_summary(array $option): string
{
    $date = fmt_date($option['option_date'] ?? null);

    $startTime = trim((string)($option['start_time'] ?? ''));
    $endTime = trim((string)($option['end_time'] ?? ''));
    $timeNote = trim((string)($option['time_note'] ?? ''));
    $legacyTimeWindow = trim((string)($option['time_window'] ?? ''));

    $parts = [];

    if ($startTime !== '') {
        $startTimestamp = strtotime($startTime);

        $formattedStartTime = $startTimestamp
            ? date('g:i A', $startTimestamp)
            : $startTime;

        if ($endTime !== '') {
            $endTimestamp = strtotime($endTime);

            $formattedEndTime = $endTimestamp
                ? date('g:i A', $endTimestamp)
                : $endTime;

            $parts[] = $formattedStartTime . '–' . $formattedEndTime;
        } else {
            $parts[] = $formattedStartTime;
        }
    } elseif ($endTime !== '') {
        $endTimestamp = strtotime($endTime);

        $formattedEndTime = $endTimestamp
            ? date('g:i A', $endTimestamp)
            : $endTime;

        $parts[] = 'Until ' . $formattedEndTime;
    } elseif ($legacyTimeWindow !== '') {
        // Keeps older records readable until time_window is removed.
        $parts[] = $legacyTimeWindow;
    }

    if ($timeNote !== '') {
        $parts[] = $timeNote;
    }

    return $parts
        ? $date . ' · ' . implode(' · ', $parts)
        : $date;
}

function month_bounds(?string $yearMonth = null): array
{
    $yearMonth = $yearMonth ?: date('Y-m');

    $start = $yearMonth . '-01';
    $end = date('Y-m-t', strtotime($start));

    return [$start, $end, $yearMonth];
}


/*
|--------------------------------------------------------------------------
| Shared database helpers
|--------------------------------------------------------------------------
*/

function table_exists(PDO $db, string $table): bool
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

function app_datetime(
    ?string $date,
    ?string $time = null,
    string $defaultTime = '00:00:00'
): ?DateTime {
    if (!$date) {
        return null;
    }

    try {
        return new DateTime($date . ' ' . ($time ?: $defaultTime));
    } catch (Throwable $error) {
        return null;
    }
}

function calculate_lead_quality(
    string $workflowStatus,
    string $parentResponse,
    array $inquiries,
    array $followups,
    ?string $lastDate,
    ?string $lastTime
): array {
    $workflowStatus = normalize_workflow_status($workflowStatus);
    $parentResponse = normalize_parent_response($parentResponse);

    $responseScores = [
        'interested' => 70,
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
    ];

    $score = $responseScores[$parentResponse] ?? 20;

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

    $inquiryBonus = 0;

    foreach ($inquiries as $inquiry) {
        $inquiryStatus = $inquiry['inquiry_status'] ?? 'pending';

        switch ($inquiryStatus) {
            case 'possible':
            case 'recommended':
            case 'completed':
                $inquiryBonus += 4;
                break;

            case 'can_consider':
            case 'adjustable':
            case 'alternative_available':
            case 'management_approval':
                $inquiryBonus += 2;
                break;

            case 'not_possible':
                $inquiryBonus -= 15;
                break;

            default:
                break;
        }
    }

    $score += max(-30, min(15, $inquiryBonus));

    $followupBonus = 0;

    foreach ($followups as $followup) {
        $followupType = $followup['followup_type'] ?? '';

        switch ($followupType) {
            case 'physical_followup':
                $followupBonus += 4;
                break;

            case 'phone_call':
            case 'call_engagement':
                $followupBonus += 3;
                break;

            case 'whatsapp_admission':
            case 'whatsapp_engagement':
            case 'general_followup':
                $followupBonus += 1;
                break;

            default:
                break;
        }
    }

    $score += min($followupBonus, 8);

    $lastContact = app_datetime($lastDate, $lastTime);

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

    $maximumScores = [
        'still_considering' => 65,
        'pending' => 55,
        'will_call_back' => 55,
        'call_back_later' => 40,
        'no_response' => 30,
        'not_reached' => 25,
        'number_not_working' => 10,
        'not_interested' => 15,
        'rejected' => 10,
        'wrong_lead' => 0,
        'accidental_lead' => 0,
        'job_inquiry' => 0,
    ];

    if (isset($maximumScores[$parentResponse])) {
        $score = min($score, $maximumScores[$parentResponse]);
    }

    $score = max(0, min(100, $score));

    if ($score >= 80) {
        return [
            'score' => $score,
            'label' => 'Hot Lead',
            'class' => 'quality-hot',
            'group' => 'high',
            'row_class' => 'lead-row-quality-high',
        ];
    }

    if ($score >= 65) {
        return [
            'score' => $score,
            'label' => 'Strong Lead',
            'class' => 'quality-strong',
            'group' => 'high',
            'row_class' => 'lead-row-quality-high',
        ];
    }

    if ($score >= 45) {
        return [
            'score' => $score,
            'label' => 'Potential Lead',
            'class' => 'quality-potential',
            'group' => 'moderate',
            'row_class' => 'lead-row-quality-moderate',
        ];
    }

    if ($score >= 25) {
        return [
            'score' => $score,
            'label' => 'Low Engagement',
            'class' => 'quality-low',
            'group' => 'low',
            'row_class' => 'lead-row-quality-low',
        ];
    }

    return [
        'score' => $score,
        'label' => 'Unqualified Lead',
        'class' => 'quality-unqualified',
        'group' => 'low',
        'row_class' => 'lead-row-quality-low',
    ];
}

function reminder_datetime(
    ?string $date,
    ?string $time = null
): ?DateTime {
    return app_datetime($date, $time, '23:59:59');
}

function reminder_urgency(
    ?string $date,
    ?string $time = null
): array {
    $due = reminder_datetime($date, $time);

    if (!$due) {
        return [
            'key' => 'future',
            'label' => 'Upcoming',
            'class' => 'future',
            'relative' => 'Date unavailable',
        ];
    }

    $seconds = $due->getTimestamp() - time();

    if ($seconds < 0) {
        return [
            'key' => 'overdue',
            'label' => 'Overdue',
            'class' => 'overdue',
            'relative' => 'Overdue',
        ];
    }

    if ($seconds <= 86400) {
        return [
            'key' => 'within-24',
            'label' => 'Within 24 hours',
            'class' => 'within-24',
            'relative' => 'Due within 24 hours',
        ];
    }

    if ($seconds <= 172800) {
        return [
            'key' => 'within-48',
            'label' => 'Within 48 hours',
            'class' => 'within-48',
            'relative' => 'Due within 48 hours',
        ];
    }

    return [
        'key' => 'future',
        'label' => 'Upcoming',
        'class' => 'future',
        'relative' => 'Upcoming',
    ];
}

function reminder_summary(array $reminders): array
{
    $active = array_values(array_filter(
        $reminders,
        static fn(array $reminder): bool =>
            ($reminder['status'] ?? 'pending') === 'pending'
    ));

    if (!$active) {
        return [
            'count' => 0,
            'class' => 'none',
            'label' => '',
            'next' => null,
            'urgency' => null,
        ];
    }

    usort($active, static function (array $a, array $b): int {
        $aDate = reminder_datetime(
            $a['reminder_date'] ?? null,
            $a['reminder_time'] ?? null
        );

        $bDate = reminder_datetime(
            $b['reminder_date'] ?? null,
            $b['reminder_time'] ?? null
        );

        $aTimestamp = $aDate
            ? $aDate->getTimestamp()
            : PHP_INT_MAX;

        $bTimestamp = $bDate
            ? $bDate->getTimestamp()
            : PHP_INT_MAX;

        return $aTimestamp <=> $bTimestamp;
    });

    $next = $active[0];

    $urgency = reminder_urgency(
        $next['reminder_date'] ?? null,
        $next['reminder_time'] ?? null
    );

    return [
        'count' => count($active),
        'class' => $urgency['class'],
        'label' => $urgency['label'],
        'next' => $next,
        'urgency' => $urgency,
    ];
}

function next_followup_status(
    array $currentFollowup,
    array $allFollowups
): array {
    $nextDate = trim(
        (string)($currentFollowup['next_action_date'] ?? '')
    );

    if ($nextDate === '') {
        return [
            'key' => 'none',
            'label' => '',
            'class' => '',
        ];
    }

    $currentId = (int)($currentFollowup['id'] ?? 0);

    foreach ($allFollowups as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $currentId) {
            continue;
        }

        if (($candidate['followup_date'] ?? '') === $nextDate) {
            return [
                'key' => 'completed',
                'label' => 'Completed',
                'class' => 'next-followup-completed',
            ];
        }
    }

    try {
        $scheduledDate = new DateTime($nextDate . ' 00:00:00');
    } catch (Throwable $error) {
        return [
            'key' => 'active',
            'label' => 'Active',
            'class' => 'next-followup-active',
        ];
    }

    if ($scheduledDate < new DateTime('today')) {
        return [
            'key' => 'missed',
            'label' => 'Missed',
            'class' => 'next-followup-missed',
        ];
    }

    return [
        'key' => 'active',
        'label' => 'Active',
        'class' => 'next-followup-active',
    ];
}