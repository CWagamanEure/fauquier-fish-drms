<?php
// One-on-one communications data layer for addCommunication.php (UF2-146 donor, UF2-147 person contacted,
// UF2-148 campaign): the form (blank / from post / validate), the lookups it is checked against, the
// insert, and the status labels the donor and campaign Communications tabs show. Every statement is
// prepared. The anonymous donor (999) is never written. Outreach saving is UF2-141..144 and is not here.
require_once __DIR__ . '/dbDonations.php';   // donation_valid_date(), donation_today()
require_once __DIR__ . '/dbDonors.php';      // donor_options(), DONOR_ANONYMOUS_ID
require_once __DIR__ . '/dbCampaigns.php';   // campaign_owner_options()
require_once __DIR__ . '/dbVolunteerHours.php';   // hours_campaign_names()

const COMM_TYPES = ['phone' => 'Phone', 'email' => 'Email', 'mail' => 'Mail', 'text' => 'Text',
                    'in_person' => 'In person', 'other' => 'Other'];
const COMM_DIRECTIONS = ['outbound' => 'FISH reached out', 'inbound' => 'Donor reached out'];
// The statuses the form offers, in its order. The enum has more (delivered, opened, failed) for imports.
const COMM_FORM_STATUSES = ['logged' => 'Logged', 'sent' => 'Sent', 'replied' => 'Replied',
                            'follow_up' => 'Follow up', 'bounced' => 'Bounced'];
const COMM_STATUS_LABELS = COMM_FORM_STATUSES + ['delivered' => 'Delivered', 'opened' => 'Opened', 'failed' => 'Failed'];
// "Someone else" in the Person contacted dropdown. Any account id is possible, so a staff id that happens to
// equal it is matched as staff first (comm_form_validate() sets person_is_staff).
const COMM_PERSON_OTHER = '__other__';
const COMM_TEXT_MAX = ['subject' => 255, 'summary' => 5000, 'person_other' => 255];

// Label for a stored status: follow_up -> "Follow up"; an unknown value is shown as stored.
function comm_status_label($status) {
    $status = (string) $status;
    return COMM_STATUS_LABELS[$status] ?? ucfirst($status);
}

// id => name of the active staff accounts (not vmsroot), the choices for Person contacted.
function comm_staff_options() {
    return array_column(campaign_owner_options(), 'name', 'id');
}

// id => name of the campaigns a contact can be linked to: the same list as the hours and donation forms.
function comm_campaign_options() {
    return hours_campaign_names();
}

function comm_form_blank() {
    return ['donor' => '', 'person' => '', 'person_other' => '', 'comm_type' => '', 'direction' => 'outbound',
            'date' => donation_today(), 'status' => 'logged', 'subject' => '', 'campaign' => '', 'summary' => '',
            'follow_up' => 0, 'follow_up_on' => '', 'person_is_staff' => false];
}

// The form array from a POST; only strings are accepted, arrays become ''.
function comm_form_from_post(array $post) {
    $str = function ($k) use ($post) { return isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : ''; };
    return ['donor' => $str('donor_id'), 'person' => $str('person_contacted'), 'person_other' => $str('person_contacted_other'),
            'comm_type' => $str('comm_type'), 'direction' => $str('direction'), 'date' => $str('occurred_at'),
            'status' => $str('status'), 'subject' => $str('subject'), 'campaign' => $str('campaign_id_one'),
            'summary' => $str('summary'), 'follow_up' => isset($post['follow_up']) ? 1 : 0,
            'follow_up_on' => $str('follow_up_on')];
}

