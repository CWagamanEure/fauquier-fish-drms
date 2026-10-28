<?php
// Campaign data layer for the FISH NET campaign pages. dbevents doubles as the campaigns table (migration 009).
// Reads come from the campaign_summary view; gifts, volunteer hours and communications come from their own
// tables through eventID / campaign_id. Every statement is prepared. Reads throw mysqli_sql_exception (pages
// catch it and show a generic notice); writes log the error and return false. The old dbEvents.php is NOT used.
require_once __DIR__ . '/dbinfo.php';

const CAMPAIGN_TYPES = ['cash' => 'Cash', 'items' => 'Items', 'both' => 'Cash + items', 'volunteers' => 'Volunteers'];
const CAMPAIGN_STATUS_LABELS = ['active' => 'Active', 'upcoming' => 'Upcoming', 'completed' => 'Completed'];
const CAMPAIGN_UNITS = ['items', 'cans', 'lbs', 'bags', 'boxes', 'coats', 'kits', 'meals'];
const CAMPAIGN_KINDS = ['all', 'cash', 'noncash'];
const CAMPAIGN_GIFTS_PER_PAGE = 8;
const CAMPAIGN_TAB_LIMIT = 50;          // rows shown on the Volunteers and Communications tabs
const CAMPAIGN_ENDING_DAYS = 14;        // the "ending soon" tile
const CAMPAIGN_ANONYMOUS_DONOR_ID = 999;
const CAMPAIGN_FIELD_LIMITS = ['name' => 255, 'description' => 2000, 'fund' => 100, 'location' => 255];

// Display name for a donors row aliased dn (same rule as donor_summary; the anonymous donor reads "Anonymous").
const CAMPAIGN_DONOR_NAME_SQL = "CASE WHEN dn.id = 999 THEN 'Anonymous'
        WHEN dn.donor_type = 'organization' AND COALESCE(dn.org_name, '') <> '' THEN dn.org_name
        ELSE TRIM(CONCAT_WS(' ', dn.`first`, dn.`last`)) END";

// ---------------------------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------------------------

// Run one prepared SELECT and return every row as an associative array.
function campaign_rows($sql, $types = '', array $values = []) {
    $con = connect();
    $stmt = $con->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return $rows;
}

// 1200 -> "1,200", 12.5 -> "12.50".
function campaign_num($n) {
    $n = (float) $n;
    return number_format($n, floor($n) == $n ? 0 : 2);
}

// Number for an input box: 12000.00 -> "12000", 12.5 -> "12.5", NULL -> "".
function campaign_plain_num($v) {
    if ($v === null || $v === '') { return ''; }
    return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
}

// "Charity Coleman" -> "Charity C." for the list; blank owner -> an em dash.
function campaign_owner_short($name) {
    $name = trim((string) $name);
    if ($name === '') { return '—'; }
    $parts = preg_split('/\s+/', $name);
    if (count($parts) < 2) { return $name; }
    return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
}

// Percent and label for the main progress bar: cash for Cash and Cash + items, items for Items, shifts for Volunteers.
function campaign_progress(array $c) {
    switch ($c['campaign_type']) {
        case 'items':
            $have = (float) $c['items_collected'];
            $goal = (float) ($c['item_goal'] ?? 0);
            $label = campaign_num($have) . ($goal > 0 ? ' of ' . campaign_num($goal) : '') . ' ' . $c['item_unit'];
            break;
        case 'volunteers':
            $have = (float) $c['shifts'];
            $goal = (float) ($c['volunteer_goal'] ?? 0);
            $label = campaign_num($have) . ($goal > 0 ? ' of ' . campaign_num($goal) : '') . ' shifts';
            break;
        default:
            $have = (float) $c['cash_raised'];
            $goal = (float) ($c['cash_goal'] ?? 0);
            $label = '$' . campaign_num($have) . ($goal > 0 ? ' of $' . campaign_num($goal) : ' raised');
    }
    return ['pct' => $goal > 0 ? (int) round($have / $goal * 100) : 0, 'label' => $label];
}

