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
if (!is_string($file) || $file === '' || strpbrk($file, "/\\\0") !== false) {
    http_response_code(404);
    exit('File not found.');
}
$directory = realpath($documents[$type]['dir']);
$path = $directory === false ? false : realpath($directory . '/' . $file);
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($path === false || dirname($path) !== $directory || !is_file($path)
    || !in_array($extension, $documents[$type]['ext'], true)) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: ' . ($extension === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
$disposition = $extension === 'pdf' ? 'inline' : 'attachment';
header("Content-Disposition: $disposition; filename=\"download.$extension\"; filename*=UTF-8''" . rawurlencode($file));
header('Content-Length: ' . filesize($path));
session_write_close();
readfile($path);
