<?php
include_once('dbinfo.php');

//get donations for a specific event id
function fetch_donations_for_event($id) {
    $con=connect();
    $stmt = $con->prepare('SELECT * FROM donations WHERE eventID = ?');
    $stmt->bind_param('s', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        mysqli_close($con);
        return null;
    }
    $donations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return $donations;
}

// ---------- FISH NET donation pages (UF2-42..57, UF2-87) ----------
// Every statement below is prepared. Cash gifts live in donations, item gifts in non_cash_donations;
// the list reads both as one set. Donor names come from donor_summary (see CLAUDE.md). "Today" and the
// date windows are computed in PHP (America/New_York) and bound, because the database may run in UTC.
require_once __DIR__ . '/dbDonors.php';   // donor_like_escape(), donor_get(), DONOR_ANONYMOUS_ID

const DONATIONS_PER_PAGE = 10;
const DONATION_KINDS = ['all', 'cash', 'noncash'];
const DONATION_RANGES = ['30', '90', 'year', 'all'];
const DONATION_SORTS = ['newest', 'oldest', 'amount'];
// Enum values of the columns (migrations 002 and 004) and the labels the pages show.
const DONATION_PAYMENT_LABELS = ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card', 'online' => 'Online',
                                 'digital_token' => 'Digital token', 'stock' => 'Stock', 'other' => 'Other'];
const DONATION_PLATFORM_LABELS = ['in_person' => 'In person', 'mighty_cause' => 'Mighty Cause',
                                  'benevity' => 'Benevity', 'other' => 'Other'];
const DONATION_CATEGORY_LABELS = ['canned_food' => 'Canned food', 'dry_goods' => 'Dry goods',
                                  'fresh_produce' => 'Fresh produce', 'clothing' => 'Clothing',
                                  'household_items' => 'Household items', 'hygiene_products' => 'Hygiene products',
                                  'other' => 'Other'];
const DONATION_CONDITION_LABELS = ['new' => 'New / unopened', 'gently_used' => 'Gently used', 'used' => 'Used'];
const DONATION_UNITS = ['items', 'lbs', 'bags', 'boxes'];
// Two decimals at most and no sign, exponent or separators, so the DECIMAL columns never round silently.
const DONATION_NUMBER_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';
const DONATION_MONEY_MAX = 9999999999.99;     // DECIMAL(12,2)
const DONATION_QUANTITY_MAX = 99999999.99;    // DECIMAL(10,2)

function donation_today() {
    return date('Y-m-d');
}

// The three tiles: cash and non-cash gifts received this calendar month, and every gift ever recorded.
function donations_tiles() {
    $first = date('Y-m-01');
    $last = date('Y-m-t');
    $con = connect();
    $stmt = $con->prepare("SELECT
        (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE donation_date BETWEEN ? AND ?) AS cash_month,
        (SELECT COUNT(*) FROM non_cash_donations WHERE date_received BETWEEN ? AND ?) AS noncash_month,
        (SELECT COALESCE(SUM(estimated_value), 0) FROM non_cash_donations WHERE date_received BETWEEN ? AND ?) AS noncash_value,
        (SELECT COUNT(*) FROM donations) + (SELECT COUNT(*) FROM non_cash_donations) AS recorded");
    $stmt->bind_param('ssssss', $first, $last, $first, $last, $first, $last);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    return ['cash_month' => (float) $row['cash_month'], 'noncash_month' => (int) $row['noncash_month'],
            'noncash_value' => (float) $row['noncash_value'], 'recorded' => (int) $row['recorded']];
}

// First date of a list range, or null for "all time".
function donations_range_start($range) {
    switch ($range) {
        case '30': return date('Y-m-d', strtotime('-30 days'));
        case '90': return date('Y-m-d', strtotime('-90 days'));
        case 'year': return date('Y-01-01');
    }
    return null;
}

// FROM clause, WHERE clause and bound values shared by the list and its count. $kind and $range are
// already validated.
function donations_list_sql($kind, $q, $range) {
    $from = "(SELECT 'cash' AS kind, d.id, d.donation_date AS gift_date, d.donorID AS donor_id, d.amount AS value,
                     NULL AS quantity, NULL AS unit, d.payment_method AS detail, d.eventID AS campaign_id, d.recorded_by
              FROM donations d
              UNION ALL
              SELECT 'noncash', n.id, n.date_received, n.donor_id, n.estimated_value,
                     n.quantity, n.unit, n.item_description, n.campaign_id, n.recorded_by
              FROM non_cash_donations n) g
             LEFT JOIN donor_summary s ON s.id = g.donor_id
             LEFT JOIN dbevents e ON e.id = g.campaign_id
             LEFT JOIN dbpersons p ON p.id = g.recorded_by";
    $where = ['1 = 1'];
    $types = '';
    $values = [];
    if ($kind !== 'all') { $where[] = $kind === 'cash' ? "g.kind = 'cash'" : "g.kind = 'noncash'"; }
    $start = donations_range_start($range);
    if ($start !== null) {
        $where[] = 'g.gift_date >= ?';
        $types .= 's';
        $values[] = $start;
    }
    if ($q !== '') {
        $like = '%' . donor_like_escape($q) . '%';
        $where[] = "(s.display_name LIKE ? ESCAPE '\\\\' OR e.name LIKE ? ESCAPE '\\\\')";
        $types .= 'ss';
        $values[] = $like;
        $values[] = $like;
    }
    return [$from, implode(' AND ', $where), $types, $values];
}

