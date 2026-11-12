<?php
// Data & Imports data layer (importHandler.php, importPreview.php, dataImports.php). Each template row is
// turned into the same form array the matching "add" page posts, then checked with that page's own
// validate function (donor_form_validate, donation_form_validate, hours_form_validate, comm_form_validate)
// and saved with its own create function, so an imported record follows exactly the rules of one typed in.
// On top of that: finding the donor a gift / shift / contact belongs to, duplicate checks, and the
// import_batches / import_rows bookkeeping (migration 018). Every statement is prepared.
require_once __DIR__ . '/dbinfo.php';
require_once __DIR__ . '/dbDonors.php';
require_once __DIR__ . '/dbDonations.php';
require_once __DIR__ . '/dbVolunteerHours.php';
require_once __DIR__ . '/dbCommunications.php';

// The template columns per type: heading => [required, example]. The headings must match
// templates/fishnet-import-<type>.xlsx (row 1). A row equal to the example row is skipped.
const IMPORT_TYPES = [
  'donors' => ['label' => 'Donors', 'columns' => [
    'Donor type' => [true, 'individual'], 'First name' => [false, 'Jane'], 'Middle name' => [false, 'A'],
    'Last name' => [false, 'Smith'], 'Organization name' => [false, ''], 'Organization contact' => [false, ''],
    'Email' => [false, 'jane.smith@example.com'], 'Phone' => [false, '540-555-0123'], 'Street' => [false, '12 Main Street'],
    'City' => [false, 'Warrenton'], 'State' => [false, 'VA'], 'ZIP' => [false, '20186'], 'Preferred contact' => [false, 'email'],
    'Volunteer' => [false, 'No'], 'Email updates' => [false, 'Yes'], 'Mail receipts' => [false, 'No'],
    'Do not contact' => [false, 'No'], 'Donor since' => [false, '03/15/2021'], 'Notes' => [false, 'Met at the spring food drive']]],
  'cash' => ['label' => 'Cash donations', 'columns' => [
    'Donation date' => [true, '10/14/2026'], 'Donor email' => [false, 'jane.smith@example.com'], 'Donor ID' => [false, ''],
    'Donor first name' => [false, 'Jane'], 'Donor last name' => [false, 'Smith'], 'Amount' => [true, '250'],
    'Fee' => [false, ''], 'Payment method' => [true, 'check'], 'Platform' => [true, 'in_person'], 'Check number' => [false, '1042'],
    'Deposit date' => [false, '10/16/2026'], 'Campaign' => [false, 'Thanksgiving Drive'], 'In honor of' => [false, ''],
    'Send receipt' => [false, 'Yes'], 'Reason / notes' => [false, 'Annual gift']]],
  'noncash' => ['label' => 'Non-cash donations', 'columns' => [
    'Date received' => [true, '10/20/2026'], 'Donor email' => [false, 'jane.smith@example.com'], 'Donor ID' => [false, ''],
    'Donor first name' => [false, 'Jane'], 'Donor last name' => [false, 'Smith'], 'Item description' => [true, 'Canned green beans'],
    'Category' => [true, 'canned_food'], 'Quantity' => [true, '24'], 'Weight (lbs)' => [true, '18'],
    'Estimated value' => [false, '36'], 'Condition' => [false, 'new'], 'Intended use' => [true, 'Food pantry'],
    'Campaign' => [false, 'Thanksgiving Drive'], 'Send receipt' => [false, 'No'], 'Notes' => [false, '']]],
  'volunteer' => ['label' => 'Volunteer hours', 'columns' => [
    'Volunteer email' => [false, 'jane.smith@example.com'], 'Donor ID' => [false, ''], 'First name' => [false, 'Jane'],
    'Last name' => [false, 'Smith'], 'Date' => [true, '10/22/2026'], 'Start time' => [false, '9:00 AM'],
    'End time' => [false, '12:30 PM'], 'Hours' => [true, '3.5'], 'Job' => [false, 'Pantry sorting'],
    'Location' => [false, 'FISH pantry'], 'Campaign' => [false, ''], 'Notes' => [false, '']]],
  'comms' => ['label' => 'Communications', 'columns' => [
    'Date' => [true, '11/02/2026'], 'Donor email' => [false, 'jane.smith@example.com'], 'Donor ID' => [false, ''],
    'First name' => [false, 'Jane'], 'Last name' => [false, 'Smith'], 'Type' => [true, 'email'], 'Direction' => [false, 'outbound'],
    'Subject' => [false, 'Thanksgiving Drive thank you'], 'Summary' => [false, 'Thanked for canned food gift'],
    'Person contacted' => [false, ''], 'Status' => [false, 'sent'], 'Campaign' => [false, '']]],
];

// Marks a duplicate the user chose to "Import anyway" before the batch was imported.
const IMPORT_FORCED = 'Import anyway (possible duplicate)';

