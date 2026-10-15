<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(2, true);
require_once "database/dbPersons.php";

$query = isset($_GET['query']) ? $_GET['query'] : "";

$users = searchUsers($query);

echo json_encode($users);
?>

