<?php
require_once __DIR__ . '/include/auth.php';

// Every private document type: where it lives, which extensions may be served, who may fetch it.
$documents = [
    'reports'   => ['dir' => __DIR__ . '/reportGeneration/reports', 'ext' => ['pdf'],                 'level' => 3],
    'exports'   => ['dir' => __DIR__ . '/python/exports',           'ext' => ['xlsx', 'csv'],         'level' => 3],
    'resources' => ['dir' => __DIR__ . '/uploads',                  'ext' => ['pdf', 'doc', 'docx'],  'level' => 1],
];

$type = $_GET['type'] ?? '';
if (!is_string($type) || !isset($documents[$type])) {
    require_authentication(1);
    http_response_code(404);
    exit('File not found.');
}
require_authentication($documents[$type]['level']);

$file = $_GET['file'] ?? '';
$path = auth_private_file($documents[$type]['dir'], $file, $documents[$type]['ext']);
if ($path === false) {
    http_response_code(404);
    exit('File not found.');
}
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

header('Content-Type: ' . ($extension === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
$disposition = $extension === 'pdf' ? 'inline' : 'attachment';
header("Content-Disposition: $disposition; filename=\"download.$extension\"; filename*=UTF-8''" . rawurlencode($file));
header('Content-Length: ' . filesize($path));
session_write_close();
readfile($path);
