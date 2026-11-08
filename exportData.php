<?php
// Data & Imports exports (UF2-132..139): every Export form on dataImports.php posts `export` (+ from/to or
// year) here, and the answer is a CSV download or a redirect back with a fixed error code. Only `cash`
// (UF2-133) exists so far; each later export adds a case with its own permission check and query.
// Including the layout signs in the visitor and checks the CSRF token on every POST (require_authentication).
require_once 'include/fishnet-layout.php';
require_once 'include/csvExport.php';
ini_set('display_errors', '0');   // nothing but the file or a redirect may reach the browser

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

function export_back($error) {
    header('Location: dataImports.php?export_error=' . $error . '#export', true, 303);
    exit;
}

switch (fish_str($_POST, 'export')) {
    case 'cash':
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
        [$from, $to] = $dates;
        $showDonorId = fish_can('donors', 'view');   // the donations list only shows donor ids with donors view
        session_write_close();
        try {
            $con = connect();
            $stmt = $con->prepare("SELECT d.id, d.donation_date, d.donorID, s.display_name, s.is_anonymous,
                                          d.amount, d.fee, d.net_amount, d.payment_method, d.check_number, d.platform,
                                          d.deposit_date, e.name AS campaign, d.in_honor_of, d.honoree_name,
                                          DATE(d.receipt_sent_at) AS receipt_sent, p.first_name, p.last_name, d.reason
                                   FROM donations d
                                   LEFT JOIN donor_summary s ON s.id = d.donorID
                                   LEFT JOIN dbevents e ON e.id = d.eventID
                                   LEFT JOIN dbpersons p ON p.id = d.recorded_by
                                   WHERE d.donation_date BETWEEN ? AND ?
                                   ORDER BY d.donation_date, d.id");
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $result = $stmt->get_result();
        } catch (mysqli_sql_exception $e) {
            error_log('cash export failed: ' . $e->getMessage());
            export_back('failed');
        }
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

    default:
        export_back('unavailable');
}
