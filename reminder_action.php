<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_admin();

$db = get_db();

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: leads.php');
    exit;
}

csrf_check();

$action = trim((string)($_POST['action'] ?? ''));
$reminderId = (int)($_POST['reminder_id'] ?? 0);
$leadId = (int)($_POST['lead_id'] ?? 0);

if ($leadId <= 0) {
    flash_set('Invalid lead.', 'error');
    header('Location: leads.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Reminder helpers
|--------------------------------------------------------------------------
*/

function reminder_redirect(int $leadId): never
{
    header('Location: lead_view.php?id=' . $leadId);
    exit;
}

function reminder_valid_date(?string $date): bool
{
    $date = trim((string)$date);

    if ($date === '') {
        return false;
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    return $dateObject
        && $dateObject->format('Y-m-d') === $date;
}

function reminder_valid_time(?string $time): bool
{
    $time = trim((string)$time);

    if ($time === '') {
        return true;
    }

    return (bool)preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
        $time
    );
}

function reminder_normalize_time(?string $time): ?string
{
    $time = trim((string)$time);

    if ($time === '') {
        return null;
    }

    return strlen($time) === 5
        ? $time . ':00'
        : $time;
}

function reminder_clean_text(
    mixed $value,
    int $maximumLength,
    string $fieldLabel,
    bool $required = false
): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException(
                'Please enter ' . strtolower($fieldLabel) . '.'
            );
        }

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

function reminder_followups_table_exists(PDO $db): bool
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $stmt->execute(['follow_ups']);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $error) {
        return false;
    }
}

function reminder_lead_exists(PDO $db, int $leadId): bool
{
    $stmt = $db->prepare(
        'SELECT id
         FROM leads
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$leadId]);

    return (bool)$stmt->fetchColumn();
}

function reminder_get_for_lead(
    PDO $db,
    int $reminderId,
    int $leadId,
    bool $forUpdate = false
): ?array {
    $sql = 'SELECT *
            FROM lead_reminders
            WHERE id = ?
              AND lead_id = ?
            LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $db->prepare($sql);

    $stmt->execute([
        $reminderId,
        $leadId,
    ]);

    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

    return $reminder ?: null;
}

function reminder_followup_exists(
    PDO $db,
    int $followupId,
    int $leadId
): bool {
    if (
        $followupId <= 0
        || !reminder_followups_table_exists($db)
    ) {
        return false;
    }

    $stmt = $db->prepare(
        'SELECT id
         FROM follow_ups
         WHERE id = ?
           AND lead_id = ?
         LIMIT 1'
    );

    $stmt->execute([
        $followupId,
        $leadId,
    ]);

    return (bool)$stmt->fetchColumn();
}

function reminder_update_linked_followup_next_action(
    PDO $db,
    ?int $followupId,
    int $leadId,
    string $reminderDate,
    ?string $reminderTime
): void {
    if (
        !$followupId
        || !reminder_followup_exists(
            $db,
            $followupId,
            $leadId
        )
    ) {
        return;
    }

    $stmt = $db->prepare(
        'UPDATE follow_ups
         SET
            next_action_date = ?,
            next_action_time = ?
         WHERE id = ?
           AND lead_id = ?'
    );

    $stmt->execute([
        $reminderDate,
        $reminderTime,
        $followupId,
        $leadId,
    ]);
}

function reminder_complete_record(
    PDO $db,
    int $reminderId,
    int $leadId
): void {
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
           AND lead_id = ?'
    );

    $stmt->execute($params);
}

/*
|--------------------------------------------------------------------------
| Confirm required tables and lead
|--------------------------------------------------------------------------
*/

if (!reminder_table_exists($db)) {
    flash_set(
        'The reminder table has not been created yet.',
        'error'
    );

    reminder_redirect($leadId);
}

