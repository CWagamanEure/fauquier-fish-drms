<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(2);
require_once('database/dbEvents.php');

if (!isset($_POST['selectedEvents'])) {
    header("Location: events.php");
    exit;
}

$ids = json_decode($_POST['selectedEvents'], true);

if (is_array($ids)) {
    foreach ($ids as $id) {
        delete_event($id);
    }
}

header("Location: viewAllEvents.php");
exit;
?>
