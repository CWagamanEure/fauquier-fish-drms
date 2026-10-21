<?php
// Donor search for the Add donation form (UF2-49): GET ?q= returns up to 10 matching active donors as
// JSON. JSON errors instead of the login redirect, so the form can tell the user what happened.
require_once 'include/auth.php';
require_authentication(1, true);
require_once 'include/permissions.php';
require_once 'database/dbDonors.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!fish_can('donors', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account cannot view donors.']);
    exit;
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$len = mb_strlen($q, 'UTF-8');
if ($len < 2 || $len > 100) {
    echo json_encode(['success' => true, 'donors' => []]);
    exit;
}

try {
    echo json_encode(['success' => true, 'donors' => donors_lookup($q)]);
} catch (mysqli_sql_exception $e) {
    error_log('donorLookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not search donors. Please try again.']);
}
