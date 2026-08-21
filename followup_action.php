<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_admin();

$db = get_db();
$lead_id = 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: leads.php');
    exit;
}

csrf_check();

$action = trim($_POST['action'] ?? '');
$lead_id = (int)($_POST['lead_id'] ?? 0);

if ($lead_id <= 0) {
    flash_set('Invalid lead.', 'error');
    header('Location: leads.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Confirm that the lead exists
|--------------------------------------------------------------------------
*/

$leadCheck = $db->prepare(
    'SELECT id
     FROM leads
     WHERE id = ?'
);

$leadCheck->execute([$lead_id]);

if (!$leadCheck->fetchColumn()) {
    flash_set('Lead not found.', 'error');
    header('Location: leads.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Dynamic lead schema support
|--------------------------------------------------------------------------
| The updated lead view separates admissions workflow from parent response.
| This helper allows the action file to update leads.parent_response when
| that column exists, while remaining compatible with older databases.
*/

function lead_has_parent_response_column(PDO $db): bool
{
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM leads LIKE 'parent_response'");
        $hasColumn = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $hasColumn = false;
    }

    return $hasColumn;
}



function sync_lead_current_state_from_followups(
    PDO $db,
    int $leadId
): void {
    $followupStmt = $db->prepare(
        'SELECT id, followup_number, followup_date, followup_time,
                lead_status, outcome
         FROM follow_ups
         WHERE lead_id = ?
         ORDER BY
            followup_number DESC,
            followup_date DESC,
            followup_time DESC,
            id DESC'
    );

    $followupStmt->execute([$leadId]);
    $followups = $followupStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$followups) {
        if (lead_has_parent_response_column($db)) {
            $resetStmt = $db->prepare(
                'UPDATE leads
                 SET parent_response = ?
                 WHERE id = ?'
            );

            $resetStmt->execute([
                'pending',
                $leadId,
            ]);
        }

        return;
    }

    $latestFollowup = $followups[0];

    $latestWorkflowStatus = normalize_workflow_status(
        $latestFollowup['lead_status'] ?? 'new'
    );

    $latestParentResponse = normalize_parent_response(
        $latestFollowup['outcome'] ?? 'pending'
    );

    if ($latestWorkflowStatus === 'closed') {
        $mainWorkflowStatus = 'closed';
    } else {
        $mainWorkflowStatus = 'new';
        $highestPriority = workflow_status_priority('new');

        foreach ($followups as $followup) {
            $candidateStatus = normalize_workflow_status(
                $followup['lead_status'] ?? 'new'
            );

            if ($candidateStatus === 'closed') {
                continue;
            }

            $candidatePriority = workflow_status_priority($candidateStatus);

            if ($candidatePriority > $highestPriority) {
                $mainWorkflowStatus = $candidateStatus;
                $highestPriority = $candidatePriority;
            }
        }
    }

    if (lead_has_parent_response_column($db)) {
        $updateStmt = $db->prepare(
            'UPDATE leads
             SET status = ?, parent_response = ?
             WHERE id = ?'
        );

        $updateStmt->execute([
            $mainWorkflowStatus,
            $latestParentResponse,
            $leadId,
        ]);

        return;
    }

    $updateStmt = $db->prepare(
        'UPDATE leads
         SET status = ?
         WHERE id = ?'
    );

    $updateStmt->execute([
        $mainWorkflowStatus,
        $leadId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Follow-up schedule support
|--------------------------------------------------------------------------
| Required table:
|
| followup_schedule_options
| - followup_id
| - lead_id
| - schedule_type
| - option_date
| - start_time
| - end_time
| - time_note
| - time_window (legacy, optional)
| - option_number
| - notes
|--------------------------------------------------------------------------
*/

function schedule_table_exists(PDO $db): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute(['followup_schedule_options']);
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $exists = false;
    }

    return $exists;
}

function require_schedule_table(PDO $db): void
{
    if (!schedule_table_exists($db)) {
        throw new RuntimeException(
            'The follow-up scheduling table has not been created yet.'
        );
    }
}

function valid_schedule_date(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    return $dateObject
        && $dateObject->format('Y-m-d') === $date;
}

function valid_schedule_time(?string $time): bool
{
    if ($time === null || $time === '') {
        return false;
    }

    return (bool)preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
        $time
    );
}

function normalize_schedule_time(?string $time): ?string
{
    $time = trim((string)$time);

    if ($time === '') {
        return null;
    }

    return strlen($time) === 5
        ? $time . ':00'
        : $time;
}

function validate_schedule_time_range(
    ?string $startTime,
    ?string $endTime,
    string $label
): array {
    $startTime = normalize_schedule_time($startTime);
    $endTime = normalize_schedule_time($endTime);

    if ($startTime === null && $endTime === null) {
        throw new InvalidArgumentException(
            'Please enter both the start time and end time for ' . $label . '.'
        );
    }

    if ($startTime === null || $endTime === null) {
        throw new InvalidArgumentException(
            'Please enter both the start time and end time for ' . $label . '.'
        );
    }

    if (
        !valid_schedule_time($startTime)
        || !valid_schedule_time($endTime)
    ) {
        throw new InvalidArgumentException(
            'Please enter valid start and end times for ' . $label . '.'
        );
    }

    if (strtotime($endTime) <= strtotime($startTime)) {
        throw new InvalidArgumentException(
            'The end time must be later than the start time for ' . $label . '.'
        );
    }

    return [$startTime, $endTime];
}

function clean_schedule_text(
    mixed $value,
    int $maximumLength,
    string $fieldLabel
): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    if (mb_strlen($value) > $maximumLength) {
        throw new InvalidArgumentException(
            $fieldLabel
            . ' cannot exceed '
            . $maximumLength
            . ' characters.'
        );
    }

    return $value;
}

function read_schedule_form(string $leadStatus): array
{
    $scheduleOptions = [];

    /*
    |--------------------------------------------------------------------------
    | Visit Interested / Visit Requested
    |--------------------------------------------------------------------------
    */

    if (in_array(
        $leadStatus,
        ['visit_interested', 'visit_requested'],
        true
    )) {
        $dates = $_POST['visit_preference_date'] ?? [];
        $startTimes = $_POST['visit_preference_start_time'] ?? [];
        $endTimes = $_POST['visit_preference_end_time'] ?? [];
        $timeNotes = $_POST['visit_preference_time_note'] ?? [];
        $notes = $_POST['visit_preference_notes'] ?? [];

        if (
            !is_array($dates)
            || !is_array($startTimes)
            || !is_array($endTimes)
            || !is_array($timeNotes)
            || !is_array($notes)
        ) {
            throw new InvalidArgumentException(
                'Invalid preferred visit options.'
            );
        }

        $rowCount = max(
            count($dates),
            count($startTimes),
            count($endTimes),
            count($timeNotes),
            count($notes)
        );

        if ($rowCount > 10) {
            throw new InvalidArgumentException(
                'You can add a maximum of 10 preferred visit options.'
            );
        }

        $optionNumber = 1;

        for ($index = 0; $index < $rowCount; $index++) {
            $date = trim((string)($dates[$index] ?? ''));
            $rawStartTime = trim((string)($startTimes[$index] ?? ''));
            $rawEndTime = trim((string)($endTimes[$index] ?? ''));

            $timeNote = clean_schedule_text(
                $timeNotes[$index] ?? '',
                150,
                'The preferred visit flexible time note'
            );

            $note = clean_schedule_text(
                $notes[$index] ?? '',
                500,
                'The preferred visit note'
            );

            if (
                $date === ''
                && $rawStartTime === ''
                && $rawEndTime === ''
                && $timeNote === null
                && $note === null
            ) {
                continue;
            }

            if (!valid_schedule_date($date)) {
                throw new InvalidArgumentException(
                    'Please enter a valid date for every preferred visit option.'
                );
            }

            [$startTime, $endTime] = validate_schedule_time_range(
                $rawStartTime,
                $rawEndTime,
                'preferred visit option ' . $optionNumber
            );

            $scheduleOptions[] = [
                'schedule_type' => 'visit_preference',
                'option_date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'time_note' => $timeNote,
                'option_number' => $optionNumber,
                'notes' => $note,
            ];

            $optionNumber++;
        }

        if (!$scheduleOptions) {
            throw new InvalidArgumentException(
                'Please add at least one preferred visit date, start time and end time.'
            );
        }

        return $scheduleOptions;
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmed Visit
    |--------------------------------------------------------------------------
    */

    if ($leadStatus === 'visit_scheduled') {
        $date = trim((string)($_POST['confirmed_visit_date'] ?? ''));

        if (!valid_schedule_date($date)) {
            throw new InvalidArgumentException(
                'Please enter a valid confirmed visit date.'
            );
        }

        [$startTime, $endTime] = validate_schedule_time_range(
            $_POST['confirmed_visit_start_time'] ?? null,
            $_POST['confirmed_visit_end_time'] ?? null,
            'the confirmed visit'
        );

        $timeNote = clean_schedule_text(
            $_POST['confirmed_visit_time_note'] ?? '',
            150,
            'The confirmed visit flexible time note'
        );

        return [[
            'schedule_type' => 'confirmed_visit',
            'option_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'time_note' => $timeNote,
            'option_number' => 1,
            'notes' => null,
        ]];
    }

    /*
    |--------------------------------------------------------------------------
    | Placement Test
    |--------------------------------------------------------------------------
    */

    if ($leadStatus === 'placement_test_scheduled') {
        $date = trim((string)($_POST['placement_test_date'] ?? ''));

        if (!valid_schedule_date($date)) {
            throw new InvalidArgumentException(
                'Please enter a valid placement test date.'
            );
        }

        [$startTime, $endTime] = validate_schedule_time_range(
            $_POST['placement_test_start_time'] ?? null,
            $_POST['placement_test_end_time'] ?? null,
            'the placement test'
        );

        $timeNote = clean_schedule_text(
            $_POST['placement_test_time_note'] ?? '',
            150,
            'The placement test flexible time note'
        );

        return [[
            'schedule_type' => 'placement_test',
            'option_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'time_note' => $timeNote,
            'option_number' => 1,
            'notes' => null,
        ]];
    }

    /*
    |--------------------------------------------------------------------------
    | Enrollment
    |--------------------------------------------------------------------------
    */

    if ($leadStatus === 'joined') {
        $date = trim((string)($_POST['enrollment_date'] ?? ''));

        if (!valid_schedule_date($date)) {
            throw new InvalidArgumentException(
                'Please enter a valid enrollment date.'
            );
        }

        return [[
            'schedule_type' => 'enrollment',
            'option_date' => $date,
            'start_time' => null,
            'end_time' => null,
            'time_note' => null,
            'option_number' => 1,
            'notes' => null,
        ]];
    }

    return [];
}

function replace_followup_schedule_options(
    PDO $db,
    int $leadId,
    int $followupId,
    array $scheduleOptions
): void {
    require_schedule_table($db);

    $deleteStmt = $db->prepare(
        'DELETE FROM followup_schedule_options
         WHERE followup_id = ? AND lead_id = ?'
    );

    $deleteStmt->execute([
        $followupId,
        $leadId,
    ]);

    if (!$scheduleOptions) {
        return;
    }

    $insertStmt = $db->prepare(
        'INSERT INTO followup_schedule_options (
            followup_id,
            lead_id,
            schedule_type,
            option_date,
            start_time,
            end_time,
            time_note,
            time_window,
            option_number,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($scheduleOptions as $option) {
        $insertStmt->execute([
            $followupId,
            $leadId,
            $option['schedule_type'],
            $option['option_date'],
            $option['start_time'],
            $option['end_time'],
            $option['time_note'],
            null,
            $option['option_number'],
            $option['notes'],
        ]);
    }
}

function delete_followup_schedule_options(
    PDO $db,
    int $leadId,
    int $followupId
): void {
    if (!schedule_table_exists($db)) {
        return;
    }

    $stmt = $db->prepare(
        'DELETE FROM followup_schedule_options
         WHERE followup_id = ? AND lead_id = ?'
    );

    $stmt->execute([
        $followupId,
        $leadId,
    ]);
}



/*
|--------------------------------------------------------------------------
| Reminder support
|--------------------------------------------------------------------------
*/

function reminder_table_exists(PDO $db): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute(['lead_reminders']);
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $exists = false;
    }

    return $exists;
}

function reminder_column_exists(PDO $db, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );

        $stmt->execute([
            'lead_reminders',
            $column,
        ]);

        $cache[$column] = (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function complete_reminder(
    PDO $db,
    int $reminderId,
    int $leadId
): void {
    if (
        !reminder_table_exists($db)
        || $reminderId <= 0
    ) {
        return;
    }

    $setParts = [
        'status = ?',
        'completed_at = NOW()',
        'dismissed_at = NULL',
    ];

    $params = ['completed'];

    if (reminder_column_exists($db, 'acknowledged_at')) {
        $setParts[] = 'acknowledged_at = NOW()';
    }

    $params[] = $reminderId;
    $params[] = $leadId;

    $stmt = $db->prepare(
        'UPDATE lead_reminders
         SET ' . implode(', ', $setParts) . '
         WHERE id = ?
           AND lead_id = ?
           AND status = ?'
    );

    $params[] = 'pending';
    $stmt->execute($params);
}

function complete_due_followup_reminder(
    PDO $db,
    int $leadId,
    ?int $excludeReminderId = null
): void {
    if (!reminder_table_exists($db)) {
        return;
    }

    $sql = 'SELECT id
            FROM lead_reminders
            WHERE lead_id = ?
              AND status = ?
              AND reminder_type = ?
              AND (
                    reminder_date < CURDATE()
                    OR (
                        reminder_date = CURDATE()
                        AND (
                            reminder_time IS NULL
                            OR reminder_time <= CURTIME()
                        )
                    )
                  )';

    $params = [
        $leadId,
        'pending',
        'follow_up',
    ];

    if ($excludeReminderId && $excludeReminderId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeReminderId;
    }

    $sql .= ' ORDER BY
                reminder_date DESC,
                reminder_time DESC,
                id DESC
              LIMIT 1
              FOR UPDATE';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $reminderId = (int)$stmt->fetchColumn();

    if ($reminderId > 0) {
        complete_reminder(
            $db,
            $reminderId,
            $leadId
        );
    }
}

function insert_followup_reminder(
    PDO $db,
    int $leadId,
    int $followupId,
    string $reminderType,
    string $title,
    string $reminderDate,
    ?string $reminderTime,
    ?string $notes = null,
    string $priority = 'normal'
): void {
    $columns = [
        'lead_id',
        'followup_id',
        'reminder_type',
        'title',
        'reminder_date',
        'reminder_time',
        'status',
        'notes',
    ];

    $values = [
        $leadId,
        $followupId,
        $reminderType,
        $title,
        $reminderDate,
        $reminderTime ?: null,
        'pending',
        $notes,
    ];

    if (reminder_column_exists($db, 'priority')) {
        $columns[] = 'priority';
        $values[] = $priority;
    }

    if (reminder_column_exists($db, 'source_type')) {
        $columns[] = 'source_type';
        $values[] = 'followup';
    }

    $placeholders = implode(
        ', ',
        array_fill(0, count($columns), '?')
    );

    $stmt = $db->prepare(
        'INSERT INTO lead_reminders (
            ' . implode(', ', $columns) . '
        ) VALUES (
            ' . $placeholders . '
        )'
    );

    $stmt->execute($values);
}

function sync_followup_reminder(
    PDO $db,
    int $leadId,
    int $followupId,
    array $data
): void {
    if (!reminder_table_exists($db)) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Replace all currently pending reminders generated by this follow-up
    |--------------------------------------------------------------------------
    | Completed and dismissed reminders are retained as reminder history.
    */

    $deleteStmt = $db->prepare(
        'DELETE FROM lead_reminders
         WHERE followup_id = ?
           AND lead_id = ?
           AND status = ?'
    );

    $deleteStmt->execute([
        $followupId,
        $leadId,
        'pending',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Next follow-up reminder
    |--------------------------------------------------------------------------
    */

    if (!empty($data['next_date'])) {
        insert_followup_reminder(
            $db,
            $leadId,
            $followupId,
            'follow_up',
            'Follow up with parent',
            $data['next_date'],
            $data['next_time'] ?? null,
            $data['notes'] ?? null,
            'normal'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow milestone reminders
    |--------------------------------------------------------------------------
    | The schedule fields saved for Visit Interested, Visit Requested,
    | Visit Scheduled, Placement Test Scheduled and Joined now generate
    | their own reminder notifications as well.
    */

    $leadStatus = normalize_workflow_status(
        $data['lead_status'] ?? 'new'
    );

    $scheduleOptions = $data['schedule_options'] ?? [];

    if (!is_array($scheduleOptions) || !$scheduleOptions) {
        return;
    }

    foreach ($scheduleOptions as $index => $option) {
        $scheduleType = (string)($option['schedule_type'] ?? '');
        $optionDate = trim((string)($option['option_date'] ?? ''));
        $startTime = normalize_schedule_time(
            $option['start_time'] ?? null
        );

        if ($optionDate === '' || !valid_schedule_date($optionDate)) {
            continue;
        }

        $timeNote = trim((string)($option['time_note'] ?? ''));
        $optionNote = trim((string)($option['notes'] ?? ''));

        $notes = array_filter([
            $timeNote !== '' ? $timeNote : null,
            $optionNote !== '' ? $optionNote : null,
        ]);

        $storedNotes = $notes
            ? implode(' — ', $notes)
            : null;

        if (
            $scheduleType === 'visit_preference'
            && in_array(
                $leadStatus,
                ['visit_interested', 'visit_requested'],
                true
            )
        ) {
            $optionNumber = (int)(
                $option['option_number']
                ?? ($index + 1)
            );

            $title = $leadStatus === 'visit_requested'
                ? 'Parent visit request – option #' . $optionNumber
                : 'Parent interested in visit – option #' . $optionNumber;

            insert_followup_reminder(
                $db,
                $leadId,
                $followupId,
                'visit',
                $title,
                $optionDate,
                $startTime,
                $storedNotes,
                'high'
            );

            continue;
        }

        if (
            $scheduleType === 'confirmed_visit'
            && $leadStatus === 'visit_scheduled'
        ) {
            insert_followup_reminder(
                $db,
                $leadId,
                $followupId,
                'visit',
                'Confirmed school visit',
                $optionDate,
                $startTime,
                $storedNotes,
                'high'
            );

            continue;
        }

        if (
            $scheduleType === 'placement_test'
            && $leadStatus === 'placement_test_scheduled'
        ) {
            insert_followup_reminder(
                $db,
                $leadId,
                $followupId,
                'placement_test',
                'Placement test scheduled',
                $optionDate,
                $startTime,
                $storedNotes,
                'high'
            );

            continue;
        }

        if (
            $scheduleType === 'enrollment'
            && $leadStatus === 'joined'
        ) {
            insert_followup_reminder(
                $db,
                $leadId,
                $followupId,
                'enrollment',
                'Student enrollment / joined',
                $optionDate,
                null,
                $storedNotes,
                'normal'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Shared validation values
|--------------------------------------------------------------------------
*/

$allowedTypes = [
    'phone_call',
    'whatsapp_admission',
    'whatsapp_engagement',
    'physical_followup',
    'call_engagement',
    'general_followup',
];

$allowedOutcomes = [
    'interested',
    'positive',
    'still_considering',
    'pending',
    'call_back_later',
    'will_call_back',
    'no_response',
    'not_reached',
    'number_not_working',
    'not_interested',
    'wrong_lead',
    'accidental_lead',
    'job_inquiry',
    'rejected',

    // Legacy values retained for older forms/records
    'neutral',
    'negative',
];

/*
|--------------------------------------------------------------------------
| Read and validate follow-up form
|--------------------------------------------------------------------------
*/

function read_followup_form(array $allowedTypes, array $allowedOutcomes): array
{
    $date = !empty($_POST['followup_date'])
        ? trim($_POST['followup_date'])
        : date('Y-m-d');

    $time = !empty($_POST['followup_time'])
        ? trim($_POST['followup_time'])
        : null;

    $followupType = trim(
        $_POST['followup_type'] ?? 'phone_call'
    );

    $outcome = normalize_parent_response(
        $_POST['outcome'] ?? 'still_considering'
    );

    $leadStatus = normalize_workflow_status(
        $_POST['lead_status'] ?? 'new'
    );

    $notes = trim(
        $_POST['notes'] ?? ''
    );

    $nextActionDate = !empty($_POST['next_action_date'])
        ? trim($_POST['next_action_date'])
        : null;

    $nextActionTime = !empty($_POST['next_action_time'])
        ? trim($_POST['next_action_time'])
        : null;

    $sourceReminderId = (int)($_POST['source_reminder_id'] ?? 0);

    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    if (
        !$dateObject
        || $dateObject->format('Y-m-d') !== $date
    ) {
        throw new InvalidArgumentException(
            'Please enter a valid follow-up date.'
        );
    }

    if (
        $time !== null
        && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)
    ) {
        throw new InvalidArgumentException(
            'Please enter a valid follow-up time.'
        );
    }

    if (
        $nextActionDate !== null
        && (
            !($nextDateObject = DateTime::createFromFormat('Y-m-d', $nextActionDate))
            || $nextDateObject->format('Y-m-d') !== $nextActionDate
        )
    ) {
        throw new InvalidArgumentException(
            'Please enter a valid next follow-up date.'
        );
    }

    if (
        $nextActionTime !== null
        && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $nextActionTime)
    ) {
        throw new InvalidArgumentException(
            'Please enter a valid next follow-up time.'
        );
    }

    if (!in_array($followupType, $allowedTypes, true)) {
        throw new InvalidArgumentException(
            'Invalid follow-up type.'
        );
    }

    if (!in_array($outcome, $allowedOutcomes, true)) {
        throw new InvalidArgumentException(
            'Invalid follow-up outcome.'
        );
    }

    /*
    | Workflow validation is controlled by STATUS_LABELS in helpers.php.
    | This automatically accepts:
    | - visit_interested
    | - visit_requested
    | - visit_scheduled
    | - visited
    */

    if (!array_key_exists($leadStatus, STATUS_LABELS)) {
        throw new InvalidArgumentException(
            'Invalid lead status.'
        );
    }

    if ($notes === '') {
        throw new InvalidArgumentException(
            'Please enter follow-up notes.'
        );
    }

    $scheduleOptions = read_schedule_form($leadStatus);

    return [
        'date' => $date,
        'time' => $time,
        'type' => $followupType,
        'outcome' => $outcome,
        'lead_status' => $leadStatus,
        'notes' => $notes,
        'next_date' => $nextActionDate,
        'next_time' => $nextActionTime,
        'source_reminder_id' => $sourceReminderId,
        'schedule_options' => $scheduleOptions,
    ];
}

/*
|--------------------------------------------------------------------------
| Add follow-up
|--------------------------------------------------------------------------
*/

if ($action === 'add') {
    try {
        $data = read_followup_form(
            $allowedTypes,
            $allowedOutcomes
        );

        $db->beginTransaction();

        $numStmt = $db->prepare(
            'SELECT COALESCE(MAX(followup_number), 0) + 1
             FROM follow_ups
             WHERE lead_id = ?'
        );

        $numStmt->execute([$lead_id]);
        $num = (int)$numStmt->fetchColumn();

        $insertFollowup = $db->prepare(
            'INSERT INTO follow_ups (
                lead_id,
                followup_number,
                followup_date,
                followup_time,
                followup_type,
                outcome,
                lead_status,
                notes,
                next_action_date,
                next_action_time
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $insertFollowup->execute([
            $lead_id,
            $num,
            $data['date'],
            $data['time'],
            $data['type'],
            $data['outcome'],
            $data['lead_status'],
            $data['notes'],
            $data['next_date'],
            $data['next_time'],
        ]);

        $followupId = (int)$db->lastInsertId();

        if ($followupId <= 0) {
            throw new RuntimeException(
                'The new follow-up ID could not be determined.'
            );
        }

        replace_followup_schedule_options(
            $db,
            $lead_id,
            $followupId,
            $data['schedule_options']
        );

        /*
        | When opened from a reminder, acknowledge that exact reminder.
        | When added from the main Add Follow-up button, automatically
        | acknowledge the most recent due follow-up reminder for this lead.
        */

        if (!empty($data['source_reminder_id'])) {
            complete_reminder(
                $db,
                (int)$data['source_reminder_id'],
                $lead_id
            );
        } else {
            complete_due_followup_reminder(
                $db,
                $lead_id
            );
        }

        sync_followup_reminder(
            $db,
            $lead_id,
            $followupId,
            $data
        );

        sync_lead_current_state_from_followups(
            $db,
            $lead_id
        );

        $db->commit();

        flash_set(
            "Follow-up #{$num} logged successfully."
        );
    } catch (InvalidArgumentException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        flash_set($error->getMessage(), 'error');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Follow-up add failed for lead '
            . $lead_id
            . ': '
            . $error->getMessage()
        );

        flash_set(
            $error->getMessage() ===
                'The follow-up scheduling table has not been created yet.'
                ? $error->getMessage()
                : 'The follow-up could not be saved. Please try again.',
            'error'
        );
    }

    header('Location: lead_view.php?id=' . $lead_id);
    exit;
}

/*
|--------------------------------------------------------------------------
| Edit follow-up
|--------------------------------------------------------------------------
*/

if ($action === 'edit') {
    $followupId = (int)($_POST['followup_id'] ?? 0);

    if ($followupId <= 0) {
        flash_set('Invalid follow-up.', 'error');
        header('Location: lead_view.php?id=' . $lead_id);
        exit;
    }

    try {
        $data = read_followup_form(
            $allowedTypes,
            $allowedOutcomes
        );

        $db->beginTransaction();

        $updateFollowup = $db->prepare(
            'UPDATE follow_ups
             SET
                followup_date = ?,
                followup_time = ?,
                followup_type = ?,
                outcome = ?,
                lead_status = ?,
                notes = ?,
                next_action_date = ?,
                next_action_time = ?
             WHERE id = ? AND lead_id = ?'
        );

        $updateFollowup->execute([
            $data['date'],
            $data['time'],
            $data['type'],
            $data['outcome'],
            $data['lead_status'],
            $data['notes'],
            $data['next_date'],
            $data['next_time'],
            $followupId,
            $lead_id,
        ]);

        if ($updateFollowup->rowCount() === 0) {
            $existsStmt = $db->prepare(
                'SELECT id
                 FROM follow_ups
                 WHERE id = ? AND lead_id = ?'
            );

            $existsStmt->execute([
                $followupId,
                $lead_id,
            ]);

            if (!$existsStmt->fetchColumn()) {
                throw new RuntimeException(
                    'Follow-up not found.'
                );
            }
        }

        replace_followup_schedule_options(
            $db,
            $lead_id,
            $followupId,
            $data['schedule_options']
        );

        if (!empty($data['source_reminder_id'])) {
            complete_reminder(
                $db,
                (int)$data['source_reminder_id'],
                $lead_id
            );
        }

        sync_followup_reminder(
            $db,
            $lead_id,
            $followupId,
            $data
        );

        /*
        | Recalculate the main lead milestone from the complete follow-up
        | history. Lower-priority activity statuses cannot replace a stronger
        | admissions milestone.
        */
        sync_lead_current_state_from_followups(
            $db,
            $lead_id
        );

        $db->commit();

        flash_set('Follow-up updated successfully.');
    } catch (InvalidArgumentException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        flash_set($error->getMessage(), 'error');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Follow-up edit failed for lead '
            . $lead_id
            . ': '
            . $error->getMessage()
        );

        flash_set(
            $error->getMessage() ===
                'The follow-up scheduling table has not been created yet.'
                ? $error->getMessage()
                : 'The follow-up could not be updated. Please try again.',
            'error'
        );
    }

    header('Location: lead_view.php?id=' . $lead_id);
    exit;
}

/*
|--------------------------------------------------------------------------
| Delete follow-up
|--------------------------------------------------------------------------
*/

if ($action === 'delete') {
    $followupId = (int)($_POST['followup_id'] ?? 0);
    $removalReason = trim($_POST['removal_reason'] ?? '');
    $removalNote = trim($_POST['removal_note'] ?? '');

    $allowedRemovalReasons = [
        'added_by_mistake',
        'wrong_followup_details',
        'duplicate_followup',
        'wrong_lead',
        'test_entry',
        'other',
    ];

    if ($followupId <= 0) {
        flash_set('Invalid follow-up.', 'error');
        header('Location: lead_view.php?id=' . $lead_id);
        exit;
    }

    if (!in_array($removalReason, $allowedRemovalReasons, true)) {
        flash_set('Please select a valid removal reason.', 'error');
        header('Location: lead_view.php?id=' . $lead_id);
        exit;
    }

    if (mb_strlen($removalNote) > 500) {
        flash_set('The removal note cannot exceed 500 characters.', 'error');
        header('Location: lead_view.php?id=' . $lead_id);
        exit;
    }

    try {
        $db->beginTransaction();

        /*
        | Lock the requested follow-up first so that two simultaneous
        | delete requests cannot renumber the same lead inconsistently.
        */

        $followupCheck = $db->prepare(
            'SELECT id
             FROM follow_ups
             WHERE id = ? AND lead_id = ?
             FOR UPDATE'
        );

        $followupCheck->execute([
            $followupId,
            $lead_id,
        ]);

        if (!$followupCheck->fetchColumn()) {
            throw new RuntimeException(
                'Follow-up not found.'
            );
        }

        delete_followup_schedule_options(
            $db,
            $lead_id,
            $followupId
        );

        if (reminder_table_exists($db)) {
            $db->prepare(
                'DELETE FROM lead_reminders
                 WHERE followup_id = ?
                   AND lead_id = ?'
            )->execute([
                $followupId,
                $lead_id,
            ]);
        }

        $deleteStmt = $db->prepare(
            'DELETE FROM follow_ups
             WHERE id = ? AND lead_id = ?'
        );

        $deleteStmt->execute([
            $followupId,
            $lead_id,
        ]);

        if ($deleteStmt->rowCount() === 0) {
            throw new RuntimeException(
                'Follow-up not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Renumber all remaining follow-ups
        |--------------------------------------------------------------------------
        | Oldest follow-up becomes #1 and every remaining record receives a
        | continuous number. For example: 1, 4, 5, 6 becomes 1, 2, 3, 4.
        */

        $remainingStmt = $db->prepare(
            'SELECT id
             FROM follow_ups
             WHERE lead_id = ?
             ORDER BY
                followup_date ASC,
                CASE
                    WHEN followup_time IS NULL THEN 1
                    ELSE 0
                END ASC,
                followup_time ASC,
                id ASC
             FOR UPDATE'
        );

        $remainingStmt->execute([$lead_id]);
        $remainingIds = $remainingStmt->fetchAll(PDO::FETCH_COLUMN);

        $renumberStmt = $db->prepare(
            'UPDATE follow_ups
             SET followup_number = ?
             WHERE id = ? AND lead_id = ?'
        );

        $newNumber = 1;

        foreach ($remainingIds as $remainingId) {
            $renumberStmt->execute([
                $newNumber,
                (int)$remainingId,
                $lead_id,
            ]);

            $newNumber++;
        }

        /*
        | Recalculate the lead after deletion. If the deleted follow-up held
        | the highest milestone, use the next highest remaining milestone.
        */
        sync_lead_current_state_from_followups(
            $db,
            $lead_id
        );

        /*
        | The removal reason and optional note are validated here because the
        | custom popup sends them. They are not inserted into a database table
        | because the current schema provided does not include an audit table.
        */

        $db->commit();

        flash_set(
            'Follow-up removed and remaining follow-ups renumbered successfully.'
        );
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Follow-up delete failed for lead '
            . $lead_id
            . ' using reason '
            . $removalReason
            . ($removalNote !== '' ? ' (' . $removalNote . ')' : '')
            . ': '
            . $error->getMessage()
        );

        flash_set(
            $error->getMessage() === 'Follow-up not found.'
                ? 'Follow-up not found.'
                : 'The follow-up could not be removed.',
            'error'
        );
    }

    header('Location: lead_view.php?id=' . $lead_id);
    exit;
}

/*
|--------------------------------------------------------------------------
| Invalid action
|--------------------------------------------------------------------------
*/

flash_set('Invalid action.', 'error');
header('Location: lead_view.php?id=' . $lead_id);
exit;