// One page of gifts in the shape donations.php renders, plus total and the effective filters.
// Unknown kind/range/sort values fall back to the defaults; the page is clamped.
function donations_list($kind, $q, $range, $sort, $page) {
    $kind = in_array($kind, DONATION_KINDS, true) ? $kind : 'all';
    $range = in_array($range, DONATION_RANGES, true) ? $range : '30';
    $sort = in_array($sort, DONATION_SORTS, true) ? $sort : 'newest';
    $orderBy = ['newest' => 'g.gift_date IS NULL, g.gift_date DESC, g.kind, g.id DESC',
                'oldest' => 'g.gift_date IS NULL, g.gift_date ASC, g.kind, g.id ASC',
                'amount' => 'g.value IS NULL, g.value DESC, g.gift_date DESC, g.kind, g.id DESC'][$sort];
    [$from, $where, $types, $values] = donations_list_sql($kind, $q, $range);
    $con = connect();

    $stmt = $con->prepare("SELECT COUNT(*) FROM $from WHERE $where");
    if ($types !== '') { $stmt->bind_param($types, ...$values); }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $pages = max(1, (int) ceil($total / DONATIONS_PER_PAGE));
    $page = min(max(1, (int) $page), $pages);
    $offset = ($page - 1) * DONATIONS_PER_PAGE;
    $limit = DONATIONS_PER_PAGE;

    $stmt = $con->prepare("SELECT g.kind, g.id, g.gift_date, g.donor_id, g.value, g.quantity, g.unit, g.detail,
                                  s.display_name, e.name AS campaign, p.first_name, p.last_name
                           FROM $from WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?");
    $allTypes = $types . 'ii';
    $allValues = array_merge($values, [$limit, $offset]);
    $stmt->bind_param($allTypes, ...$allValues);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = ['kind' => $r['kind'], 'id' => (int) $r['id'], 'date' => $r['gift_date'] ?? '',
                   'donor_id' => (int) $r['donor_id'], 'donor' => (string) $r['display_name'],
                   'value' => $r['value'] === null ? null : (float) $r['value'],
                   'quantity' => $r['quantity'] === null ? null : (float) $r['quantity'],
                   'unit' => (string) $r['unit'], 'detail' => (string) $r['detail'],
                   'campaign' => (string) $r['campaign'],
                   'recorded_by' => donation_recorder_name($r['first_name'], $r['last_name'])];
    }
    $stmt->close();
    mysqli_close($con);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'kind' => $kind,
            'range' => $range, 'sort' => $sort, 'per_page' => DONATIONS_PER_PAGE];
}

// "Maria L." from dbpersons first/last name; '' when unknown.
function donation_recorder_name($first, $last) {
    $first = trim((string) $first);
    $initial = mb_substr(trim((string) $last), 0, 1, 'UTF-8');
    return trim($first . ($initial !== '' ? ' ' . $initial . '.' : ''));
}