// Who may import each type: data_import edit plus the right the matching add page needs.
function import_can($type) {
    if (!fish_can('data_import', 'edit')) { return false; }
    switch ($type) {
        case 'donors':    return fish_can('donors', 'view') && fish_can('donors', 'edit');
        case 'cash':
        case 'noncash':   return fish_can_record_donations();
        case 'volunteer': return fish_can_log_hours();
        case 'comms':     return fish_can_log_communications();
    }
    return false;
}

// ---------- reading cells ----------

/** Lower-case heading without "*" and extra spaces, so "Amount *" and "amount" both match "Amount". */
function import_key($heading) {
    return strtolower(trim(preg_replace('/\s+/', ' ', str_replace('*', '', (string) $heading))));
}

/** The row re-keyed by template heading ('' for a missing column). */
function import_cells($type, array $row) {
    $byKey = [];
    foreach ($row as $h => $v) { $byKey[import_key($h)] = trim((string) $v); }
    $out = [];
    foreach (array_keys(IMPORT_TYPES[$type]['columns']) as $h) { $out[$h] = $byKey[import_key($h)] ?? ''; }
    return $out;
}

/** Required template headings missing from the file's heading row. */
function import_missing_headers($type, array $headers) {
    $have = array_map('import_key', $headers);
    $missing = [];
    foreach (IMPORT_TYPES[$type]['columns'] as $h => [$required]) {
        if ($required && !in_array(import_key($h), $have, true)) { $missing[] = $h; }
    }
    return $missing;
}

/** True when the row is the template's grey example row. */
function import_is_example($type, array $cells) {
    foreach (IMPORT_TYPES[$type]['columns'] as $h => [, $example]) {
        if (strtolower($cells[$h]) !== strtolower($example)) { return false; }
    }
    return true;
}

/** MM/DD/YYYY, M/D/YY or YYYY-MM-DD -> 'Y-m-d'; '' stays ''; anything else is returned unchanged (and fails validation). */
function import_date($s) {
    $s = trim($s);
    if ($s === '') { return ''; }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $s, $m)) {
        // Two-digit years: up to this year is 20YY, later ones are 19YY ("03/15/85" is 1985).
        $y = strlen($m[3]) === 2 ? ((int) $m[3] <= (int) date('y') ? 2000 : 1900) + (int) $m[3] : (int) $m[3];
        return checkdate((int) $m[1], (int) $m[2], $y) ? sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]) : $s;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : $s;
    }
    return $s;
}

/** "9:00 AM", "12:30 pm", "14:05" -> "HH:MM"; '' stays ''; anything else unchanged (fails validation). */
function import_time($s) {
    $s = trim($s);
    if (preg_match('/^(\d{1,2}):(\d{2})\s*([ap])\.?m\.?$/i', $s, $m)) {
        $h = (int) $m[1] % 12 + (strtolower($m[3]) === 'p' ? 12 : 0);
        return (int) $m[1] <= 12 ? sprintf('%02d:%s', $h, $m[2]) : $s;
    }
    return $s;
}

/** "Yes"/"Y"/"true"/"1"/"x" -> true. */
function import_yes($s) {
    return in_array(strtolower(trim($s)), ['yes', 'y', 'true', '1', 'x'], true);
}

/** "$1,250.00" -> "1250.00" (money and numbers are typed loosely in spreadsheets). */
function import_number($s) {
    return str_replace([',', '$', ' '], '', trim($s));
}

/** A value with spaces turned into underscores and lower-cased: "In person" -> "in_person". */
function import_enum($s) {
    return strtolower(str_replace([' ', '-'], '_', trim($s)));
}

// ---------- lookups (cached per request) ----------

/**
 * The donor a gift / shift / contact row belongs to, by Donor ID, then email, then first + last name.
 * Returns [id, error]. A row with no donor details at all returns [null, null] (the caller decides whether
 * that means anonymous or an error).
 */
function import_find_donor($idText, $email, $first, $last) {
    if ($idText === '' && $email === '' && $first === '' && $last === '') { return [null, null]; }
    $con = connect();
    try {
        if ($idText !== '') {
            if (!ctype_digit($idText)) { return [null, 'Donor ID must be a number.']; }
            $stmt = $con->prepare('SELECT id FROM donors WHERE id = ? AND is_active = 1 AND id <> ?');
            $id = (int) $idText; $anon = DONOR_ANONYMOUS_ID;
            $stmt->bind_param('ii', $id, $anon);
        } elseif ($email !== '') {
            $stmt = $con->prepare('SELECT id FROM donors WHERE LOWER(email) = LOWER(?) AND is_active = 1 AND id <> ?');
            $anon = DONOR_ANONYMOUS_ID;
            $stmt->bind_param('si', $email, $anon);
        } else {
            if ($first === '' || $last === '') { return [null, 'Give the donor\'s email, Donor ID, or both first and last name.']; }
            $stmt = $con->prepare('SELECT id FROM donors WHERE LOWER(`first`) = LOWER(?) AND LOWER(`last`) = LOWER(?) AND is_active = 1 AND id <> ?');
            $anon = DONOR_ANONYMOUS_ID;
            $stmt->bind_param('ssi', $first, $last, $anon);
        }
        $stmt->execute();
        $ids = array_map(fn($r) => (int) $r[0], $stmt->get_result()->fetch_all(MYSQLI_NUM));
        $stmt->close();
    } finally {
        mysqli_close($con);
    }
    $who = $idText !== '' ? "Donor ID $idText" : ($email !== '' ? $email : "$first $last");
    if (!$ids) { return [null, "No active donor found for $who. Add the donor first, or check the spelling."]; }
    if (count($ids) > 1) { return [null, "More than one donor matches $who. Add their Donor ID or email."]; }
    return [$ids[0], null];
}

