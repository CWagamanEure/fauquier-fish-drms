<?php
// Data & Imports exports (UF2-132..139): every Export form on dataImports.php posts `export` (+ from/to or
// year) here, and the answer is a CSV download or a redirect back with a fixed error code. Built so far: `cash`
// (UF2-133) and `noncash` (UF2-134); each later export adds a case with its own permission check and query.
// Including the layout signs in the visitor and checks the CSRF token on every POST (require_authentication).
require_once 'include/fishnet-layout.php';
require_once 'include/csvExport.php';
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
 * Runs one prepared SELECT bound to the date range and returns [$con, $stmt, $result], before any header is
 * sent. A database error is logged and answered with a redirect to `failed`, so there is never a partial file.
 */
function export_query($sql, $from, $to, $what) {
    try {
        $con = connect();
        $stmt = $con->prepare($sql);
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
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
             ORDER BY d.donation_date, d.id", $from, $to, 'cash');
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
             ORDER BY n.date_received, n.id", $from, $to, 'noncash');
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

    default:
        export_back('unavailable');
}
