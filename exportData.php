<?php
// Data & Imports exports (UF2-132..139): every Export form on dataImports.php posts `export` (+ from/to or
// year) here, and the answer is a CSV download or a redirect back with a fixed error code. Built so far: `cash`
// (UF2-133), `noncash` (UF2-134), `hours` (UF2-138), `emails` (UF2-132), `donor-list` (UF2-136) and `donor-totals` (UF2-135); each later export adds a case with its own permission
// check and query.
// Including the layout signs in the visitor and checks the CSRF token on every POST (require_authentication).
require_once 'include/fishnet-layout.php';
require_once 'include/csvExport.php';
require_once 'database/dbDonors.php';   // donor_type_label(), donor_pref_label(), donor_status_label()
ini_set('display_errors', '0');   // nothing but the file or a redirect may reach the browser

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

/** Redirects to the Export section of dataImports.php with a fixed error code, and ends the request. */
function export_back($error) {
    header('Location: dataImports.php?export_error=' . $error . '#export', true, 303);
    exit;
}

/**
 * The steps every donation date-range export takes before its query, in this order: the permission check
 * (403 and exit without donations view), the dates (redirect with `dates` and exit when invalid), whether
 * Donor ID may be shown (donors view, as on the donations list), then releasing the session lock. Returns
 * [$from, $to, $showDonorId] only when the export may go ahead; every refusal ends the request here.
 */
function export_range_start() {
    if (!fish_can_export_donations()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You do not have access to donation exports.';
        exit;
    }
    $dates = fish_export_dates(fish_str($_POST, 'from'), fish_str($_POST, 'to'));
    if ($dates === null) {
        export_back('dates');
    }
    $showDonorId = fish_can('donors', 'view');
    session_write_close();
    return [$dates[0], $dates[1], $showDonorId];
}

/**
 * Runs one prepared SELECT with the given bound values (none for hours) and returns [$con, $stmt, $result],
 * before any header is sent. A database error is logged and answered with a redirect to `failed`, so there is
 * never a partial file.
 */
function export_query($sql, $what, array $params = []) {
    try {
        $con = connect();
        $stmt = $con->prepare($sql);
        $params ? $stmt->execute($params) : $stmt->execute();
        return [$con, $stmt, $stmt->get_result()];
    } catch (mysqli_sql_exception $e) {
        error_log($what . ' export failed: ' . $e->getMessage());
        export_back('failed');
    }
}