/** Campaign name -> id among the campaigns the add pages offer (open ones); [id|'', error]. */
function import_find_campaign($name) {
    static $byName = null;
    if ($name === '') { return ['', null]; }
    if ($byName === null) {
        $byName = [];
        foreach (donation_campaigns() as $id => $c) { $byName[strtolower(trim($c['name']))] = (string) $id; }
    }
    $id = $byName[strtolower(trim($name))] ?? null;
    return $id === null ? ['', "No open campaign named \"$name\"."] : [$id, null];
}

// ---------- one row -> form ----------

/**
 * Checks one row. Returns ['status' => 'ready'|'error'|'duplicate', 'errors' => [...], 'form' => array,
 * 'donor' => id|null, 'match' => id|null]. $seen collects duplicate keys already in this file.
 */
function import_check_row($type, array $cells, array &$seen, $rowNo = '?') {
    $errors = [];
    $form = [];
    $donorId = null;
    // Required columns are checked by each type's own validator below (with the add page's wording);
    // Donor type is the one the donor form defaults instead of refusing, so it is checked here.
    if ($type === 'donors' && $cells['Donor type'] === '') { $errors[] = 'Donor type is required.'; }

    if ($type === 'donors') {
        $t = strtolower($cells['Donor type']);
        $post = [
            'type' => $t === '' ? 'Individual' : (DONOR_TYPE_LABELS[$t] ?? '?'),
            'first' => $cells['First name'], 'middle' => $cells['Middle name'], 'last' => $cells['Last name'],
            'org_name' => $cells['Organization name'], 'email' => $cells['Email'], 'phone' => $cells['Phone'],
            'preferred' => DONOR_PREFERRED_LABELS[strtolower($cells['Preferred contact'])] ?? '',
            'street' => $cells['Street'], 'city' => $cells['City'], 'state' => strtoupper($cells['State']), 'zip' => $cells['ZIP'],
        ];
        foreach (['volunteer' => 'Volunteer', 'email_updates' => 'Email updates', 'mail_receipts' => 'Mail receipts',
                  'do_not_contact' => 'Do not contact'] as $k => $h) {
            if (import_yes($cells[$h])) { $post[$k] = '1'; }
        }
        if (in_array($post['type'], DONOR_CONTACT_TYPES, true) && $cells['Organization contact'] !== '') {
            $post['contacts'] = [['name' => $cells['Organization contact']]];
            $post['primary_contact'] = '0';
        }
        if ($post['type'] === '?') { $errors[] = 'Donor type must be individual, organization or foundation.'; $post['type'] = 'Individual'; }
        if ($cells['Preferred contact'] !== '' && $post['preferred'] === '') { $errors[] = 'Preferred contact must be email, phone, mail or text.'; }
        $form = donor_form_from_post($post, []);
        $errors = array_merge($errors, donor_form_validate($form));
        $form['donor_since'] = import_date($cells['Donor since']);
        if ($form['donor_since'] !== '' && !donation_valid_date($form['donor_since'])) { $errors[] = 'Donor since must be a date (MM/DD/YYYY).'; }
        elseif ($form['donor_since'] > date('Y-m-d')) { $errors[] = 'Donor since cannot be in the future.'; }
        $form['notes'] = $cells['Notes'];
        if (mb_strlen($form['notes'], 'UTF-8') > 2000) { $errors[] = 'Notes must be 2000 characters or fewer.'; }
    } else {
        // Every other type belongs to a donor.
        $emailCol = $type === 'volunteer' ? 'Volunteer email' : 'Donor email';
        $firstCol = in_array($type, ['cash', 'noncash'], true) ? 'Donor first name' : 'First name';
        $lastCol = in_array($type, ['cash', 'noncash'], true) ? 'Donor last name' : 'Last name';
        [$donorId, $donorError] = import_find_donor($cells['Donor ID'], $cells[$emailCol], $cells[$firstCol], $cells[$lastCol]);
        if ($donorError) { $errors[] = $donorError; }
        $anonymous = $donorId === null && $donorError === null;
        if ($anonymous && !in_array($type, ['cash', 'noncash'], true)) {
            $errors[] = 'Give the ' . ($type === 'volunteer' ? 'volunteer' : 'donor') . '\'s email, Donor ID, or first and last name.';
        }
        [$campaign, $campaignError] = import_find_campaign($cells['Campaign']);
        if ($campaignError) { $errors[] = $campaignError; }

        if ($type === 'cash' || $type === 'noncash') {
            $post = ['donor' => (string) ($anonymous ? DONOR_ANONYMOUS_ID : (int) $donorId), 'campaign' => $campaign];
            if ($type === 'cash') {
                $post += ['date_received' => import_date($cells['Donation date']), 'amount' => import_number($cells['Amount']),
                          'fee' => import_number($cells['Fee']), 'pay_type' => import_enum($cells['Payment method']),
                          'platform' => import_enum($cells['Platform']), 'check_number' => $cells['Check number'],
                          'deposit_date' => import_date($cells['Deposit date']), 'notes' => $cells['Reason / notes'],
                          'honoree_name' => $cells['In honor of']];
                if ($cells['In honor of'] !== '') { $post['in_honor'] = '1'; }
            } else {
                $post += ['date_received' => import_date($cells['Date received']), 'item_description' => $cells['Item description'],
                          'category' => import_enum($cells['Category']), 'quantity' => import_number($cells['Quantity']),
                          'weight_lbs' => import_number($cells['Weight (lbs)']), 'estimated_value' => import_number($cells['Estimated value']),
                          'condition' => import_enum($cells['Condition']), 'intended_use' => $cells['Intended use'], 'notes' => $cells['Notes']];
            }
            if (import_yes($cells['Send receipt'])) { $post['send_receipt'] = '1'; }
            $form = donation_form_from_post($post, $type);
            $more = donation_form_validate($form, $type);
            // The add page never offers the anonymous donor, so its validator refuses it; a template row with
            // no donor details is an anonymous gift on purpose.
            if ($anonymous || $donorError) { $more = array_values(array_filter($more, fn($e) => strpos($e, 'donor') === false)); }
            $errors = array_merge($errors, $more);
        } elseif ($type === 'volunteer') {
            static $jobs = null, $campaigns = null;
            $jobs = $jobs ?? hours_job_options();
            $campaigns = $campaigns ?? hours_campaign_names();
            $form = hours_form_from_post(['date' => import_date($cells['Date']), 'start_time' => import_time($cells['Start time']),
                                          'end_time' => import_time($cells['End time']), 'hours' => import_number($cells['Hours']),
                                          'job' => $cells['Job'], 'campaign_id' => $campaign, 'location' => $cells['Location'],
                                          'notes' => $cells['Notes']]);
            $errors = array_merge($errors, hours_form_validate($form, null, $jobs, $campaigns, (int) $donorId));
        } else {   // comms
            static $staff = null, $commCampaigns = null;
            $staff = $staff ?? comm_staff_options();
            $commCampaigns = $commCampaigns ?? comm_campaign_options();
            $person = $cells['Person contacted'];
            $form = comm_form_from_post(['donor_id' => $donorId ? (string) $donorId : '', 'comm_type' => import_enum($cells['Type']),
                                         'direction' => import_enum($cells['Direction']) ?: 'outbound',
                                         'occurred_at' => import_date($cells['Date']), 'status' => import_enum($cells['Status']) ?: 'logged',
                                         'subject' => $cells['Subject'], 'campaign_id_one' => $campaign, 'summary' => $cells['Summary'],
                                         'person_contacted' => $person === '' ? '' : COMM_PERSON_OTHER, 'person_contacted_other' => $person]);
            $donorList = $donorId ? [(string) $donorId => 'donor'] : [];
            $more = comm_form_validate($form, $donorList, $staff, $commCampaigns);
            if ($donorId === null) { $more = array_values(array_filter($more, fn($e) => strpos($e, 'donor') === false)); }
            $errors = array_merge($errors, $more);
        }
    }

    $errors = array_values(array_unique($errors));
    if ($errors) { return ['status' => 'error', 'errors' => $errors, 'form' => $form, 'donor' => $donorId, 'match' => null, 'key' => null]; }

    // Duplicates: the same record already in FISH Net, or earlier in this file.
    [$key, $match, $what] = import_duplicate($type, $form);
    $key = $key === null ? null : hash('sha256', $key);   // stored in import_rows.dup_key; long cells cannot be cut short
    if ($key !== null && isset($seen[$key])) {
        return ['status' => 'duplicate', 'errors' => ["Same as row {$seen[$key]} of this file."], 'form' => $form, 'donor' => $donorId, 'match' => null, 'key' => $key];
    }
    if ($key !== null) { $seen[$key] = $rowNo; }
    if ($match !== null) {
        return ['status' => 'duplicate', 'errors' => [$what], 'form' => $form, 'donor' => $donorId, 'match' => $match, 'key' => $key];
    }
    return ['status' => 'ready', 'errors' => [], 'form' => $form, 'donor' => $donorId, 'match' => null, 'key' => $key];
}

