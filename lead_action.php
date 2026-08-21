<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_admin();

$db = get_db();

$id = isset($_GET['id'])
    ? (int)$_GET['id']
    : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

$lead = [
    'received_date' => date('Y-m-d'),
    'source' => 'call_in',
    'grade' => '',
    'contact' => '',
    'parent_name' => '',
    'child_name' => '',
    'current_school' => '',
    'location' => '',
    'fb_name' => '',
    'inquiry_notes' => '',
    'transfer_period' => '',
    'reason' => '',
    'status' => 'new',
    'rejection_reason' => '',
    'visit_date' => '',
    'converted_date' => '',
];

$errors = [];

/*
|--------------------------------------------------------------------------
| Load existing lead
|--------------------------------------------------------------------------
*/

if ($id > 0) {
    $stmt = $db->prepare(
        'SELECT *
         FROM leads
         WHERE id = ?'
    );

    $stmt->execute([$id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        flash_set('Lead not found.', 'error');
        header('Location: leads.php');
        exit;
    }

    $lead = array_merge($lead, $existing);
}

/*
|--------------------------------------------------------------------------
| Save lead
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $editableFields = [
        'received_date',
        'source',
        'grade',
        'contact',
        'parent_name',
        'child_name',
        'current_school',
        'location',
        'fb_name',
        'inquiry_notes',
        'transfer_period',
        'reason',
        'status',
        'rejection_reason',
        'visit_date',
        'converted_date',
    ];

    foreach ($editableFields as $field) {
        $lead[$field] = trim($_POST[$field] ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($lead['received_date'] === '') {
        $errors[] = 'Received date is required.';
    }

    if ($lead['contact'] === '') {
        $errors[] = 'Contact number is required.';
    }

    if (!array_key_exists($lead['source'], SOURCE_LABELS)) {
        $errors[] = 'Please select a valid lead source.';
    }

    if (!array_key_exists($lead['status'], STATUS_LABELS)) {
        $errors[] = 'Please select a valid lead status.';
    }

    $reasonRequiredStatuses = [
        'not_interested',
        'wrong_lead',
        'accidental_lead',
        'rejected',
        'random_click',
    ];

    if (
        in_array($lead['status'], $reasonRequiredStatuses, true)
        && $lead['rejection_reason'] === ''
    ) {
        $errors[] = 'Please enter a reason for this status.';
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare optional values
    |--------------------------------------------------------------------------
    */

    if (!$errors) {
        $lead['visit_date'] =
            $lead['visit_date'] !== ''
                ? $lead['visit_date']
                : null;

        $lead['converted_date'] =
            $lead['converted_date'] !== ''
                ? $lead['converted_date']
                : null;

        $lead['rejection_reason'] =
            $lead['rejection_reason'] !== ''
                ? $lead['rejection_reason']
                : null;

        /*
        | Clear an old rejection reason when the lead is moved back
        | to an active or successful status.
        */

        if (
            !in_array(
                $lead['status'],
                $reasonRequiredStatuses,
                true
            )
        ) {
            $lead['rejection_reason'] = null;
        }

        try {
            if ($id > 0) {
                $sql = '
                    UPDATE leads
                    SET
                        received_date = ?,
                        source = ?,
                        grade = ?,
                        contact = ?,
                        parent_name = ?,
                        child_name = ?,
                        current_school = ?,
                        location = ?,
                        fb_name = ?,
                        inquiry_notes = ?,
                        transfer_period = ?,
                        reason = ?,
                        status = ?,
                        rejection_reason = ?,
                        visit_date = ?,
                        converted_date = ?
                    WHERE id = ?
                ';

                $db->prepare($sql)->execute([
                    $lead['received_date'],
                    $lead['source'],
                    $lead['grade'],
                    $lead['contact'],
                    $lead['parent_name'],
                    $lead['child_name'],
                    $lead['current_school'],
                    $lead['location'],
                    $lead['fb_name'],
                    $lead['inquiry_notes'],
                    $lead['transfer_period'],
                    $lead['reason'],
                    $lead['status'],
                    $lead['rejection_reason'],
                    $lead['visit_date'],
                    $lead['converted_date'],
                    $id,
                ]);

                flash_set('Lead updated successfully.');
                header('Location: lead_view.php?id=' . $id);
                exit;
            }

            $sql = '
                INSERT INTO leads (
                    received_date,
                    source,
                    grade,
                    contact,
                    parent_name,
                    child_name,
                    current_school,
                    location,
                    fb_name,
                    inquiry_notes,
                    transfer_period,
                    reason,
                    status,
                    rejection_reason,
                    visit_date,
                    converted_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ';

            $db->prepare($sql)->execute([
                $lead['received_date'],
                $lead['source'],
                $lead['grade'],
                $lead['contact'],
                $lead['parent_name'],
                $lead['child_name'],
                $lead['current_school'],
                $lead['location'],
                $lead['fb_name'],
                $lead['inquiry_notes'],
                $lead['transfer_period'],
                $lead['reason'],
                $lead['status'],
                $lead['rejection_reason'],
                $lead['visit_date'],
                $lead['converted_date'],
            ]);

            $newId = (int)$db->lastInsertId();

            flash_set('Lead added successfully.');
            header('Location: lead_view.php?id=' . $newId);
            exit;
        } catch (Throwable $error) {
            error_log(
                'Lead save failed: ' . $error->getMessage()
            );

            $errors[] =
                'The lead could not be saved. Please try again.';
        }
    }
}

$page_title = $id ? 'Edit Lead' : 'Add Lead';
$active = 'add';

require __DIR__ . '/includes/layout_top.php';
?>

<div class="topbar">

    <div>
        <div class="eyebrow">
            <?= $id ? 'Editing lead #' . $id : 'New entry' ?>
        </div>

        <h1>
            <?= $id ? 'Edit lead' : 'Add a new lead' ?>
        </h1>
    </div>

</div>

<?php if ($errors): ?>

    <div class="flash error">
        <?= implode(
            '<br>',
            array_map('e', $errors)
        ) ?>
    </div>

<?php endif; ?>

<form method="post" class="card" id="leadForm">

    <input
        type="hidden"
        name="csrf"
        value="<?= e(csrf_token()) ?>"
    >

    <?php if ($id): ?>
        <input
            type="hidden"
            name="id"
            value="<?= $id ?>"
        >
    <?php endif; ?>

    <h2>Inquiry details</h2>

    <div class="form-row">

        <div class="field">
            <label for="received_date">
                Date received
            </label>

            <input
                type="date"
                id="received_date"
                name="received_date"
                value="<?= e($lead['received_date']) ?>"
                required
            >
        </div>

        <div class="field">
            <label for="source">
                How it came in
            </label>

            <select id="source" name="source" required>

                <?php foreach (SOURCE_LABELS as $key => $label): ?>

                    <option
                        value="<?= e($key) ?>"
                        <?= $lead['source'] === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>

        <div class="field">
            <label for="grade">
                Grade applying for
            </label>

            <input
                type="text"
                id="grade"
                name="grade"
                value="<?= e($lead['grade']) ?>"
                placeholder="e.g. Grade 8"
            >
        </div>

    </div>

    <div class="form-row">

        <div class="field">
            <label for="contact">
                Contact number
            </label>

            <input
                type="text"
                id="contact"
                name="contact"
                value="<?= e($lead['contact']) ?>"
                required
            >
        </div>

        <div class="field">
            <label for="parent_name">
                Parent name
            </label>

            <input
                type="text"
                id="parent_name"
                name="parent_name"
                value="<?= e($lead['parent_name']) ?>"
            >
        </div>

        <div class="field">
            <label for="child_name">
                Child name
            </label>

            <input
                type="text"
                id="child_name"
                name="child_name"
                value="<?= e($lead['child_name']) ?>"
            >
        </div>

    </div>

    <div class="form-row">

        <div class="field">
            <label for="current_school">
                Current school
            </label>

            <input
                type="text"
                id="current_school"
                name="current_school"
                value="<?= e($lead['current_school']) ?>"
            >
        </div>

        <div class="field">
            <label for="location">
                Location / town
            </label>

            <input
                type="text"
                id="location"
                name="location"
                value="<?= e($lead['location']) ?>"
            >
        </div>

        <div class="field">
            <label for="fb_name">
                Facebook name
            </label>

            <input
                type="text"
                id="fb_name"
                name="fb_name"
                value="<?= e($lead['fb_name']) ?>"
            >
        </div>

    </div>

    <div class="field">

        <label for="inquiry_notes">
            What they are asking / initial notes
        </label>

        <textarea
            id="inquiry_notes"
            name="inquiry_notes"
            rows="5"
        ><?= e($lead['inquiry_notes']) ?></textarea>

    </div>

    <div class="form-row">

        <div class="field">
            <label for="transfer_period">
                Planning to transfer
            </label>

            <input
                type="text"
                id="transfer_period"
                name="transfer_period"
                value="<?= e($lead['transfer_period']) ?>"
                placeholder="e.g. Next term or within one month"
            >
        </div>

        <div class="field">
            <label for="reason">
                Reason for transfer
            </label>

            <input
                type="text"
                id="reason"
                name="reason"
                value="<?= e($lead['reason']) ?>"
                placeholder="e.g. Better academic support"
            >
        </div>

    </div>

    <h2>Status and progress</h2>

    <div class="form-row">

        <div class="field">
            <label for="status">
                Current status
            </label>

            <select
                id="status"
                name="status"
                required
                onchange="updateStatusFields()"
            >

                <?php foreach (STATUS_LABELS as $key => $label): ?>

                    <option
                        value="<?= e($key) ?>"
                        <?= $lead['status'] === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>

        <div
            class="field"
            id="rejection_wrap"
            style="<?= in_array(
                $lead['status'],
                [
                    'not_interested',
                    'wrong_lead',
                    'accidental_lead',
                    'rejected',
                    'random_click',
                ],
                true
            ) ? '' : 'display:none;' ?>"
        >

            <label for="rejection_reason">
                Reason
            </label>

            <input
                type="text"
                id="rejection_reason"
                name="rejection_reason"
                value="<?= e($lead['rejection_reason']) ?>"
                placeholder="Enter the reason for this status"
            >

        </div>

    </div>

    <div class="form-row">

        <div class="field">
            <label for="visit_date">
                School visit date
            </label>

            <input
                type="date"
                id="visit_date"
                name="visit_date"
                value="<?= e($lead['visit_date']) ?>"
            >

            <small>
                Use for Visit Scheduled or Visited.
            </small>
        </div>

        <div class="field">
            <label for="converted_date">
                Converted / joined date
            </label>

            <input
                type="date"
                id="converted_date"
                name="converted_date"
                value="<?= e($lead['converted_date']) ?>"
            >

            <small>
                Use when the lead is Converted or Joined.
            </small>
        </div>

    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:18px;">

        <button
            type="submit"
            class="btn btn-primary"
        >
            <?= $id ? 'Save changes' : 'Add lead' ?>
        </button>

        <a
            href="<?= $id
                ? 'lead_view.php?id=' . $id
                : 'leads.php' ?>"
            class="btn btn-outline"
        >
            Cancel
        </a>

    </div>

