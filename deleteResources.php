<?php
// Deletes one uploaded document. POST only, with a form token, and the file must be a plain
// name inside uploads/ (no path separators), mirroring the checks in download.php.
require_once __DIR__ . '/include/auth.php';
require_authentication(2);
auth_require_csrf();

$file = $_POST['file'] ?? '';
$directory = realpath(__DIR__ . '/uploads');
$path = false;
if (is_string($file) && $file !== '' && strpbrk($file, "/\\\0") === false && $directory !== false) {
    $path = realpath($directory . '/' . $file);
}
if ($path === false || dirname($path) !== $directory || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
unlink($path);
header('Location: resources.php', true, 303);
exit;