switch (fish_str($_POST, 'export')) {
    case 'cash':
        [$from, $to, $showDonorId] = export_range_start();
        [$con, $stmt, $result] = export_query(
            "SELECT d.id, d.donation_date, d.donorID, s.display_name, s.is_anonymous,
                    d.amount, d.fee, d.net_amount, d.payment_method, d.check_number, d.platform,
                    d.deposit_date, e.name AS campaign, d.in_honor_of, d.honoree_name,
                    DATE(d.receipt_sent_at) AS receipt_sent, p.first_name, p.last_name, d.reason
             FROM donations d
             LEFT JOIN donor_summary s ON s.id = d.donorID
             LEFT JOIN dbevents e ON e.id = d.eventID
             LEFT JOIN dbpersons p ON p.id = d.recorded_by
             WHERE d.donation_date BETWEEN ? AND ?
             ORDER BY d.donation_date, d.id", 'cash', [$from, $to]);
        $rows = (function () use ($result, $showDonorId) {
            while ($r = $result->fetch_assoc()) {
                yield [
                    $r['id'],
                    $r['donation_date'],
                    $showDonorId ? $r['donorID'] : null,
                    (int) $r['is_anonymous'] === 1 ? 'Anonymous' : $r['display_name'],
                    $r['amount'],
                    $r['fee'],
                    $r['net_amount'],
                    $r['payment_method'] === null ? null : (DONATION_PAYMENT_LABELS[$r['payment_method']] ?? $r['payment_method']),
                    $r['check_number'],
                    $r['platform'] === null ? null : (DONATION_PLATFORM_LABELS[$r['platform']] ?? $r['platform']),
                    $r['deposit_date'],
                    $r['campaign'],
                    (int) $r['in_honor_of'] === 1 ? $r['honoree_name'] : null,
                    $r['receipt_sent'],
                    donation_recorder_name($r['first_name'], $r['last_name']),
                    $r['reason'],
                ];
            }
        })();
        fish_csv_send("fish-cash-donations-$from-to-$to.csv",
                      ['Gift ID', 'Date', 'Donor ID', 'Donor', 'Amount', 'Fee', 'Net', 'Payment method', 'Check number',
                       'Platform', 'Deposit date', 'Campaign', 'In honor of', 'Receipt sent', 'Recorded by', 'Notes'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    case 'noncash':
        [$from, $to, $showDonorId] = export_range_start();
        [$con, $stmt, $result] = export_query(
            "SELECT n.id, n.date_received, n.donor_id, s.display_name, s.is_anonymous, n.item_description,
                    n.category, n.quantity, n.weight_lbs, n.estimated_value, n.item_condition,
                    (SELECT COALESCE(SUM(x.quantity), 0) FROM non_cash_distributions x WHERE x.non_cash_donation_id = n.id) AS distributed,
                    n.intended_use, e.name AS campaign, DATE(n.receipt_sent_at) AS receipt_sent,
                    p.first_name, p.last_name, n.notes
             FROM non_cash_donations n
             LEFT JOIN donor_summary s ON s.id = n.donor_id
             LEFT JOIN dbevents e ON e.id = n.campaign_id
             LEFT JOIN dbpersons p ON p.id = n.recorded_by
             WHERE n.date_received BETWEEN ? AND ?
             ORDER BY n.date_received, n.id", 'noncash', [$from, $to]);
        $rows = (function () use ($result, $showDonorId) {
            while ($r = $result->fetch_assoc()) {
                yield [
                    $r['id'],
                    $r['date_received'],
                    $showDonorId ? $r['donor_id'] : null,
                    (int) $r['is_anonymous'] === 1 ? 'Anonymous' : $r['display_name'],
                    $r['item_description'],
                    DONATION_CATEGORY_LABELS[$r['category']] ?? $r['category'],
                    $r['quantity'],
                    $r['weight_lbs'],
                    $r['estimated_value'],
                    $r['item_condition'] === null ? null : (DONATION_CONDITION_LABELS[$r['item_condition']] ?? $r['item_condition']),
                    $r['distributed'],
                    $r['intended_use'],
                    $r['campaign'],
                    $r['receipt_sent'],
                    donation_recorder_name($r['first_name'], $r['last_name']),
                    $r['notes'],
                ];
            }
        })();
        fish_csv_send("fish-noncash-donations-$from-to-$to.csv",
                      ['Gift ID', 'Date received', 'Donor ID', 'Donor', 'Item description', 'Category', 'Quantity (items)',
                       'Weight (lbs)', 'Estimated value', 'Condition', 'Given out so far (items)', 'Intended use',
                       'Campaign', 'Receipt sent', 'Recorded by', 'Notes'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    case 'hours':
        // Donors view, as on hours.php, addHours.php and the profile's Volunteer tab. No dates: every entry.
        if (!fish_can_export_hours()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have access to volunteer hours exports.';
            exit;
        }
        session_write_close();
        [$con, $stmt, $result] = export_query(
            "SELECT v.id, v.activity_date, v.donor_id, s.display_name, s.is_anonymous, v.hours,
                    TIME_FORMAT(v.start_time, '%H:%i') AS start_time, TIME_FORMAT(v.end_time, '%H:%i') AS end_time,
                    v.job, v.location, e.name AS campaign, v.is_verified, v.counts_for_letter,
                    p.first_name, p.last_name, v.notes
             FROM volunteer_hours v
             LEFT JOIN donor_summary s ON s.id = v.donor_id
             LEFT JOIN dbevents e ON e.id = v.campaign_id
             LEFT JOIN dbpersons p ON p.id = v.recorded_by
             ORDER BY v.activity_date, v.id", 'hours');
        $rows = (function () use ($result) {
            while ($r = $result->fetch_assoc()) {
                yield [
                    $r['id'],
                    $r['activity_date'],
                    $r['donor_id'],
                    (int) $r['is_anonymous'] === 1 ? 'Anonymous' : $r['display_name'],
                    $r['hours'],
                    $r['start_time'],
                    $r['end_time'],
                    $r['job'],
                    $r['location'],
                    $r['campaign'],
                    (int) $r['is_verified'] === 1 ? 'Yes' : 'No',
                    (int) $r['counts_for_letter'] === 1 ? 'Yes' : 'No',
                    donation_recorder_name($r['first_name'], $r['last_name']),
                    $r['notes'],
                ];
            }
        })();
        fish_csv_send('fish-volunteer-hours-' . date('Y-m-d') . '.csv',
                      ['Entry ID', 'Date', 'Volunteer ID', 'Volunteer', 'Hours', 'Start time', 'End time', 'Job',
                       'Location', 'Campaign', 'Verified', 'Counts for letter', 'Recorded by', 'Notes'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    case 'emails':
        // Donors view, as on the donor profile. No dates: one row per email of every active donor that is not Do
        // not contact, then one per email of their organization or foundation contact people (decided 2026-11-23).
        if (!fish_can_export_emails()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have access to the email export.';
            exit;
        }
        session_write_close();
        // A UNION's ORDER BY may only name output columns, so each branch selects its sort keys under aliases.
        [$con, $stmt, $result] = export_query(
            "SELECT s.id AS donor_id, s.display_name, NULL AS contact_name, NULL AS contact_title, s.email,
                    s.email_updates, 0 AS row_kind, 0 AS contact_primary, 0 AS contact_sort, 0 AS contact_id
             FROM donor_summary s
             WHERE s.is_anonymous = 0 AND s.is_active = 1 AND s.do_not_contact = 0
               AND COALESCE(s.email, '') REGEXP '[^[:space:]]'
             UNION ALL
             SELECT s.id, s.display_name, c.name, c.title, c.email,
                    s.email_updates, 1, c.is_primary, c.sort_order, c.id
             FROM donor_contacts c
             JOIN donor_summary s ON s.id = c.donor_id
             WHERE s.is_anonymous = 0 AND s.is_active = 1 AND s.do_not_contact = 0
               AND s.donor_type IN ('organization', 'foundation')
               AND COALESCE(c.email, '') REGEXP '[^[:space:]]'
             ORDER BY display_name, donor_id, row_kind, contact_primary DESC, contact_sort, contact_id", 'emails');
        $rows = (function () use ($result) {
            while ($r = $result->fetch_assoc()) {
                // Trim Unicode spaces too (a no-break space passes the SQL check); never write a blank email.
                // preg_replace() returns null on invalid UTF-8 (legacy data); fall back to plain trim() then.
                $email = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $r['email']) ?? trim((string) $r['email']);
                if ($email === '') {
                    continue;
                }
                yield [
                    $r['donor_id'],
                    $r['display_name'],
                    $r['contact_name'],
                    $r['contact_title'],
                    $email,
                    (int) $r['email_updates'] === 1 ? 'Yes' : 'No',
                ];
            }
        })();
        fish_csv_send('fish-donor-emails-' . date('Y-m-d') . '.csv',
                      ['Donor ID', 'Donor', 'Contact person', 'Contact title', 'Email', 'Email updates'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    case 'donor-list':
        // Donors view, as the hours and emails exports. No dates: one row per active donor (donor.php's "All"
        // list), Do not contact donors included with a Yes / No column (decided 2026-11-23). The main contact is
        // joined only for organizations and foundations, the types whose profile shows contacts.
        if (!fish_can_export_donor_list()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have access to the donor list export.';
            exit;
        }
        session_write_close();
        [$con, $stmt, $result] = export_query(
            "SELECT s.id, s.display_name, s.donor_type, s.email, s.phone, s.street, s.city, s.state, s.zip,
                    c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone,
                    s.preferred_contact, s.do_not_contact, s.email_updates, s.mail_receipts, s.status,
                    s.last_gift_date, s.donor_since_date
             FROM donor_summary s
             LEFT JOIN donor_contacts c ON c.donor_id = s.id AND c.is_primary = 1
                                       AND s.donor_type IN ('organization', 'foundation')
             WHERE s.is_anonymous = 0 AND s.is_active = 1
             ORDER BY s.display_name, s.id", 'donor list');
        $yesNo = fn($v) => (int) $v === 1 ? 'Yes' : 'No';
        $rows = (function () use ($result, $yesNo) {
            while ($r = $result->fetch_assoc()) {
                yield [
                    $r['id'], $r['display_name'], donor_type_label($r['donor_type']),
                    $r['email'], $r['phone'], $r['street'], $r['city'], $r['state'], $r['zip'],
                    $r['contact_name'], $r['contact_email'], $r['contact_phone'],
                    donor_pref_label($r['preferred_contact']),
                    $yesNo($r['do_not_contact']), $yesNo($r['email_updates']), $yesNo($r['mail_receipts']),
                    donor_status_label($r['status']), $r['last_gift_date'], $r['donor_since_date'],
                ];
            }
        })();
        fish_csv_send('fish-donor-list-' . date('Y-m-d') . '.csv',
                      ['Donor ID', 'Donor', 'Type', 'Email', 'Phone', 'Street', 'City', 'State', 'ZIP',
                       'Main contact', 'Main contact email', 'Main contact phone', 'Preferred contact',
                       'Do not contact', 'Email updates', 'Mail receipts', 'Status', 'Last gift', 'Donor since'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    case 'donor-totals':
        // Donations view and donors view: amounts are donations data, hours and the donor list are donors data. No
        // dates: lifetime totals from donor_summary for every active donor (the donor list's rows), zeros included.
        if (!fish_can_export_donor_totals()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have access to the donor totals export.';
            exit;
        }
        session_write_close();
        [$con, $stmt, $result] = export_query(
            "SELECT s.id, s.display_name, s.donor_type, s.status, s.cash_gift_count, s.lifetime_cash,
                    s.noncash_gift_count, s.items_donated, s.noncash_value, s.volunteer_shift_count, s.volunteer_hours,
                    s.first_gift_date, s.last_gift_date
             FROM donor_summary s
             WHERE s.is_anonymous = 0 AND s.is_active = 1
             ORDER BY s.display_name, s.id", 'donor totals');
        $rows = (function () use ($result) {
            while ($r = $result->fetch_assoc()) {
                yield [
                    $r['id'], $r['display_name'], donor_type_label($r['donor_type']), donor_status_label($r['status']),
                    $r['cash_gift_count'], $r['lifetime_cash'], $r['noncash_gift_count'], $r['items_donated'],
                    $r['noncash_value'], $r['volunteer_shift_count'], $r['volunteer_hours'],
                    $r['first_gift_date'], $r['last_gift_date'],
                ];
            }
        })();
        fish_csv_send('fish-donor-totals-' . date('Y-m-d') . '.csv',
                      ['Donor ID', 'Donor', 'Type', 'Status', 'Cash gifts', 'Cash total (gross)', 'Non-cash gifts',
                       'Items donated', 'Non-cash estimated value', 'Volunteer shifts', 'Volunteer hours',
                       'First gift', 'Last gift'],
                      $rows);
        $stmt->close();
        $con->close();
        exit;

    default:
        export_back('unavailable');
}
