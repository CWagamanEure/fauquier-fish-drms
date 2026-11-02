<?php
// Deletes one uploaded document. POST only (require_authentication checks the form token on
// every POST; auth_require_csrf rejects GET), and the file must be a plain document name inside
// uploads/, resolved by the same helper download.php uses.
require_once __DIR__ . '/include/auth.php';
require_authentication(2);
auth_require_csrf();

$path = auth_private_file(__DIR__ . '/uploads', $_POST['file'] ?? '', ['pdf', 'doc', 'docx']);
if ($path === false) {
    http_response_code(404);
    exit('File not found.');
}
unlink($path);
header('Location: resources.php', true, 303);
exit;