// Campaigns for the form selects and the progress card, keyed by id: every dbevents row that is not
// finished, plus $keepId (the campaign stored on a gift being edited). completed is nullable text holding
// '0'/'1' (seed, addEvent) or 'yes'/'no' (legacy archive helpers); '1' and 'yes' mean finished.
function donation_campaigns($keepId = null) {
    $keep = $keepId === null ? 0 : (int) $keepId;
    $con = connect();
    $stmt = $con->prepare("SELECT e.id, e.name, e.goalAmount,
                                  (SELECT COALESCE(SUM(d.amount), 0) FROM donations d WHERE d.eventID = e.id) AS raised,
                                  (SELECT COUNT(*) FROM non_cash_donations n WHERE n.campaign_id = e.id) AS gifts,
                                  (SELECT COALESCE(SUM(n.estimated_value), 0) FROM non_cash_donations n WHERE n.campaign_id = e.id) AS item_value
                           FROM dbevents e
                           WHERE NOT (COALESCE(e.completed, '') IN ('1', 'yes')) OR e.id = ?
                           ORDER BY e.name, e.id");
    $stmt->bind_param('i', $keep);
    $stmt->execute();
    $campaigns = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $campaigns[(int) $r['id']] = ['name' => (string) $r['name'], 'goal' => (float) $r['goalAmount'],
                                      'raised' => (float) $r['raised'], 'gifts' => (int) $r['gifts'],
                                      'item_value' => (float) $r['item_value']];
    }
    $stmt->close();
    mysqli_close($con);
    return $campaigns;
}

// One gift by kind ('cash' | 'noncash') and id, or null. Raw column values (NULLs kept) plus the campaign
// name, the recorder's name and, for non-cash, the quantity already distributed.
function donation_get($kind, $id) {
    if ($kind === 'cash') {
        $sql = "SELECT d.id, d.donorID AS donor_id, d.donation_date AS date_received, d.amount, d.fee, d.net_amount,
                       d.payment_method, d.platform, d.check_number, d.is_signed, d.deposit_date, d.in_honor_of,
                       d.honoree_name, d.eventID AS campaign_id, d.reason AS notes, d.send_receipt, d.receipt_sent_at,
                       d.thanked, d.recorded_by, e.name AS campaign, p.first_name, p.last_name
                FROM donations d
                LEFT JOIN dbevents e ON e.id = d.eventID
                LEFT JOIN dbpersons p ON p.id = d.recorded_by
                WHERE d.id = ?";
    } elseif ($kind === 'noncash') {
        $sql = "SELECT n.id, n.donor_id, n.date_received, n.item_description, n.category, n.quantity, n.unit,
                       n.estimated_value, n.item_condition, n.intended_use, n.campaign_id, n.notes, n.send_receipt,
                       n.receipt_sent_at, n.recorded_by, e.name AS campaign, p.first_name, p.last_name,
                       (SELECT COALESCE(SUM(x.quantity), 0) FROM non_cash_distributions x WHERE x.non_cash_donation_id = n.id) AS distributed
                FROM non_cash_donations n
                LEFT JOIN dbevents e ON e.id = n.campaign_id
                LEFT JOIN dbpersons p ON p.id = n.recorded_by
                WHERE n.id = ?";
    } else {
        return null;
    }
    $con = connect();
    $stmt = $con->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    if (!$row) { return null; }
    $row['kind'] = $kind;
    $row['donor_id'] = (int) $row['donor_id'];
    $row['recorder'] = donation_recorder_name($row['first_name'], $row['last_name']);
    return $row;
}

// ---------- form handling (addDonation.php) ----------

// The empty form for a new gift of $kind; date defaults to today, unit to items.
function donation_form_blank($kind) {
    $form = ['donor' => '', 'date_received' => donation_today(), 'campaign' => '', 'notes' => '', 'send_receipt' => 1];
    if ($kind === 'noncash') {
        return $form + ['item_description' => '', 'category' => '', 'quantity' => '', 'unit' => 'items',
                        'estimated_value' => '', 'condition' => '', 'intended_use' => ''];
    }
    return $form + ['amount' => '', 'fee' => '', 'pay_type' => '', 'platform' => '', 'check_number' => '',
                    'signed' => '', 'deposit_date' => '', 'in_honor' => 0, 'honoree_name' => ''];
}

