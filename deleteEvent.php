<?php
// Deletes one event/campaign. POST with a form token only; the id must be an integer.
require_once __DIR__ . '/include/auth.php';
require_authentication(2);
auth_require_csrf();
require_once('database/dbEvents.php');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    header('Location: index.php', true, 303);
    exit;
}
if (delete_event($id)) {
    header('Location: calendar.php?deleteSuccess', true, 303);
    exit;
}
header('Location: index.php', true, 303);