/**
 * [file key, id of the matching record already stored or null, message]. The key spots the same row twice
 * in one file; the query spots a record already in FISH Net.
 */
function import_duplicate($type, array $form) {
    switch ($type) {
        case 'donors':
            $email = strtolower($form['email']);
            $isOrg = in_array($form['type'], DONOR_CONTACT_TYPES, true);
            $name = strtolower($isOrg ? $form['org_name'] : $form['first'] . ' ' . $form['last']);
            $key = 'd|' . ($email !== '' ? $email : $name . '|' . strtolower($form['street']));
            if ($email !== '') {
                $sql = 'SELECT id FROM donors WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1';
                $args = ['s', $email];
                $what = 'A donor with this email is already in FISH Net';
            } elseif ($isOrg) {
                $sql = 'SELECT id FROM donors WHERE LOWER(org_name) = ? AND is_active = 1 LIMIT 1';
                $args = ['s', strtolower($form['org_name'])];
                $what = ($form['type'] === 'Foundation' ? 'A foundation' : 'An organization') . ' with this name is already in FISH Net';
            } else {
                $sql = "SELECT id FROM donors WHERE LOWER(`first`) = ? AND LOWER(`last`) = ? AND is_active = 1
                          AND (? = '' OR LOWER(COALESCE(street, '')) = ? OR COALESCE(zip, '') = ?) LIMIT 1";
                $street = strtolower($form['street']);
                $args = ['sssss', strtolower($form['first']), strtolower($form['last']), $street . $form['zip'], $street, $form['zip']];
                $what = 'A donor with this name' . ($street . $form['zip'] !== '' ? ' and address' : '') . ' is already in FISH Net';
            }
            break;
        case 'cash':
            // Anonymous gifts of the same amount on the same day are normal (a cash jar); only a check number
            // can tell two of them apart, so without one they are not checked.
            if ((int) $form['donor'] === DONOR_ANONYMOUS_ID && $form['check_number'] === '') { return [null, null, '']; }
            $key = 'c|' . $form['donor'] . '|' . $form['date_received'] . '|' . (float) $form['amount'] . '|' . $form['check_number'];
            $sql = 'SELECT id FROM donations WHERE donorID = ? AND donation_date = ? AND amount = ? AND COALESCE(check_number, \'\') = ? LIMIT 1';
            $args = ['isss', (int) $form['donor'], $form['date_received'], $form['amount'], $form['check_number']];
            $what = 'The same gift (donor, date, amount' . ($form['check_number'] !== '' ? ', check number' : '') . ') is already recorded';
            break;
        case 'noncash':
            if ((int) $form['donor'] === DONOR_ANONYMOUS_ID) { return [null, null, '']; }   // anonymous drop-offs
            $key = 'n|' . $form['donor'] . '|' . $form['date_received'] . '|' . strtolower($form['item_description']) . '|' . (float) $form['quantity'];
            $sql = 'SELECT id FROM non_cash_donations WHERE donor_id = ? AND date_received = ? AND LOWER(item_description) = ? AND quantity = ? LIMIT 1';
            $args = ['isss', (int) $form['donor'], $form['date_received'], strtolower($form['item_description']), $form['quantity']];
            $what = 'The same item gift (donor, date, item, quantity) is already recorded';
            break;
        case 'volunteer':
            return [null, null, ''];   // checked in import_batch_create() / import_row_save(), which know the volunteer
        default:   // comms
            $key = 'm|' . $form['donor'] . '|' . $form['date'] . '|' . $form['comm_type'] . '|' . strtolower($form['subject']);
            $sql = 'SELECT id FROM communications WHERE donor_id = ? AND DATE(occurred_at) = ? AND comm_type = ? AND COALESCE(subject, \'\') = ? LIMIT 1';
            $args = ['isss', (int) $form['donor'], $form['date'], $form['comm_type'], $form['subject']];
            $what = 'The same contact (donor, date, type, subject) is already logged';
    }
    $con = connect();
    $stmt = $con->prepare($sql);
    $stmt->bind_param(...$args);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    mysqli_close($con);
    return [$key, $row ? (int) $row[0] : null, $what];
}