if (!reminder_lead_exists($db, $leadId)) {
    flash_set('Lead not found.', 'error');
    header('Location: leads.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Add manual reminder
|--------------------------------------------------------------------------
*/

if ($action === 'add') {
    try {
        $title = reminder_clean_text(
            $_POST['title'] ?? '',
            150,
            'Reminder title',
            true
        );

        $reminderType = trim(
            (string)($_POST['reminder_type'] ?? 'general')
        );

        $priority = trim(
            (string)($_POST['priority'] ?? 'normal')
        );

        $reminderDate = trim(
            (string)($_POST['reminder_date'] ?? '')
        );

        $reminderTime = reminder_normalize_time(
            $_POST['reminder_time'] ?? null
        );

        $notes = reminder_clean_text(
            $_POST['notes'] ?? '',
            500,
            'Reminder notes'
        );

        $followupId = (int)($_POST['followup_id'] ?? 0);

        $allowedTypes = [
            'follow_up',
            'phone_call',
            'whatsapp',
            'visit',
            'placement_test',
            'document_collection',
            'payment',
            'general',
        ];

        $allowedPriorities = [
            'normal',
            'high',
            'urgent',
        ];

        if (!in_array($reminderType, $allowedTypes, true)) {
            throw new InvalidArgumentException(
                'Please select a valid reminder type.'
            );
        }

        if (!in_array($priority, $allowedPriorities, true)) {
            throw new InvalidArgumentException(
                'Please select a valid reminder priority.'
            );
        }

        if (!reminder_valid_date($reminderDate)) {
            throw new InvalidArgumentException(
                'Please enter a valid reminder date.'
            );
        }

        if (!reminder_valid_time($reminderTime)) {
            throw new InvalidArgumentException(
                'Please enter a valid reminder time.'
            );
        }

        if (
            $followupId > 0
            && !reminder_followup_exists(
                $db,
                $followupId,
                $leadId
            )
        ) {
            throw new InvalidArgumentException(
                'The selected follow-up could not be found.'
            );
        }

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
            $followupId > 0 ? $followupId : null,
            $reminderType,
            $title,
            $reminderDate,
            $reminderTime,
            'pending',
            $notes,
        ];

        if (reminder_column_exists($db, 'priority')) {
            $columns[] = 'priority';
            $values[] = $priority;
        }

        if (reminder_column_exists($db, 'source_type')) {
            $columns[] = 'source_type';
            $values[] = $followupId > 0
                ? 'followup'
                : 'manual';
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

        flash_set('Reminder added successfully.');
    } catch (InvalidArgumentException $error) {
        flash_set($error->getMessage(), 'error');
    } catch (Throwable $error) {
        error_log(
            'Reminder add failed for lead '
            . $leadId
            . ': '
            . $error->getMessage()
        );

        flash_set(
            'The reminder could not be added.',
            'error'
        );
    }

    reminder_redirect($leadId);
}

/*
|--------------------------------------------------------------------------
| All remaining actions require a valid reminder
|--------------------------------------------------------------------------
*/

if ($reminderId <= 0) {
    flash_set('Invalid reminder request.', 'error');
    reminder_redirect($leadId);
}

$existingReminder = reminder_get_for_lead(
    $db,
    $reminderId,
    $leadId
);

if (!$existingReminder) {
    flash_set('Reminder not found.', 'error');
    reminder_redirect($leadId);
}

/*
|--------------------------------------------------------------------------
| Acknowledge / complete reminder
|--------------------------------------------------------------------------
| This completes the reminder without creating a new follow-up.
|--------------------------------------------------------------------------
*/

if (
    $action === 'complete'
    || $action === 'acknowledge'
) {
    try {
        reminder_complete_record(
            $db,
            $reminderId,
            $leadId
        );

        flash_set('Reminder acknowledged.');
    } catch (Throwable $error) {
        error_log(
            'Reminder acknowledgement failed for reminder '
            . $reminderId
            . ': '
            . $error->getMessage()
        );

        flash_set(
            'The reminder could not be acknowledged.',
            'error'
        );
    }

    reminder_redirect($leadId);
}

/*
|--------------------------------------------------------------------------
| Reschedule reminder
|--------------------------------------------------------------------------
| If the reminder is linked to a follow-up, the follow-up's Next Plan date
| and time are updated inside the same transaction.
|--------------------------------------------------------------------------
*/

if ($action === 'reschedule') {
    $reminderDate = trim(
        (string)($_POST['reminder_date'] ?? '')
    );

    $reminderTime = reminder_normalize_time(
        $_POST['reminder_time'] ?? null
    );

    try {
        $notes = reminder_clean_text(
            $_POST['notes'] ?? '',
            500,
            'Reminder notes'
        );

        if (!reminder_valid_date($reminderDate)) {
            throw new InvalidArgumentException(
                'Please enter a valid reminder date.'
            );
        }

        if (!reminder_valid_time($reminderTime)) {
            throw new InvalidArgumentException(
                'Please enter a valid reminder time.'
            );
        }

        $db->beginTransaction();

        $lockedReminder = reminder_get_for_lead(
            $db,
            $reminderId,
            $leadId,
            true
        );

        if (!$lockedReminder) {
            throw new RuntimeException(
                'Reminder not found.'
            );
        }

        $setParts = [
            'reminder_date = ?',
            'reminder_time = ?',
            'notes = ?',
            'status = ?',
            'completed_at = NULL',
            'dismissed_at = NULL',
        ];

        $params = [
            $reminderDate,
            $reminderTime,
            $notes,
            'pending',
        ];

        if (reminder_column_exists($db, 'acknowledged_at')) {
            $setParts[] = 'acknowledged_at = NULL';
        }

        $params[] = $reminderId;
        $params[] = $leadId;

        $stmt = $db->prepare(
            'UPDATE lead_reminders
             SET ' . implode(', ', $setParts) . '
             WHERE id = ?
               AND lead_id = ?'
        );

        $stmt->execute($params);

        $linkedFollowupId = !empty(
            $lockedReminder['followup_id']
        )
            ? (int)$lockedReminder['followup_id']
            : null;

        reminder_update_linked_followup_next_action(
            $db,
            $linkedFollowupId,
            $leadId,
            $reminderDate,
            $reminderTime
        );

        $db->commit();

        flash_set(
            $linkedFollowupId
                ? 'Reminder and follow-up next plan updated successfully.'
                : 'Reminder rescheduled successfully.'
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
            'Reminder reschedule failed for reminder '
            . $reminderId
            . ': '
            . $error->getMessage()
        );

        flash_set(
            $error->getMessage() === 'Reminder not found.'
                ? 'Reminder not found.'
                : 'The reminder could not be rescheduled.',
            'error'
        );
    }

    reminder_redirect($leadId);
}

/*
|--------------------------------------------------------------------------
| Dismiss reminder
|--------------------------------------------------------------------------
*/

if ($action === 'dismiss') {
    $dismissReason = trim(
        (string)($_POST['dismiss_reason'] ?? '')
    );

    $dismissNote = trim(
        (string)($_POST['dismiss_note'] ?? '')
    );

    $allowedDismissReasons = [
        '',
        'already_completed',
        'duplicate',
        'no_longer_required',
        'parent_unavailable',
        'created_by_mistake',
        'other',
    ];

    if (!in_array($dismissReason, $allowedDismissReasons, true)) {
        flash_set(
            'Please select a valid dismissal reason.',
            'error'
        );

        reminder_redirect($leadId);
    }

    if (mb_strlen($dismissNote) > 500) {
        flash_set(
            'The dismissal note cannot exceed 500 characters.',
            'error'
        );

        reminder_redirect($leadId);
    }

    $dismissDetails = [];

    if ($dismissReason !== '') {
        $dismissDetails[] = 'Dismiss reason: '
            . ucwords(
                str_replace('_', ' ', $dismissReason)
            );
    }

    if ($dismissNote !== '') {
        $dismissDetails[] = $dismissNote;
    }

    $storedNote = $dismissDetails
        ? implode(' — ', $dismissDetails)
        : null;

    try {
        $setParts = [
            'status = ?',
            'dismissed_at = NOW()',
            'completed_at = NULL',
            'notes = COALESCE(?, notes)',
        ];

        $params = [
            'dismissed',
            $storedNote,
        ];

        if (reminder_column_exists($db, 'acknowledged_at')) {
            $setParts[] = 'acknowledged_at = NULL';
        }

        $params[] = $reminderId;
        $params[] = $leadId;

        $stmt = $db->prepare(
            'UPDATE lead_reminders
             SET ' . implode(', ', $setParts) . '
             WHERE id = ?
               AND lead_id = ?'
        );

        $stmt->execute($params);

        flash_set('Reminder dismissed.');
    } catch (Throwable $error) {
        error_log(
            'Reminder dismissal failed for reminder '
            . $reminderId
            . ': '
            . $error->getMessage()
        );

        flash_set(
            'The reminder could not be dismissed.',
            'error'
        );
    }

    reminder_redirect($leadId);
}

/*
|--------------------------------------------------------------------------
| Invalid action
|--------------------------------------------------------------------------
*/

flash_set('Invalid reminder action.', 'error');
reminder_redirect($leadId);