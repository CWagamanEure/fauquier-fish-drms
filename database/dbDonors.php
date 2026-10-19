<?php
// Donor data layer for the FISH NET donor pages. Every statement is prepared; reads go through the
// donor_summary view (migration 007/008), writes go to donors. The anonymous donor (id 999) is never
// written. Write functions return false (and log) on a database error.
require_once __DIR__ . '/dbinfo.php';

const DONORS_PER_PAGE = 8;
const DONOR_ANONYMOUS_ID = 999;
const DONOR_FILTERS = ['all', 'individuals', 'organizations', 'lapsed'];
const DONOR_SORTS = ['last_gift', 'name', 'lifetime'];
const DONOR_PREFERRED_LABELS = ['email' => 'Email', 'phone' => 'Phone', 'mail' => 'Mail', 'text' => 'Text'];
const DONOR_STATES = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
// Column lengths from SHOW CREATE TABLE donors; strict-mode MariaDB rejects anything longer.
const DONOR_FIELD_LIMITS = ['first' => 100, 'middle' => 100, 'last' => 100, 'org_name' => 255, 'email' => 100,
                            'phone' => 30, 'street' => 100, 'city' => 30, 'state' => 30, 'zip' => 30];

function donor_pref_to_enum($label) {
    $enum = array_search($label, DONOR_PREFERRED_LABELS, true);
    return $enum === false ? null : $enum;
}

function donor_pref_label($enum) {
    return DONOR_PREFERRED_LABELS[$enum] ?? '';
}

function donor_phone_digits($phone) {
    return preg_replace('/\D/', '', (string) $phone);
}

function donor_like_escape($s) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// ---------- reads ----------