/** Volunteer hours duplicate check needs the donor, which is not part of the hours form. */
function import_hours_duplicate($donorId, array $form) {
    $con = connect();
    $stmt = $con->prepare('SELECT id FROM volunteer_hours WHERE donor_id = ? AND activity_date = ? AND hours = ? AND COALESCE(TIME_FORMAT(start_time, \'%H:%i\'), \'\') = ? LIMIT 1');
    $hours = number_format((float) $form['hours'], 2, '.', '');
    $stmt->bind_param('isss', $donorId, $form['date'], $hours, $form['start']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    mysqli_close($con);
    return $row ? (int) $row[0] : null;
}

/**
 * import_check_row() plus the volunteer-hours duplicate check (which needs the volunteer, not part of the
 * hours form). Used for every check: at upload, when a row is fixed or re-checked, and right before it is
 * saved. $seen maps duplicate key => sheet row for the other rows of the same file.
 */
function import_check_full($type, array $cells, array &$seen, $rowNo) {
    $r = import_check_row($type, $cells, $seen, $rowNo);
    if ($type !== 'volunteer' || $r['status'] !== 'ready') { return $r; }
    $key = hash('sha256', 'h|' . $r['donor'] . '|' . $r['form']['date'] . '|' . $r['form']['hours'] . '|' . $r['form']['start']);
    $r['key'] = $key;
    if (isset($seen[$key])) {
        return ['status' => 'duplicate', 'errors' => ["Same as row {$seen[$key]} of this file."]] + $r;
    }
    $seen[$key] = $rowNo;
    if ($match = import_hours_duplicate((int) $r['donor'], $r['form'])) {
        return ['status' => 'duplicate', 'errors' => ['The same shift (volunteer, date, hours, start) is already logged'], 'match' => $match] + $r;
    }
    return $r;
}

/**
 * Duplicate keys of the other rows of a batch that are (or will be) records: key => sheet row. A row the
 * user chose to "Import anyway" is left out, so the row it duplicates is not turned into a duplicate of it.
 */
function import_batch_keys($batchId, $exceptRowId) {
    $con = connect();
    $stmt = $con->prepare("SELECT dup_key, sheet_row FROM import_rows WHERE batch_id = ? AND id <> ? AND dup_key IS NOT NULL
                           AND status IN ('ready', 'saving', 'imported') AND COALESCE(problem, '') <> ? ORDER BY sheet_row");
    $forced = IMPORT_FORCED;
    $stmt->bind_param('iis', $batchId, $exceptRowId, $forced);
    $stmt->execute();
    $seen = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$k, $n]) { $seen[$k] = $seen[$k] ?? (int) $n; }
    $stmt->close();
    mysqli_close($con);
    return $seen;
}