// ---------------------------------------------------------------------------------------------
// Campaign list page
// ---------------------------------------------------------------------------------------------

// Tiles above the list. "This year" is the calendar year; only gifts linked to a campaign count.
function campaigns_tiles() {
    $rows = campaign_rows(
        "SELECT
            (SELECT COUNT(*) FROM campaign_summary WHERE status = 'active') AS active,
            (SELECT COUNT(*) FROM campaign_summary WHERE status = 'active' AND end_date IS NOT NULL
                AND end_date <= CURDATE() + INTERVAL ? DAY) AS ending_soon,
            (SELECT COALESCE(SUM(amount), 0) FROM donations
                WHERE eventID IS NOT NULL AND YEAR(donation_date) = YEAR(CURDATE())) AS raised_year,
            (SELECT COALESCE(SUM(quantity), 0) FROM non_cash_donations
                WHERE campaign_id IS NOT NULL AND YEAR(date_received) = YEAR(CURDATE())) AS items_year",
        'i', [CAMPAIGN_ENDING_DAYS]);
    $r = $rows[0];
    return ['active' => (int) $r['active'], 'ending_soon' => (int) $r['ending_soon'],
            'raised_year' => (float) $r['raised_year'], 'items_year' => (float) $r['items_year']];
}

// Every campaign, active first, shaped for the list rows.
function campaigns_list() {
    $out = [];
    $sql = "SELECT * FROM campaign_summary
            ORDER BY FIELD(status, 'active', 'upcoming', 'completed'), start_date IS NULL, start_date DESC, id DESC";
    foreach (campaign_rows($sql) as $r) {
        $p = campaign_progress($r);
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'type' => $r['campaign_type'],
                  'start' => (string) ($r['start_date'] ?? ''), 'end' => (string) ($r['end_date'] ?? ''),
                  'pct' => $p['pct'], 'label' => $p['label'],
                  'owner' => campaign_owner_short($r['owner_name']), 'status' => $r['status']];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Campaign detail page
// ---------------------------------------------------------------------------------------------

// One campaign with its totals, or null when the id does not exist.
function campaign_get($id) {
    $rows = campaign_rows('SELECT * FROM campaign_summary WHERE id = ?', 'i', [(int) $id]);
    if (!$rows) { return null; }
    $r = $rows[0];
    foreach (['id', 'cash_gift_count', 'item_gift_count', 'shifts', 'show_on_dashboard', 'email_past_donors', 'ask_volunteers', 'is_completed'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    foreach (['cash_raised', 'items_collected', 'volunteer_hours'] as $k) {
        $r[$k] = (float) $r[$k];
    }
    return $r;
}

// Distinct donors with a gift linked to this campaign (the anonymous donor is not counted) and how many of them
// were first-time donors, meaning their earliest gift anywhere belongs to this campaign.
function campaign_donor_counts($id) {
    $id = (int) $id;
    $rows = campaign_rows(
        "SELECT COUNT(*) AS donors, COALESCE(SUM(t.first_in_campaign <= ds.first_gift_date), 0) AS first_time
         FROM (
             SELECT donor_id, MIN(gift_date) AS first_in_campaign FROM (
                 SELECT donorID AS donor_id, donation_date AS gift_date FROM donations WHERE eventID = ?
                 UNION ALL
                 SELECT donor_id, date_received FROM non_cash_donations WHERE campaign_id = ?
             ) g
             WHERE donor_id IS NOT NULL AND donor_id <> " . CAMPAIGN_ANONYMOUS_DONOR_ID . "
             GROUP BY donor_id
         ) t
         JOIN donor_summary ds ON ds.id = t.donor_id",
        'ii', [$id, $id]);
    return ['donors' => (int) $rows[0]['donors'], 'first_time' => (int) $rows[0]['first_time']];
}

// One page of this campaign's gifts (cash and non-cash together), newest first. $kind: all | cash | noncash.
function campaign_gifts(array $c, $kind, $page) {
    $kind = in_array($kind, CAMPAIGN_KINDS, true) ? $kind : 'all';
    $total = $kind === 'cash' ? $c['cash_gift_count']
           : ($kind === 'noncash' ? $c['item_gift_count'] : $c['cash_gift_count'] + $c['item_gift_count']);
    $pages = max(1, (int) ceil($total / CAMPAIGN_GIFTS_PER_PAGE));
    $page = min(max(1, (int) $page), $pages);
    $offset = ($page - 1) * CAMPAIGN_GIFTS_PER_PAGE;
    $id = (int) $c['id'];
    $limit = CAMPAIGN_GIFTS_PER_PAGE;

    $rows = campaign_rows(
        "SELECT * FROM (
            SELECT 'cash' AS kind, dn.id AS donor_id, " . CAMPAIGN_DONOR_NAME_SQL . " AS donor_name,
                   d.donation_date AS gift_date, d.id AS gift_id,
                   d.amount AS amount, NULL AS quantity, NULL AS unit, NULL AS item
            FROM donations d LEFT JOIN donors dn ON dn.id = d.donorID
            WHERE d.eventID = ?
            UNION ALL
            SELECT 'noncash', dn.id, " . CAMPAIGN_DONOR_NAME_SQL . ",
                   n.date_received, n.id, NULL, n.quantity, n.unit, n.item_description
            FROM non_cash_donations n LEFT JOIN donors dn ON dn.id = n.donor_id
            WHERE n.campaign_id = ?
         ) g
         WHERE (? = 'all' OR g.kind = ?)
         ORDER BY g.gift_date IS NULL, g.gift_date DESC, g.gift_id DESC
         LIMIT ? OFFSET ?",
        'iissii', [$id, $id, $kind, $kind, $limit, $offset]);

    $out = [];
    foreach ($rows as $r) {
        $isCash = $r['kind'] === 'cash';
        $out[] = ['kind' => $r['kind'], 'date' => $r['gift_date'], 'donor_id' => (int) $r['donor_id'],
                  'donor' => (string) $r['donor_name'],
                  'detail' => $isCash ? '$' . number_format((float) $r['amount'], 2)
                                      : campaign_num($r['quantity']) . ' ' . $r['unit'] . ' · ' . $r['item']];
    }
    return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'kind' => $kind];
}

// Latest volunteer shifts linked to this campaign.
function campaign_volunteers($id) {
    return campaign_rows(
        'SELECT v.activity_date, v.hours, v.job, ' . CAMPAIGN_DONOR_NAME_SQL . ' AS volunteer_name, dn.id AS donor_id
         FROM volunteer_hours v LEFT JOIN donors dn ON dn.id = v.donor_id
         WHERE v.campaign_id = ?
         ORDER BY v.activity_date DESC, v.id DESC LIMIT ' . CAMPAIGN_TAB_LIMIT,
        'i', [(int) $id]);
}

// Latest communications linked to this campaign, and the total number (for the tab count).
function campaign_communications($id) {
    return campaign_rows(
        'SELECT c.occurred_at, c.comm_type, c.subject, c.status, ' . CAMPAIGN_DONOR_NAME_SQL . ' AS donor_name, dn.id AS donor_id
         FROM communications c LEFT JOIN donors dn ON dn.id = c.donor_id
         WHERE c.campaign_id = ?
         ORDER BY c.occurred_at DESC, c.id DESC LIMIT ' . CAMPAIGN_TAB_LIMIT,
        'i', [(int) $id]);
}

function campaign_comm_count($id) {
    $rows = campaign_rows('SELECT COUNT(*) AS n FROM communications WHERE campaign_id = ?', 'i', [(int) $id]);
    return (int) $rows[0]['n'];
}

// Cash per week for the weekly chart: the last four weeks counted from the campaign's start date, ending with
// the current week (labelled "This wk" while the campaign is active). Returns [[label, amount], ...].
function campaign_weekly_cash(array $c) {
    if (!in_array($c['campaign_type'], ['cash', 'both'], true) || empty($c['start_date'])) { return []; }
    $start = new DateTime($c['start_date']);
    $today = new DateTime('today');
    $ref = (!empty($c['end_date']) && new DateTime($c['end_date']) < $today) ? new DateTime($c['end_date']) : $today;
    if ($ref < $start) { return []; }          // upcoming: nothing to chart yet
    $pivot = intdiv((int) $start->diff($ref)->days, 7) + 1;
    $first = max(1, $pivot - 3);

    $rows = campaign_rows(
        'SELECT FLOOR(DATEDIFF(donation_date, ?) / 7) + 1 AS wk, SUM(amount) AS total
         FROM donations WHERE eventID = ? AND donation_date >= ? GROUP BY wk',
        'sis', [$c['start_date'], (int) $c['id'], $c['start_date']]);
    $byWeek = [];
    foreach ($rows as $r) { $byWeek[(int) $r['wk']] = (float) $r['total']; }

    $out = [];
    for ($w = $first; $w <= $pivot; $w++) {
        $label = ($w === $pivot && $c['status'] === 'active') ? 'This wk' : 'Wk ' . $w;
        $out[] = [$label, $byWeek[$w] ?? 0.0];
    }
    return $out;
}

// Top donors for the side panel: by cash for Cash and Cash + items campaigns, by quantity for Items campaigns.
// The anonymous donor is skipped. Returns [[donor_id, name, "display text"], ...].
function campaign_top_donors(array $c, $limit = 3) {
    $id = (int) $c['id'];
    $limit = (int) $limit;
    if ($c['campaign_type'] === 'volunteers') { return []; }
    $out = [];
    if ($c['campaign_type'] === 'items') {
        $rows = campaign_rows(
            'SELECT dn.id AS donor_id, MAX(' . CAMPAIGN_DONOR_NAME_SQL . ') AS donor_name, SUM(n.quantity) AS total, MAX(n.unit) AS unit
             FROM non_cash_donations n JOIN donors dn ON dn.id = n.donor_id
             WHERE n.campaign_id = ? AND dn.id <> ' . CAMPAIGN_ANONYMOUS_DONOR_ID . '
             GROUP BY dn.id ORDER BY total DESC, donor_name LIMIT ?',
            'ii', [$id, $limit]);
        foreach ($rows as $r) {
            $out[] = [(int) $r['donor_id'], (string) $r['donor_name'], campaign_num($r['total']) . ' ' . $r['unit']];
        }
        return $out;
    }
    $rows = campaign_rows(
        'SELECT dn.id AS donor_id, MAX(' . CAMPAIGN_DONOR_NAME_SQL . ') AS donor_name, SUM(d.amount) AS total
         FROM donations d JOIN donors dn ON dn.id = d.donorID
         WHERE d.eventID = ? AND dn.id <> ' . CAMPAIGN_ANONYMOUS_DONOR_ID . '
         GROUP BY dn.id ORDER BY total DESC, donor_name LIMIT ?',
        'ii', [$id, $limit]);
    foreach ($rows as $r) {
        $out[] = [(int) $r['donor_id'], (string) $r['donor_name'], '$' . number_format((float) $r['total'], 2)];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Lookups for forms
// ---------------------------------------------------------------------------------------------

// Active staff accounts for the Owner dropdown: [['id' => 'ccoleman', 'name' => 'Charity Coleman'], ...].
function campaign_owner_options() {
    return campaign_rows(
        "SELECT id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), id) AS name
         FROM dbpersons WHERE status = 'active' AND id <> 'vmsroot' ORDER BY name, id");
}

// Campaigns a new gift can be linked to (not completed), for the donation form's campaign dropdown:
// [['id' => 3, 'name' => 'Thanksgiving Food Drive'], ...]. Link gifts by id, not by name.
function campaigns_options() {
    return campaign_rows(
        "SELECT id, name FROM campaign_summary WHERE status <> 'completed'
         ORDER BY FIELD(status, 'active', 'upcoming'), name");
}

// ---------------------------------------------------------------------------------------------
// Add / edit form
// ---------------------------------------------------------------------------------------------

function campaign_form_defaults($ownerId = '') {
    return ['name' => '', 'description' => '', 'owner_id' => (string) $ownerId, 'type' => 'cash',
            'cash_goal' => '', 'item_goal' => '', 'item_unit' => 'items', 'volunteer_goal' => '',
            'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+30 days')),
            'fund' => 'General Fund', 'location' => '',
            'show_on_dashboard' => 1, 'email_past_donors' => 0, 'ask_volunteers' => 0, 'completed' => 0];
}

// Form values for an existing campaign (a campaign_get() row).
function campaign_form_from_row(array $c) {
    return ['name' => (string) $c['name'], 'description' => (string) $c['description'],
            'owner_id' => (string) $c['owner_id'], 'type' => $c['campaign_type'],
            'cash_goal' => campaign_plain_num($c['cash_goal']), 'item_goal' => campaign_plain_num($c['item_goal']),
            'item_unit' => $c['item_unit'],
            'volunteer_goal' => $c['volunteer_goal'] === null ? '' : (string) (int) $c['volunteer_goal'],
            'start_date' => (string) $c['start_date'], 'end_date' => (string) $c['end_date'],
            'fund' => (string) $c['fund'], 'location' => (string) $c['location'],
            'show_on_dashboard' => (int) $c['show_on_dashboard'], 'email_past_donors' => (int) $c['email_past_donors'],
            'ask_volunteers' => (int) $c['ask_volunteers'], 'completed' => (int) $c['is_completed']];
}

// Posted values, trimmed. Anything that is not a plain string becomes ''.
function campaign_form_from_post(array $post, array $base) {
    $s = function ($k) use ($post) {
        $v = $post[$k] ?? '';
        return is_string($v) ? trim($v) : '';
    };
    return array_merge($base, [
        'name' => $s('name'), 'description' => $s('description'), 'owner_id' => $s('owner_id'), 'type' => $s('type'),
        'cash_goal' => $s('cash_goal'), 'item_goal' => $s('item_goal'), 'item_unit' => $s('item_unit'),
        'volunteer_goal' => $s('volunteer_goal'), 'start_date' => $s('start_date'), 'end_date' => $s('end_date'),
        'fund' => $s('fund'), 'location' => $s('location'),
        'show_on_dashboard' => isset($post['show_on_dashboard']) ? 1 : 0,
        'email_past_donors' => isset($post['email_past_donors']) ? 1 : 0,
        'ask_volunteers' => isset($post['ask_volunteers']) ? 1 : 0,
        'completed' => isset($post['completed']) ? 1 : 0,
    ]);
}

// Validate and normalise the form in place; returns a list of error messages (empty = OK).
// Goals that do not apply to the chosen type are cleared, because the browser still submits hidden inputs.
function campaign_form_validate(array &$form, array $ownerIds) {
    $errors = [];
    foreach (CAMPAIGN_FIELD_LIMITS as $k => $max) {
        if (mb_strlen($form[$k]) > $max) {
            $errors[] = ucfirst($k) . ' is too long (at most ' . $max . ' characters).';
        }
    }
    if ($form['name'] === '') { $errors[] = 'Campaign name is required.'; }
    if (!array_key_exists($form['type'], CAMPAIGN_TYPES)) {
        $form['type'] = 'cash';
        $errors[] = 'Choose what the campaign is collecting.';
    }
    if ($form['owner_id'] !== '' && !in_array($form['owner_id'], $ownerIds, true)) {
        $errors[] = 'Choose an owner from the list.';
    }

    $usesCash  = in_array($form['type'], ['cash', 'both'], true);
    $usesItems = in_array($form['type'], ['items', 'both'], true);
    if (!$usesCash)  { $form['cash_goal'] = ''; }
    if (!$usesItems) { $form['item_goal'] = ''; $form['item_unit'] = 'items'; }

    foreach (['cash_goal' => 'Cash goal', 'item_goal' => 'Item goal'] as $k => $label) {
        if ($form[$k] === '') { continue; }
        $clean = str_replace([',', '$', ' '], '', $form[$k]);
        if (!is_numeric($clean) || (float) $clean <= 0 || (float) $clean > 9999999999.99) {
            $errors[] = $label . ' must be a number greater than zero.';
        } else {
            $form[$k] = number_format((float) $clean, 2, '.', '');
        }
    }
    if (!in_array($form['item_unit'], CAMPAIGN_UNITS, true)) { $form['item_unit'] = 'items'; }
    if ($form['volunteer_goal'] !== ''
        && (!ctype_digit($form['volunteer_goal']) || (int) $form['volunteer_goal'] < 1 || (int) $form['volunteer_goal'] > 100000)) {
        $errors[] = 'Volunteer shifts must be a whole number of at least 1.';
    }

    $validDates = 0;
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $k => $label) {
        $d = DateTime::createFromFormat('Y-m-d', $form[$k]);
        if (!$d || $d->format('Y-m-d') !== $form[$k]) {
            $errors[] = $label . ' is required.';
        } else {
            $validDates++;
        }
    }
    if ($validDates === 2 && $form['end_date'] < $form['start_date']) {
        $errors[] = 'End date must be on or after the start date.';
    }
    return $errors;
}

// Values in the order both statements below bind them.
function campaign_bind_values(array $f, $byUser) {
    return [$f['name'], $f['type'], $f['description'],
            $f['cash_goal'] === '' ? null : $f['cash_goal'],
            $f['item_goal'] === '' ? null : $f['item_goal'],
            $f['item_unit'],
            $f['volunteer_goal'] === '' ? null : (int) $f['volunteer_goal'],
            $f['start_date'], $f['end_date'],
            $f['fund'] === '' ? null : $f['fund'],
            $f['location'],
            $f['owner_id'] === '' ? null : $f['owner_id'],
            (int) $f['show_on_dashboard'], (int) $f['email_past_donors'], (int) $f['ask_volunteers'],
            $f['completed'] ? '1' : '0',
            $byUser];
}

// Create a campaign. Returns the new id, or false on a database error.
function campaign_create(array $form, $byUser) {
    try {
        $con = connect();
        $stmt = $con->prepare(
            'INSERT INTO dbevents (name, campaign_type, description, goalAmount, item_goal, item_unit, volunteer_goal,
                                   startDate, endDate, fund, location, owner_id,
                                   show_on_dashboard, email_past_donors, ask_volunteers, completed, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $values = campaign_bind_values($form, $byUser);
        $stmt->bind_param('ssssssisssssiiiss', ...$values);
        $stmt->execute();
        $newId = $stmt->errno === 0 ? (int) $con->insert_id : false;
        $stmt->close();
        mysqli_close($con);
        return $newId;
    } catch (mysqli_sql_exception $e) {
        error_log('campaign_create: ' . $e->getMessage());
        return false;
    }
}

// Update a campaign. Returns true on success, false on a database error.
function campaign_update($id, array $form, $byUser) {
    try {
        $con = connect();
        $stmt = $con->prepare(
            'UPDATE dbevents SET name = ?, campaign_type = ?, description = ?, goalAmount = ?, item_goal = ?, item_unit = ?,
                    volunteer_goal = ?, startDate = ?, endDate = ?, fund = ?, location = ?, owner_id = ?,
                    show_on_dashboard = ?, email_past_donors = ?, ask_volunteers = ?, completed = ?, updated_by = ?
             WHERE id = ?');
        $values = campaign_bind_values($form, $byUser);
        $values[] = (int) $id;
        $stmt->bind_param('ssssssisssssiiissi', ...$values);
        $stmt->execute();
        $ok = $stmt->errno === 0;
        $stmt->close();
        mysqli_close($con);
        return $ok;
    } catch (mysqli_sql_exception $e) {
        error_log('campaign_update: ' . $e->getMessage());
        return false;
    }
}
