<?php
// Treasurer page data (UF2-163..167): cash donations (money in) from `donations` and expenses (money out,
// migration 019) for a date range and fund, plus calendar-year totals. Every statement is prepared and
// every value bound.
//
// "Fund" is the campaign's Fund / designation (dbevents.fund, migration 009). A gift or expense with no
// campaign, or whose campaign has no designation, is the General Fund. Donations link to campaigns
// through donations.eventID, expenses through expenses.campaign_id.
require_once __DIR__ . '/dbinfo.php';
require_once __DIR__ . '/dbDonations.php';   // DONATION_PAYMENT_LABELS, DONOR_ANONYMOUS_ID (via dbDonors.php)

const TREASURER_GENERAL_FUND = 'General Fund';
const TREASURER_SHOW = ['all', 'donations', 'expenses'];
const TREASURER_SPANS = [3, 5];
// The fund label of a row joined to its campaign as `e` (bound, never interpolated from input).
const TREASURER_FUND_SQL = "COALESCE(NULLIF(TRIM(e.fund), ''), 'General Fund')";

// A real calendar date 'YYYY-MM-DD' between 1900 and 2100 (strtotime() would roll 2026-02-30 over).
function treasurer_valid_date($s) {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return false;
    }
    $y = (int) $m[1];
    return $y >= 1900 && $y <= 2100 && checkdate((int) $m[2], (int) $m[3], $y);
}

// FROM + WHERE for one side ('donations' or 'expenses') between two dates, optionally one fund. The log,
// the cards and the year chart all use this, so they always count the same rows. $withDonor adds the
// donor name join (`s`) the log needs; a LEFT JOIN, so it never changes which rows match.
function treasurer_side_sql($side, $from, $to, $fund, $withDonor = false) {
    $fundExpr = TREASURER_FUND_SQL;
    if ($side === 'donations') {
        $donor = $withDonor ? 'LEFT JOIN donor_summary s ON s.id = d.donorID' : '';
        $sql = "FROM donations d LEFT JOIN dbevents e ON e.id = d.eventID $donor
                WHERE d.donation_date BETWEEN ? AND ? AND d.amount IS NOT NULL";
    } else {
        $sql = "FROM expenses x LEFT JOIN dbevents e ON e.id = x.campaign_id
                WHERE x.expense_date BETWEEN ? AND ?";
    }
    $types = 'ss';
    $values = [$from, $to];
    if ($fund !== '') {
        $sql .= " AND $fundExpr = ?";
        $types .= 's';
        $values[] = $fund;
    }
    return [$sql, $types, $values];
}