/** Saves one checked row with the add page's create function. Returns the new record id, or false. */
function import_create($type, array $form, $donorId, $byUser) {
    switch ($type) {
        case 'donors':
            $id = donor_create($form, $byUser);
            if ($id && ($form['notes'] !== '' || $form['donor_since'] !== '')) {
                // donor_create() has no notes / donor-since fields; set them right after. If this fails the donor
                // still exists, so the row is still marked imported (the caller must not create it again).
                try {
                    $con = connect();
                    $stmt = $con->prepare('UPDATE donors SET notes = COALESCE(?, notes), donor_since = COALESCE(?, donor_since) WHERE id = ?');
                    $notes = $form['notes'] === '' ? null : $form['notes'];
                    $since = $form['donor_since'] === '' ? null : $form['donor_since'];
                    $stmt->bind_param('ssi', $notes, $since, $id);
                    $stmt->execute();
                    $stmt->close();
                    mysqli_close($con);
                } catch (mysqli_sql_exception $e) {
                    error_log('import: donor ' . $id . ' notes/donor_since not saved: ' . $e->getMessage());
                }
            }
            return $id;
        case 'cash':
        case 'noncash':
            return donation_create($type, $form, $byUser);
        case 'volunteer':
            return hours_create([(int) $donorId], $form, $byUser) ? true : false;   // hours_create returns a count, not an id
        default:
            return comm_create($form, comm_staff_options(), $byUser);
    }
}

// ---------- batches ----------

/**
 * Checks every row of a parsed file and stores the batch. Returns the new batch id, or false on a database
 * error. $rows is rowNumber => [heading => text] from import_read_file().
 */
function import_batch_create($type, $fileName, array $rows, $byUser) {
    $seen = [];
    $checked = [];
    foreach ($rows as $n => $row) {
        $cells = import_cells($type, $row);
        if (import_is_example($type, $cells)) { continue; }
        $r = import_check_full($type, $cells, $seen, $n);
        $checked[$n] = [$cells, $r];
    }
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $stmt = $con->prepare('INSERT INTO import_batches (import_type, file_name, rows_total, uploaded_by) VALUES (?, ?, ?, ?)');
        $total = count($checked);
        $name = mb_substr($fileName, 0, 255, 'UTF-8');
        $stmt->bind_param('ssis', $type, $name, $total, $byUser);
        $stmt->execute();
        $batchId = (int) $con->insert_id;
        $stmt->close();
        $stmt = $con->prepare('INSERT INTO import_rows (batch_id, sheet_row, data, status, problem, match_id, dup_key) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($checked as $n => [$cells, $r]) {
            $json = json_encode($cells, JSON_UNESCAPED_UNICODE);
            $problem = $r['errors'] ? implode('; ', $r['errors']) : null;
            $match = $r['match'];
            $key = $r['key'] === null ? null : mb_substr($r['key'], 0, 512, 'UTF-8');
            $stmt->bind_param('iisssis', $batchId, $n, $json, $r['status'], $problem, $match, $key);
            $stmt->execute();
        }
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        return $batchId;
    } catch (mysqli_sql_exception $e) {
        error_log('import_batch_create failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (mysqli_sql_exception $ignored) {} }
        return false;
    }
}