// The form array for a stored gift (donation_get() row); NULL columns become ''.
function donation_form_from_row(array $row) {
    $s = function ($v) { return $v === null ? '' : (string) $v; };
    $form = ['donor' => (string) $row['donor_id'], 'date_received' => $s($row['date_received']),
             'campaign' => $s($row['campaign_id']), 'notes' => $s($row['notes']),
             'send_receipt' => (int) $row['send_receipt']];
    if ($row['kind'] === 'noncash') {
        return $form + ['item_description' => $s($row['item_description']), 'category' => $s($row['category']),
                        'quantity' => $s($row['quantity']), 'unit' => $s($row['unit']),
                        'estimated_value' => $s($row['estimated_value']), 'condition' => $s($row['item_condition']),
                        'intended_use' => $s($row['intended_use'])];
    }
    return $form + ['amount' => $s($row['amount']), 'fee' => $s($row['fee']), 'pay_type' => $s($row['payment_method']),
                    'platform' => $s($row['platform']), 'check_number' => $s($row['check_number']),
                    'signed' => $row['is_signed'] === null ? '' : ((int) $row['is_signed'] ? 'yes' : 'no'),
                    'deposit_date' => $s($row['deposit_date']), 'in_honor' => (int) $row['in_honor_of'],
                    'honoree_name' => $s($row['honoree_name'])];
}

// The form array from a POST; only strings are accepted, arrays become ''. (Same rule as fish_str();
// repeated here because the data layer must not depend on the page layout.)
function donation_form_from_post(array $post, $kind) {
    $str = function ($k) use ($post) { return isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : ''; };
    $form = ['donor' => $str('donor'), 'date_received' => $str('date_received'), 'campaign' => $str('campaign'),
             'notes' => $str('notes'), 'send_receipt' => isset($post['send_receipt']) ? 1 : 0];
    if ($kind === 'noncash') {
        return $form + ['item_description' => $str('item_description'), 'category' => $str('category'),
                        'quantity' => $str('quantity'), 'unit' => $str('unit'),
                        'estimated_value' => $str('estimated_value'), 'condition' => $str('condition'),
                        'intended_use' => $str('intended_use')];
    }
    return $form + ['amount' => $str('amount'), 'fee' => $str('fee'), 'pay_type' => $str('pay_type'),
                    'platform' => $str('platform'), 'check_number' => $str('check_number'), 'signed' => $str('signed'),
                    'deposit_date' => $str('deposit_date'), 'in_honor' => isset($post['in_honor']) ? 1 : 0,
                    'honoree_name' => $str('honoree_name')];
}

// A real Y-m-d date from 1900 on (the legacy `date` text parser, fish_parse_date in migration 002, only
// reads 1900-2100).
function donation_valid_date($s) {
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s && $s >= '1900-01-01' && $s <= '2100-12-31';
}