// The four tiles on donor.php, all over the same set: active, non-anonymous donors.
function donors_tiles() {
    $con = connect();
    $stmt = $con->prepare("SELECT COUNT(*) AS total,
                                  SUM(donor_since_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_month,
                                  SUM(status = 'lapsed') AS lapsed
                           FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // Duplicates: donors sharing a non-empty email (case-insensitive) or the same non-empty phone digits
    // with another donor in the same set; counted as donors involved, not match groups.
    $anon = DONOR_ANONYMOUS_ID;
    $stmt = $con->prepare("SELECT COUNT(*) FROM donors d
                           WHERE d.is_active = 1 AND d.id <> ? AND (
                             (COALESCE(d.email, '') <> '' AND EXISTS (
                                SELECT 1 FROM donors o WHERE o.id <> d.id AND o.is_active = 1 AND o.id <> ?
                                  AND LOWER(o.email) = LOWER(d.email)))
                             OR (REGEXP_REPLACE(COALESCE(d.phone, ''), '[^0-9]', '') <> '' AND EXISTS (
                                SELECT 1 FROM donors o WHERE o.id <> d.id AND o.is_active = 1 AND o.id <> ?
                                  AND REGEXP_REPLACE(COALESCE(o.phone, ''), '[^0-9]', '') = REGEXP_REPLACE(COALESCE(d.phone, ''), '[^0-9]', ''))))");
    $stmt->bind_param('iii', $anon, $anon, $anon);
    $stmt->execute();
    $dupes = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    mysqli_close($con);
    return ['total' => (int) $row['total'], 'new_month' => (int) $row['new_month'],
            'lapsed' => (int) $row['lapsed'], 'dupes' => $dupes];
}

// Builds the WHERE clause and bound values shared by the list and its count.
function donors_list_where($filter, $q) {
    $where = ['is_anonymous = 0', 'is_active = 1'];
    $types = '';
    $values = [];
    if ($filter === 'individuals') { $where[] = "donor_type = 'individual'"; }
    if ($filter === 'organizations') { $where[] = "donor_type = 'organization'"; }
    if ($filter === 'lapsed') { $where[] = "status = 'lapsed'"; }
    if ($q !== '') {
        $like = '%' . donor_like_escape($q) . '%';
        $clauses = ["display_name LIKE ? ESCAPE '\\\\'", "email LIKE ? ESCAPE '\\\\'"];
        $types .= 'ss';
        $values[] = $like;
        $values[] = $like;
        $digits = donor_phone_digits($q);
        if (strlen($digits) >= 3) {   // a name search must not match every phone holder
            $clauses[] = "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') LIKE ?";
            $types .= 's';
            $values[] = '%' . $digits . '%';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
    return [implode(' AND ', $where), $types, $values];
}

// Donor list in the shape donor.php renders: id, name, type, contact, lifetime, last_gift, status,
// plus total and the effective page. Unknown filter/sort values fall back to the defaults.
function donors_list($filter, $q, $sort, $page) {
    $filter = in_array($filter, DONOR_FILTERS, true) ? $filter : 'all';
    $sort = in_array($sort, DONOR_SORTS, true) ? $sort : 'last_gift';
    $orderBy = ['last_gift' => 'last_gift_date IS NULL, last_gift_date DESC, display_name, id',
                'name' => 'display_name, id',
                'lifetime' => 'lifetime_cash DESC, display_name, id'][$sort];
    [$where, $types, $values] = donors_list_where($filter, $q);
    $con = connect();

    $stmt = $con->prepare("SELECT COUNT(*) FROM donor_summary WHERE $where");
    if ($types !== '') { $stmt->bind_param($types, ...$values); }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $pages = max(1, (int) ceil($total / DONORS_PER_PAGE));
    $page = min(max(1, (int) $page), $pages);
    $offset = ($page - 1) * DONORS_PER_PAGE;
    $limit = DONORS_PER_PAGE;

    $stmt = $con->prepare("SELECT id, display_name, donor_type, email, phone, lifetime_cash, last_gift_date, status
                           FROM donor_summary WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?");
    $allTypes = $types . 'ii';
    $allValues = array_merge($values, [$limit, $offset]);
    $stmt->bind_param($allTypes, ...$allValues);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = ['id' => (int) $r['id'], 'name' => $r['display_name'],
                   'type' => $r['donor_type'] === 'organization' ? 'Organization' : 'Individual',
                   'contact' => $r['email'] !== null && $r['email'] !== '' ? $r['email'] : (string) $r['phone'],
                   'lifetime' => (float) $r['lifetime_cash'], 'last_gift' => $r['last_gift_date'] ?? '',
                   'status' => $r['status']];
    }
    $stmt->close();
    mysqli_close($con);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'filter' => $filter, 'sort' => $sort, 'per_page' => DONORS_PER_PAGE];
}

// The donor-picker shape used by addDonation.php (UF2-49): name, type and town plus the side-panel
// figures. Email and phone are matched by donors_lookup() but never returned.
function donor_lookup_row(array $r) {
    $town = implode(', ', array_filter([(string) $r['city'], (string) $r['state']], 'strlen'));
    return ['id' => (int) $r['id'], 'name' => $r['display_name'],
            'type' => $r['donor_type'] === 'organization' ? 'Organization' : 'Individual',
            'town' => $town, 'since' => $r['donor_since_date'] === null ? '' : substr($r['donor_since_date'], 0, 4),
            'cash' => (float) $r['lifetime_cash'], 'items' => (int) $r['items_donated'],
            'last_gift' => $r['last_gift_date'] ?? '', 'hours' => (float) $r['volunteer_hours']];
}

// Active, non-anonymous donors matching $q by name, email or phone (same rules as the donor list),
// at most $limit, in donor_lookup_row() shape.
function donors_lookup($q, $limit = 10) {
    [$where, $types, $values] = donors_list_where('all', $q);
    $con = connect();
    $stmt = $con->prepare("SELECT id, display_name, donor_type, city, state, donor_since_date, lifetime_cash,
                                  items_donated, last_gift_date, volunteer_hours
                           FROM donor_summary WHERE $where ORDER BY display_name, id LIMIT ?");
    $types .= 'i';
    $values[] = (int) $limit;
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $rows = array_map('donor_lookup_row', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    mysqli_close($con);
    return $rows;
}

// One donor_summary row (active or not), or null.
function donor_get($id) {
    $con = connect();
    $stmt = $con->prepare('SELECT * FROM donor_summary WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    return $row ?: null;
}

// The form-shaped profile addDonor.php and updateDonor.php use, or null.
function donor_profile_fields($id) {
    $con = connect();
    $stmt = $con->prepare('SELECT id, donor_type, `first`, middle_name, `last`, org_name, email, phone, preferred_contact,
                                  street, city, state, zip, is_volunteer, email_updates, mail_receipts, do_not_contact,
                                  notes, created_at, is_active FROM donors WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    if (!$r) { return null; }
    return ['id' => (int) $r['id'],
            'type' => $r['donor_type'] === 'organization' ? 'Organization' : 'Individual',
            'first' => (string) $r['first'], 'middle' => (string) $r['middle_name'], 'last' => (string) $r['last'],
            'org_name' => (string) $r['org_name'], 'email' => (string) $r['email'], 'phone' => (string) $r['phone'],
            'preferred' => $r['preferred_contact'] === null ? '' : donor_pref_label($r['preferred_contact']),
            'street' => (string) $r['street'], 'city' => (string) $r['city'], 'state' => (string) $r['state'],
            'zip' => (string) $r['zip'],
            'volunteer' => (int) $r['is_volunteer'], 'email_updates' => (int) $r['email_updates'],
            'mail_receipts' => (int) $r['mail_receipts'], 'do_not_contact' => (int) $r['do_not_contact'],
            'notes' => (string) $r['notes'], 'code' => sprintf('D-%05d', (int) $r['id']),
            'created' => $r['created_at'], 'is_active' => (int) $r['is_active']];
}

function donor_history_rows($sql, $id) {
    $con = connect();
    $stmt = $con->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return $rows;
}

function donor_cash_history($id) {
    return donor_history_rows('SELECT d.id, d.donation_date, COALESCE(d.amount, 0) AS amount, d.payment_method, d.check_number,
                                      d.receipt_sent_at, d.send_receipt, d.thanked, e.name AS campaign
                               FROM donations d LEFT JOIN dbevents e ON e.id = d.eventID
                               WHERE d.donorID = ? ORDER BY d.donation_date IS NULL, d.donation_date DESC, d.id DESC', $id);
}

function donor_noncash_history($id) {
    return donor_history_rows('SELECT n.id, n.date_received, n.quantity, n.unit, n.item_description, n.estimated_value,
                                      n.receipt_sent_at, n.send_receipt, e.name AS campaign
                               FROM non_cash_donations n LEFT JOIN dbevents e ON e.id = n.campaign_id
                               WHERE n.donor_id = ? ORDER BY n.date_received DESC, n.id DESC', $id);
}

function donor_hours_history($id) {
    return donor_history_rows('SELECT v.id, v.activity_date, v.hours, v.job, e.name AS campaign
                               FROM volunteer_hours v LEFT JOIN dbevents e ON e.id = v.campaign_id
                               WHERE v.donor_id = ? ORDER BY v.activity_date DESC, v.id DESC', $id);
}

function donor_communications($id) {
    return donor_history_rows('SELECT c.id, c.occurred_at, c.comm_type, c.subject, c.status, e.name AS campaign
                               FROM communications c LEFT JOIN dbevents e ON e.id = c.campaign_id
                               WHERE c.donor_id = ? ORDER BY c.occurred_at DESC, c.id DESC', $id);
}

// ---------- form handling shared by addDonor.php and updateDonor.php ----------

// The form array from a POST; only strings are accepted, arrays become ''. (Same rule as fish_str() in
// include/fishnet-layout.php; repeated here because the data layer must not depend on the page layout.)
function donor_form_from_post(array $post, array $base) {
    $str = function ($k) use ($post) { return isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : ''; };
    $form = array_merge($base, [
        'type' => $str('type'), 'first' => $str('first'), 'middle' => $str('middle'), 'last' => $str('last'),
        'org_name' => $str('org_name'), 'email' => $str('email'), 'phone' => $str('phone'),
        'preferred' => $str('preferred'), 'street' => $str('street'), 'city' => $str('city'),
        'state' => $str('state'), 'zip' => $str('zip'),
        'volunteer' => isset($post['volunteer']) ? 1 : 0, 'email_updates' => isset($post['email_updates']) ? 1 : 0,
        'mail_receipts' => isset($post['mail_receipts']) ? 1 : 0, 'do_not_contact' => isset($post['do_not_contact']) ? 1 : 0,
    ]);
    if (!in_array($form['type'], ['Individual', 'Organization'], true)) { $form['type'] = 'Individual'; }
    return $form;
}

// Validates and normalises $form in place; returns the list of error messages. A posted value that is
// byte-identical to the stored one ($stored, for edits) is accepted as is, so legacy data that would not
// pass today's rules survives an edit untouched; an empty value clears the field; a changed value must
// pass the normal rules and the column lengths.
function donor_form_validate(array &$form, ?array $stored = null) {
    $errors = [];
    $unchanged = function ($k) use ($form, $stored) { return $stored !== null && array_key_exists($k, $stored) && $form[$k] === (string) $stored[$k]; };
    if ($form['type'] === 'Individual') {
        if ($form['first'] === '') { $errors[] = 'First name is required.'; }
        if ($form['last'] === '') { $errors[] = 'Last name is required.'; }
    } elseif ($form['org_name'] === '') {
        $errors[] = 'Organization name is required.';
    }
    if ($form['email'] !== '' && !$unchanged('email') && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($form['phone'] !== '' && !$unchanged('phone')) {
        $digits = donor_phone_digits($form['phone']);
        if (strlen($digits) === 11 && $digits[0] === '1') { $digits = substr($digits, 1); }
        if (strlen($digits) !== 10) {
            $errors[] = 'Phone number must have 10 digits.';
        } else {
            $form['phone'] = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }
    }
    if ($form['zip'] !== '' && !$unchanged('zip') && !preg_match('/^\d{5}(-\d{4})?$/', $form['zip'])) {
        $errors[] = 'ZIP code must be 5 digits.';
    }
    if ($form['state'] !== '' && !$unchanged('state') && !in_array($form['state'], DONOR_STATES, true)) {
        $errors[] = 'Choose a valid state.';
    }
    if ($form['preferred'] !== '' && !$unchanged('preferred') && donor_pref_to_enum($form['preferred']) === null) {
        $form['preferred'] = '';
    }
    foreach (DONOR_FIELD_LIMITS as $field => $limit) {
        if (!$unchanged($field) && mb_strlen($form[$field], 'UTF-8') > $limit) {
            $label = ['org_name' => 'Organization name', 'first' => 'First name', 'last' => 'Last name', 'middle' => 'Middle name'][$field] ?? ucfirst($field);
            $errors[] = "$label must be $limit characters or fewer.";
        }
    }
    return $errors;
}

// ---------- writes ----------

function donor_bind_values(array $form) {
    $nullable = function ($v) { return $v === '' ? null : $v; };
    return [
        $form['type'] === 'Organization' ? 'organization' : 'individual',
        $nullable($form['first']), $nullable($form['middle']), $nullable($form['last']), $nullable($form['org_name']),
        $nullable($form['email']), $nullable($form['phone']),
        $form['preferred'] === '' ? null : donor_pref_to_enum($form['preferred']),
        $nullable($form['street']), $nullable($form['city']), $nullable($form['state']), $nullable($form['zip']),
        (int) $form['volunteer'], (int) $form['email_updates'], (int) $form['mail_receipts'], (int) $form['do_not_contact'],
    ];
}

// Inserts a donor from the validated form; returns the new id or false. donor_since and created_at are
// left to their column defaults so the donor reads as "new" from today.
function donor_create(array $form, $byUser) {
    try {
        $con = connect();
        $stmt = $con->prepare('INSERT INTO donors (donor_type, `first`, middle_name, `last`, org_name, email, phone, preferred_contact,
                                                   street, city, state, zip, is_volunteer, email_updates, mail_receipts, do_not_contact, updated_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $values = donor_bind_values($form);
        $values[] = $byUser;
        $stmt->bind_param('ssssssssssssiiiis', ...$values);
        $stmt->execute();
        $id = (int) $con->insert_id;
        $stmt->close();
        mysqli_close($con);
        return $id > 0 ? $id : false;
    } catch (mysqli_sql_exception $e) {
        error_log('donor_create failed: ' . $e->getMessage());
        return false;
    }
}

// Updates only the columns the form carries; org_contact, notes, gender, donor_since and created_at are
// never touched. Refuses the anonymous donor. Returns true on success.
function donor_update($id, array $form, $byUser) {
    if ((int) $id === DONOR_ANONYMOUS_ID) { return false; }
    try {
        $con = connect();
        $stmt = $con->prepare('UPDATE donors SET donor_type = ?, `first` = ?, middle_name = ?, `last` = ?, org_name = ?, email = ?, phone = ?,
                                                 preferred_contact = ?, street = ?, city = ?, state = ?, zip = ?, is_volunteer = ?, email_updates = ?,
                                                 mail_receipts = ?, do_not_contact = ?, updated_by = ?
                               WHERE id = ? AND id <> ' . DONOR_ANONYMOUS_ID);
        $values = donor_bind_values($form);
        $values[] = $byUser;
        $values[] = (int) $id;
        $stmt->bind_param('ssssssssssssiiiisi', ...$values);
        $stmt->execute();
        $ok = $stmt->errno === 0;
        $stmt->close();
        mysqli_close($con);
        return $ok;
    } catch (mysqli_sql_exception $e) {
        error_log('donor_update failed: ' . $e->getMessage());
        return false;
    }
}

// Soft delete and restore. Refuses the anonymous donor. Returns true on success.
function donor_set_active($id, $active, $byUser) {
    if ((int) $id === DONOR_ANONYMOUS_ID) { return false; }
    try {
        $con = connect();
        if ($active) {
            $stmt = $con->prepare('UPDATE donors SET is_active = 1, deactivated_at = NULL, deactivated_by = NULL, updated_by = ?
                                   WHERE id = ? AND id <> ' . DONOR_ANONYMOUS_ID);
            $stmt->bind_param('si', $byUser, $id);
        } else {
            $stmt = $con->prepare('UPDATE donors SET is_active = 0, deactivated_at = NOW(), deactivated_by = ?, updated_by = ?
                                   WHERE id = ? AND id <> ' . DONOR_ANONYMOUS_ID);
            $stmt->bind_param('ssi', $byUser, $byUser, $id);
        }
        $stmt->execute();
        $ok = $stmt->errno === 0;
        $stmt->close();
        mysqli_close($con);
        return $ok;
    } catch (mysqli_sql_exception $e) {
        error_log('donor_set_active failed: ' . $e->getMessage());
        return false;
    }
}