function import_batch_get($id) {
    $con = connect();
    $stmt = $con->prepare('SELECT * FROM import_batches WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    return $b ?: null;
}

/** Rows of one batch, optionally only some statuses, in sheet order. data is decoded. */
function import_batch_rows($batchId, array $statuses = []) {
    $con = connect();
    $sql = 'SELECT * FROM import_rows WHERE batch_id = ?';
    if ($statuses) { $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; }
    $stmt = $con->prepare($sql . ' ORDER BY sheet_row');
    $stmt->bind_param('i' . str_repeat('s', count($statuses)), $batchId, ...$statuses);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    foreach ($rows as &$r) { $r['data'] = json_decode($r['data'], true) ?: []; }
    return $rows;
}

/** status => count for one batch. */
function import_batch_counts($batchId) {
    $counts = ['ready' => 0, 'error' => 0, 'duplicate' => 0, 'imported' => 0, 'skipped' => 0];
    $con = connect();
    $stmt = $con->prepare('SELECT status, COUNT(*) FROM import_rows WHERE batch_id = ? GROUP BY status');
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$s, $n]) { $counts[$s] = (int) $n; }
    $stmt->close();
    mysqli_close($con);
    return $counts;
}

/**
 * Re-checks one stored row and saves it. $force imports a duplicate anyway (errors are never forced).
 * The row is claimed first (status -> 'saving' only if it is still in the status it was read with), so a
 * double-clicked Import or row action cannot create the record twice. Returns ['ok' => bool, 'problem' => string].
 */
function import_row_save(array $batch, array $row, $byUser, $force = false) {
    $type = $batch['import_type'];
    $rowId = (int) $row['id'];
    if (!import_row_claim($rowId, $row['status'])) {
        return ['ok' => false, 'problem' => 'This row was already handled.'];
    }
    try {
        $seen = import_batch_keys((int) $batch['id'], $rowId);
        $r = import_check_full($type, import_cells($type, $row['data']), $seen, (int) $row['sheet_row']);
    } catch (Throwable $e) {
        import_row_mark($rowId, $row['status'], $row['problem'], null, $byUser, $row['match_id'], $row['dup_key'] ?? null);
        throw $e;
    }
    if ($r['status'] === 'error' || ($r['status'] === 'duplicate' && !$force)) {
        import_row_mark($rowId, $r['status'], implode('; ', $r['errors']), null, $byUser, $r['match'], $r['key']);
        return ['ok' => false, 'problem' => implode('; ', $r['errors'])];
    }
    try {
        $id = import_create($type, $r['form'], $r['donor'], $byUser);
    } catch (Throwable $e) {
        error_log('import_row_save create failed: ' . $e->getMessage());
        $id = false;
    }
    if (!$id) {
        import_row_mark($rowId, 'error', 'Could not be saved. Please try again.', null, $byUser, null, $r['key']);
        return ['ok' => false, 'problem' => 'Could not be saved. Please try again.'];
    }
    import_row_mark($rowId, 'imported', null, $id === true ? null : (int) $id, $byUser, $r['match'], $r['key']);
    try {
        $con = connect();
        $stmt = $con->prepare('UPDATE import_batches SET rows_added = rows_added + 1 WHERE id = ?');
        $bid = (int) $batch['id'];
        $stmt->bind_param('i', $bid);
        $stmt->execute();
        $stmt->close();
        mysqli_close($con);
    } catch (mysqli_sql_exception $e) {
        error_log('import_row_save count not updated: ' . $e->getMessage());
    }
    return ['ok' => true, 'problem' => ''];
}

/** Claims a row for saving: true only for the one request that moved it out of $fromStatus. */
function import_row_claim($rowId, $fromStatus) {
    $con = connect();
    $stmt = $con->prepare("UPDATE import_rows SET status = 'saving' WHERE id = ? AND status = ?");
    $stmt->bind_param('is', $rowId, $fromStatus);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    mysqli_close($con);
    return $ok;
}

/** Claims a checked batch for importing: true only for the one request that moved it from 'checked'. */
function import_batch_claim($batchId) {
    $con = connect();
    $stmt = $con->prepare("UPDATE import_batches SET status = 'importing', imported_at = NOW() WHERE id = ? AND status = 'checked'");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    mysqli_close($con);
    return $ok;
}

function import_row_mark($rowId, $status, $problem, $recordId, $byUser, $matchId, $dupKey = null) {
    $con = connect();
    $stmt = $con->prepare('UPDATE import_rows SET status = ?, problem = ?, record_id = ?, updated_by = ?, match_id = ?, dup_key = ? WHERE id = ?');
    $key = $dupKey === null ? null : mb_substr($dupKey, 0, 512, 'UTF-8');
    $stmt->bind_param('ssisisi', $status, $problem, $recordId, $byUser, $matchId, $key, $rowId);
    $stmt->execute();
    $stmt->close();
    mysqli_close($con);
}