// Validates and normalises $form in place against the lists the form offered (all id => name); returns
// the list of error messages. Keys are compared as strings so PHP's integer-key coercion cannot let a
// list position or a numeric string through.
function comm_form_validate(array &$form, array $donors, array $staff, array $campaigns) {
    $errors = [];
    $has = function (array $map, $key) {
        foreach (array_keys($map) as $k) { if ((string) $k === (string) $key) { return true; } }
        return false;
    };
    if ($form['donor'] === '') {
        $errors[] = 'Choose a donor.';
    } elseif (!ctype_digit($form['donor']) || !$has($donors, $form['donor'])) {
        $errors[] = 'Choose a donor from the list.';
        $form['donor'] = '';
    }
    // The select decides: a name left in the (hidden) "Someone else" box is ignored unless Someone else is
    // picked, so switching back to "Not recorded" really records no one.
    $form['person_is_staff'] = $form['person'] !== '' && $has($staff, $form['person']);
    if ($form['person_is_staff'] || $form['person'] === '') {
        $form['person_other'] = '';
    } elseif ($form['person'] === COMM_PERSON_OTHER) {
        if ($form['person_other'] === '') {
            $errors[] = 'Type the name of the person contacted, or pick one from the list.';
        } elseif (mb_strlen($form['person_other'], 'UTF-8') > COMM_TEXT_MAX['person_other']) {
            $errors[] = 'The name of the person contacted must be ' . COMM_TEXT_MAX['person_other'] . ' characters or fewer.';
        }
    } elseif ($form['person'] !== '') {
        $errors[] = 'Choose the person contacted from the list.';
        $form['person'] = '';
    }
    if (!array_key_exists($form['comm_type'], COMM_TYPES)) {
        $errors[] = 'Choose the type of communication.';
        $form['comm_type'] = '';
    }
    if (!array_key_exists($form['direction'], COMM_DIRECTIONS)) {
        $form['direction'] = 'outbound';
    }
    if (!donation_valid_date($form['date'])) {
        $errors[] = 'Enter the date of the communication.';
    } elseif ($form['date'] > donation_today()) {
        $errors[] = 'The date cannot be in the future.';
    }
    if (!array_key_exists($form['status'], COMM_FORM_STATUSES)) {
        $errors[] = 'Choose a status from the list.';
        $form['status'] = 'logged';
    }
    if ($form['campaign'] !== '' && (!ctype_digit($form['campaign']) || !$has($campaigns, $form['campaign']))) {
        $errors[] = 'Choose a campaign from the list, or None.';
        $form['campaign'] = '';
    }
    foreach (['subject' => 'Subject', 'summary' => 'Summary'] as $k => $label) {
        if (mb_strlen($form[$k], 'UTF-8') > COMM_TEXT_MAX[$k]) {
            $errors[] = $label . ' must be ' . COMM_TEXT_MAX[$k] . ' characters or fewer.';
        }
    }
    // Follow-up (the posted values are kept as posted, so a re-rendered form posts back the same thing):
    // the box ticked needs a date; a follow-up date is stored whenever one is given, whatever the status;
    // the stored status becomes follow_up when the box is ticked (comm_stored_status()).
    if ($form['follow_up'] && $form['follow_up_on'] === '') {
        $errors[] = 'Enter the follow-up date, or untick "Remind me to follow up".';
    } elseif ($form['follow_up_on'] !== '' && !donation_valid_date($form['follow_up_on'])) {
        $errors[] = 'Enter a valid follow-up date.';
    }
    return $errors;
}

// The status written for a validated form: the box ticked means follow_up; otherwise the chosen status.
function comm_stored_status(array $form) {
    return $form['follow_up'] ? 'follow_up' : $form['status'];
}

// $form must have passed comm_form_validate() (which sets person_is_staff).
// Inserts one communication after re-checking, in the same transaction, that the donor is still active and
// not anonymous. $staff is id => name (for the stored display name). Returns the new id, or false.
function comm_create(array $form, array $staff, $byUser) {
    $personId = null;
    $personName = null;
    if (!empty($form['person_is_staff'])) {
        $personId = $form['person'];
        $personName = (string) $staff[$form['person']];
    } elseif ($form['person'] === COMM_PERSON_OTHER) {
        $personName = $form['person_other'];
    }
    $donorId = (int) $form['donor'];
    $campaignId = $form['campaign'] === '' ? null : (int) $form['campaign'];
    // Today's contacts get the current time so they sort above earlier rows; past dates have no time.
    $occurred = $form['date'] === donation_today() ? date('Y-m-d H:i:s') : $form['date'] . ' 00:00:00';
    $subject = $form['subject'] === '' ? null : $form['subject'];
    $summary = $form['summary'] === '' ? null : $form['summary'];
    $followOn = $form['follow_up_on'] === '' ? null : $form['follow_up_on'];
    $status = comm_stored_status($form);
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $stmt = $con->prepare('SELECT 1 FROM donors WHERE id = ? AND is_active = 1 AND id <> ? LOCK IN SHARE MODE');
        $anon = DONOR_ANONYMOUS_ID;
        $stmt->bind_param('ii', $donorId, $anon);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if (!$ok) { $con->rollback(); mysqli_close($con); return false; }
        $stmt = $con->prepare('INSERT INTO communications (donor_id, campaign_id, occurred_at, comm_type, direction, subject,
                                   summary, person_contacted, person_contacted_id, status, follow_up_on, recorded_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iissssssssss', $donorId, $campaignId, $occurred, $form['comm_type'], $form['direction'], $subject,
                          $summary, $personName, $personId, $status, $followOn, $byUser);
        $stmt->execute();
        $id = (int) $con->insert_id;
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        return $id;
    } catch (mysqli_sql_exception $e) {
        error_log('comm_create failed: ' . $e->getMessage());
        if ($con instanceof mysqli) {
            try { $con->rollback(); } catch (mysqli_sql_exception $ignored) {}
            try { mysqli_close($con); } catch (Throwable $ignored) {}
        }
        return false;
    }
}