// Validates and normalises $form in place for $kind; returns the list of error messages. $stored is the
// donation_get() row when editing (null when adding). On edit, a value left exactly as stored passes
// unchanged, including NULL (legacy CASA rows have no payment method or platform, and some have no amount
// or date, or an amount of 0.00), and the stored donor is accepted even if anonymous or inactive; the
// stored donor id comes from the database, never from the form.
function donation_form_validate(array &$form, $kind, ?array $stored = null) {
    $errors = [];
    $unchanged = function ($field, $column) use (&$form, $stored) {
        return $stored !== null && $form[$field] === ($stored[$column] === null ? '' : (string) $stored[$column]);
    };
    $number = function ($field, $label, $min, $max, $required) use (&$form, &$errors) {
        $v = $form[$field];
        if ($v === '') {
            if ($required) { $errors[] = "$label is required."; }
            return;
        }
        if (!preg_match(DONATION_NUMBER_PATTERN, $v)) {
            $errors[] = "$label must be a number with at most two decimal places.";
        } elseif ((float) $v < $min) {
            $errors[] = $min > 0 ? "$label must be at least " . number_format($min, 2) . '.' : "$label cannot be negative.";
        } elseif ((float) $v > $max) {
            $errors[] = "$label is too large.";
        }
    };
    $length = function ($field, $label, $max) use (&$form, &$errors) {
        if (mb_strlen($form[$field], 'UTF-8') > $max) { $errors[] = "$label must be $max characters or fewer."; }
    };

    // Donor
    $storedDonor = $stored === null ? null : (int) $stored['donor_id'];
    if ($form['donor'] === '' || !ctype_digit($form['donor'])) {
        $errors[] = 'Choose a donor.';
    } elseif ((int) $form['donor'] !== $storedDonor) {
        $donor = donor_get((int) $form['donor']);
        if (!$donor || (int) $donor['is_active'] !== 1 || (int) $donor['is_anonymous'] === 1) {
            $errors[] = 'Choose an active donor from the list.';
        }
    }

    // Date received
    if ($unchanged('date_received', 'date_received')) {
        // kept as stored
    } elseif ($form['date_received'] === '') {
        $errors[] = 'Date received is required.';
    } elseif (!donation_valid_date($form['date_received'])) {
        $errors[] = 'Enter a valid date received.';
    } elseif ($form['date_received'] > donation_today()) {
        $errors[] = 'Date received cannot be in the future.';
    }

    // Campaign: one the form offers (open campaigns, plus the one stored on the gift being edited)
    $offered = $form['campaign'] === '' ? [] : donation_campaigns($stored === null ? null : $stored['campaign_id']);
    if ($form['campaign'] !== '' && (!ctype_digit($form['campaign']) || !isset($offered[(int) $form['campaign']]))) {
        $errors[] = 'Choose a campaign from the list.';
        $form['campaign'] = '';
    }
    $length('notes', 'Notes', 1000);

    if ($kind === 'noncash') {
        if ($form['item_description'] === '') { $errors[] = 'Item description is required.'; }
        $length('item_description', 'Item description', 255);
        if (!array_key_exists($form['category'], DONATION_CATEGORY_LABELS)) { $errors[] = 'Choose a category.'; }
        $number('quantity', 'Quantity', 0.01, DONATION_QUANTITY_MAX, true);
        if ($stored !== null && preg_match(DONATION_NUMBER_PATTERN, $form['quantity'])
            && (float) $form['quantity'] < (float) $stored['distributed']) {
            $errors[] = 'Quantity cannot be less than the ' . rtrim(rtrim(number_format((float) $stored['distributed'], 2), '0'), '.')
                      . ' already given out.';
        }
        if (!in_array($form['unit'], DONATION_UNITS, true)) { $errors[] = 'Choose a unit.'; }
        $number('estimated_value', 'Estimated value', 0, DONATION_MONEY_MAX, false);
        if ($form['condition'] !== '' && !array_key_exists($form['condition'], DONATION_CONDITION_LABELS)) {
            $errors[] = 'Choose a condition from the list.';
        }
        if ($form['intended_use'] === '') { $errors[] = 'Intended use / program is required.'; }
        $length('intended_use', 'Intended use', 255);
        return $errors;
    }

    // Cash
    if (!$unchanged('amount', 'amount')) { $number('amount', 'Amount', 0.01, DONATION_MONEY_MAX, true); }
    if (!$unchanged('fee', 'fee')) { $number('fee', 'Fee', 0, DONATION_MONEY_MAX, false); }
    // Fee against amount only when one of them changed and both are present.
    if (!($unchanged('fee', 'fee') && $unchanged('amount', 'amount'))
        && preg_match(DONATION_NUMBER_PATTERN, $form['fee']) && preg_match(DONATION_NUMBER_PATTERN, $form['amount'])
        && (float) $form['fee'] > (float) $form['amount']) {
        $errors[] = 'Fee cannot be more than the amount.';
    }
    if (!array_key_exists($form['pay_type'], DONATION_PAYMENT_LABELS) && !$unchanged('pay_type', 'payment_method')) {
        $errors[] = 'Choose a payment type.';
    }
    if (!array_key_exists($form['platform'], DONATION_PLATFORM_LABELS) && !$unchanged('platform', 'platform')) {
        $errors[] = 'Choose a platform.';
    }
    if ($form['pay_type'] === 'check') {
        $length('check_number', 'Check number', 32);
        if (!in_array($form['signed'], ['', 'yes', 'no'], true)) { $form['signed'] = ''; }
        if ($form['deposit_date'] !== '' && !donation_valid_date($form['deposit_date'])) {
            $errors[] = 'Enter a valid deposit date.';
        }
    } else {
        $form['check_number'] = $form['signed'] = $form['deposit_date'] = '';
    }
    if ($form['in_honor']) {
        if ($form['honoree_name'] === '') { $errors[] = 'Enter who the gift is in honor of.'; }
        $length('honoree_name', 'Honoree name', 255);
    } else {
        $form['honoree_name'] = '';
    }
    return $errors;
}