function import_row_get($rowId) {
    $con = connect();
    $stmt = $con->prepare('SELECT * FROM import_rows WHERE id = ?');
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    if ($r) { $r['data'] = json_decode($r['data'], true) ?: []; }
    return $r ?: null;
}

/** Replaces a row's cells (from the Fix form) before it is re-checked. Only template headings are kept. */
function import_row_set_data($rowId, $type, array $cells) {
    $clean = [];
    foreach (array_keys(IMPORT_TYPES[$type]['columns']) as $h) {
        $clean[$h] = mb_substr(trim((string) ($cells[$h] ?? '')), 0, 5000, 'UTF-8');
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $con = connect();
    $stmt = $con->prepare('UPDATE import_rows SET data = ? WHERE id = ?');
    $stmt->bind_param('si', $json, $rowId);
    $stmt->execute();
    $stmt->close();
    mysqli_close($con);
    return $clean;
}

/** Cancels a batch only while it is still at the check step (an Import that already claimed it wins). */
function import_batch_cancel($batchId) {
    $con = connect();
    $stmt = $con->prepare("UPDATE import_batches SET status = 'cancelled' WHERE id = ? AND status = 'checked'");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    mysqli_close($con);
    return $ok;
}

/**
 * Batches whose Import was interrupted (status 'importing' for over 10 minutes since Import was clicked
 * (imported_at is set by import_batch_claim; the handler's time limit is 5 minutes): a crash, a lost connection
 * or the time limit): rows left 'ready' or 'saving' go to Needs review so nothing is silently lost, and the
 * batch is marked imported. A 'saving' row may already have its record, so its message says to check.
 */
function import_recover_stale() {
    $con = connect();
    $con->query("UPDATE import_rows r JOIN import_batches b ON b.id = r.batch_id
                 SET r.status = 'error', r.problem = IF(r.status = 'saving',
                     'The import stopped while this row was being saved. Check whether the record exists before you Fix it.',
                     'The import stopped before this row was saved. Fix it (save without changes) to import it.')
                 WHERE b.status = 'importing' AND b.imported_at < NOW() - INTERVAL 10 MINUTE AND r.status IN ('ready', 'saving')");
    $con->query("UPDATE import_batches SET status = 'imported' WHERE status = 'importing' AND imported_at < NOW() - INTERVAL 10 MINUTE");
    mysqli_close($con);
}

function import_batch_set_status($batchId, $status) {
    $con = connect();
    $stmt = $con->prepare("UPDATE import_batches SET status = ?, imported_at = IF(? = 'imported', COALESCE(imported_at, NOW()), imported_at) WHERE id = ?");
    $stmt->bind_param('ssi', $status, $status, $batchId);
    $stmt->execute();
    $stmt->close();
    mysqli_close($con);
}

/** The latest batches for Recent imports, with how many rows still need review. */
function import_recent($limit = 10) {
    $con = connect();
    $stmt = $con->prepare("SELECT b.*, (SELECT COUNT(*) FROM import_rows r WHERE r.batch_id = b.id AND r.status IN ('error','duplicate')) AS open_rows
                           FROM import_batches b WHERE b.status <> 'cancelled' ORDER BY b.created_at DESC, b.id DESC LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return $rows;
}

/** Rows still needing review from imported batches, newest batch first. */
function import_review_rows($limit = 50) {
    $con = connect();
    $stmt = $con->prepare("SELECT r.*, b.import_type, b.file_name, b.created_at AS batch_at
                           FROM import_rows r JOIN import_batches b ON b.id = r.batch_id
                           WHERE b.status IN ('importing', 'imported') AND r.status IN ('error','duplicate')
                           ORDER BY b.created_at DESC, r.sheet_row LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    foreach ($rows as &$r) { $r['data'] = json_decode($r['data'], true) ?: []; }
    return $rows;
}

/** A short "who / what" summary of a row for the review tables. */
function import_row_summary($type, array $data) {
    $pick = function (array $keys) use ($data) {
        $out = [];
        foreach ($keys as $k) { if (($data[$k] ?? '') !== '') { $out[] = $data[$k]; } }
        return implode(' · ', $out);
    };
    switch ($type) {
        case 'donors':    return $pick(['Organization name', 'First name', 'Last name', 'Email']);
        case 'cash':      return $pick(['Donation date', 'Donor email', 'Donor first name', 'Donor last name', 'Amount']);
        case 'noncash':   return $pick(['Date received', 'Donor email', 'Donor first name', 'Donor last name', 'Item description', 'Quantity']);
        case 'volunteer': return $pick(['Date', 'Volunteer email', 'First name', 'Last name', 'Hours']);
        default:          return $pick(['Date', 'Donor email', 'First name', 'Last name', 'Type', 'Subject']);
    }
}