</form>

<script>
function updateStatusFields() {
    const status = document.getElementById('status').value;
    const rejectionWrap =
        document.getElementById('rejection_wrap');

    const rejectionReason =
        document.getElementById('rejection_reason');

    const negativeStatuses = [
        'not_interested',
        'wrong_lead',
        'accidental_lead',
        'rejected',
        'random_click'
    ];

    const needsReason =
        negativeStatuses.includes(status);

    rejectionWrap.style.display =
        needsReason ? 'block' : 'none';

    rejectionReason.required = needsReason;

    if (!needsReason) {
        rejectionReason.value = '';
    }

    const visitStatuses = [
        'visit_scheduled',
        'visited',
        'placement_test_scheduled',
        'placement_test_completed'
    ];

    const successStatuses = [
        'converted',
        'joined'
    ];

    const visitDate =
        document.getElementById('visit_date');

    const convertedDate =
        document.getElementById('converted_date');

    if (
        visitStatuses.includes(status)
        && !visitDate.value
    ) {
        visitDate.value = getLocalDate();
    }

    if (
        successStatuses.includes(status)
        && !convertedDate.value
    ) {
        convertedDate.value = getLocalDate();
    }
}

function getLocalDate() {
    const today = new Date();

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0')
    ].join('-');
}

document.addEventListener(
    'DOMContentLoaded',
    updateStatusFields
);
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>