// ---------- writes ----------

function donation_bind_values($kind, array $form) {
    $nullable = function ($v) { return $v === '' ? null : $v; };
    $common = [(int) $form['donor'], $nullable($form['date_received']),
               $form['campaign'] === '' ? null : (int) $form['campaign'], $nullable($form['notes']),
               (int) $form['send_receipt']];
    if ($kind === 'noncash') {
        return array_merge($common, [$form['item_description'], $form['category'], $form['quantity'], $form['unit'],
                                     $nullable($form['estimated_value']), $nullable($form['condition']), $form['intended_use']]);
    }
    return array_merge($common, [$nullable($form['amount']), $nullable($form['fee']), $nullable($form['pay_type']),
                                 $nullable($form['platform']), $nullable($form['check_number']),
                                 $form['signed'] === '' ? null : (int) ($form['signed'] === 'yes'),
                                 $nullable($form['deposit_date']), (int) $form['in_honor'], $nullable($form['honoree_name'])]);
}

const DONATION_CASH_COLUMNS = 'donorID = ?, donation_date = ?, eventID = ?, reason = ?, send_receipt = ?, amount = ?, fee = ?,
                               payment_method = ?, platform = ?, check_number = ?, is_signed = ?, deposit_date = ?,
                               in_honor_of = ?, honoree_name = ?';
const DONATION_CASH_TYPES = 'isisisssssisis';
const DONATION_NONCASH_COLUMNS = 'donor_id = ?, date_received = ?, campaign_id = ?, notes = ?, send_receipt = ?,
                                  item_description = ?, category = ?, quantity = ?, unit = ?, estimated_value = ?,
                                  item_condition = ?, intended_use = ?';
const DONATION_NONCASH_TYPES = 'isisisssssss';

// Inserts a gift from the validated form; returns the new id or false. recorded_by is the signed-in user.
function donation_create($kind, array $form, $byUser) {
    try {
        $con = connect();
        if ($kind === 'noncash') {
            $stmt = $con->prepare('INSERT INTO non_cash_donations SET ' . DONATION_NONCASH_COLUMNS . ', recorded_by = ?');
            $types = DONATION_NONCASH_TYPES . 's';
        } else {
            $stmt = $con->prepare('INSERT INTO donations SET ' . DONATION_CASH_COLUMNS . ', thanked = 0, recorded_by = ?');
            $types = DONATION_CASH_TYPES . 's';
        }
        $values = donation_bind_values($kind, $form);
        $values[] = $byUser;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int) $con->insert_id;
        $stmt->close();
        mysqli_close($con);
        return $id > 0 ? $id : false;
    } catch (mysqli_sql_exception $e) {
        error_log('donation_create failed: ' . $e->getMessage());
        return false;
    }
}

// Updates the form's columns of one gift; recorded_by, created_at, receipt_sent_at and thanked are never
// touched. Returns true on success.
function donation_update($kind, $id, array $form) {
    try {
        $con = connect();
        if ($kind === 'noncash') {
            $stmt = $con->prepare('UPDATE non_cash_donations SET ' . DONATION_NONCASH_COLUMNS . ' WHERE id = ?');
            $types = DONATION_NONCASH_TYPES . 'i';
        } else {
            $stmt = $con->prepare('UPDATE donations SET ' . DONATION_CASH_COLUMNS . ' WHERE id = ?');
            $types = DONATION_CASH_TYPES . 'i';
        }
        $values = donation_bind_values($kind, $form);
        $values[] = (int) $id;
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $ok = $stmt->errno === 0;
        $stmt->close();
        mysqli_close($con);
        return $ok;
    } catch (mysqli_sql_exception $e) {
        error_log('donation_update failed: ' . $e->getMessage());
        return false;
    }
}