function treasurer_query($sql, $types, array $values) {
    $con = connect();
    try {
        $stmt = $con->prepare($sql);
        if ($types !== '') { $stmt->bind_param($types, ...$values); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } finally {
        mysqli_close($con);
    }
    return $rows;
}

// Every fund label that has at least one donation or expense, General Fund first, then A–Z.
function treasurer_funds() {
    $fundExpr = TREASURER_FUND_SQL;
    $rows = treasurer_query("SELECT $fundExpr AS fund FROM donations d LEFT JOIN dbevents e ON e.id = d.eventID
                               WHERE d.amount IS NOT NULL AND d.donation_date IS NOT NULL
                             UNION
                             SELECT $fundExpr FROM expenses x LEFT JOIN dbevents e ON e.id = x.campaign_id", '', []);
    $funds = [];
    foreach ($rows as $r) {
        $f = (string) $r['fund'];
        if ($f !== TREASURER_GENERAL_FUND) { $funds[$f] = true; }
    }
    $funds = array_keys($funds);
    usort($funds, 'strcasecmp');
    array_unshift($funds, TREASURER_GENERAL_FUND);
    return $funds;
}

// "Check #1042 · Winter Coat Collection"
function treasurer_detail($kind, $method, $check, $campaign) {
    if ($kind === 'donation') {
        $parts = [DONATION_PAYMENT_LABELS[$method] ?? ''];
        $check = trim((string) $check);
        if ($check !== '') { $parts[0] = trim($parts[0] . ' #' . $check); }
    } else {
        $parts = [str_replace('_', ' ', ucfirst((string) $method))];
    }
    $campaign = trim((string) $campaign);
    if ($campaign !== '') { $parts[] = $campaign; }
    return implode(' · ', array_filter($parts, fn($p) => $p !== ''));
}

// Donations and/or expenses in [$from, $to], newest first, in the shape treasurer.php renders.
function treasurer_log($from, $to, $show, $fund) {
    $fundExpr = TREASURER_FUND_SQL;
    $parts = [];
    $types = '';
    $values = [];
    if ($show !== 'expenses') {
        [$where, $t, $v] = treasurer_side_sql('donations', $from, $to, $fund, true);
        $parts[] = "SELECT 'donation' AS kind, d.id, d.donation_date AS entry_date,
                           CASE WHEN d.donorID = " . (int) DONOR_ANONYMOUS_ID . " THEN 'Anonymous'
                                ELSE COALESCE(NULLIF(TRIM(s.display_name), ''), 'Unknown donor') END AS who,
                           d.payment_method AS method, d.check_number AS check_no, e.name AS campaign,
                           $fundExpr AS fund, d.amount
                    $where";
        $types .= $t;
        $values = array_merge($values, $v);
    }
    if ($show !== 'donations') {
        [$where, $t, $v] = treasurer_side_sql('expenses', $from, $to, $fund);
        $parts[] = "SELECT 'expense' AS kind, x.id, x.expense_date AS entry_date, x.payee AS who, x.category AS method,
                           NULL AS check_no, e.name AS campaign, $fundExpr AS fund, x.amount
                    $where";
        $types .= $t;
        $values = array_merge($values, $v);
    }
    $rows = treasurer_query(implode(' UNION ALL ', $parts) . ' ORDER BY entry_date DESC, kind, id DESC', $types, $values);
    $log = [];
    foreach ($rows as $r) {
        $log[] = ['date' => $r['entry_date'], 'kind' => $r['kind'], 'who' => (string) $r['who'],
                  'detail' => treasurer_detail($r['kind'], $r['method'], $r['check_no'], $r['campaign']),
                  'fund' => (string) $r['fund'], 'amount' => (float) $r['amount']];
    }
    return $log;
}

// The four cards for [$from, $to] and the fund: money in, money out, and how many of each.
function treasurer_totals($from, $to, $fund) {
    [$dWhere, $dTypes, $dValues] = treasurer_side_sql('donations', $from, $to, $fund);
    [$xWhere, $xTypes, $xValues] = treasurer_side_sql('expenses', $from, $to, $fund);
    $row = treasurer_query("SELECT (SELECT COALESCE(SUM(d.amount), 0) $dWhere) AS money_in,
                                   (SELECT COUNT(*) $dWhere) AS donation_count,
                                   (SELECT COALESCE(SUM(x.amount), 0) $xWhere) AS money_out,
                                   (SELECT COUNT(*) $xWhere) AS expense_count",
                           $dTypes . $dTypes . $xTypes . $xTypes,
                           array_merge($dValues, $dValues, $xValues, $xValues))[0];
    return ['in' => (float) $row['money_in'], 'out' => (float) $row['money_out'],
            'donations' => (int) $row['donation_count'], 'expenses' => (int) $row['expense_count']];
}

// One row per calendar year for the last $span years (this year included), oldest first, zero-filled.
function treasurer_years($span, $fund, $thisYear = null) {
    $span = in_array($span, TREASURER_SPANS, true) ? $span : 3;
    $last = $thisYear ?? (int) date('Y');
    $first = $last - $span + 1;
    $years = [];
    for ($y = $first; $y <= $last; $y++) {
        $years[$y] = ['year' => $y, 'donations' => 0.0, 'expenses' => 0.0];
    }
    $from = sprintf('%04d-01-01', $first);
    $to = sprintf('%04d-12-31', $last);
    foreach (['donations' => 'd.donation_date', 'expenses' => 'x.expense_date'] as $side => $col) {
        [$where, $types, $values] = treasurer_side_sql($side, $from, $to, $fund);
        $amount = $side === 'donations' ? 'd.amount' : 'x.amount';
        foreach (treasurer_query("SELECT YEAR($col) AS y, SUM($amount) AS total $where GROUP BY YEAR($col)", $types, $values) as $r) {
            $years[(int) $r['y']][$side] = (float) $r['total'];
        }
    }
    return array_values($years);
}
