<?php
require_once __DIR__ . '/include/auth.php';

$type = $_GET['type'] ?? '';
require_authentication($type === 'resources' ? 1 : 3);

$directories = [
    'reports' => __DIR__ . '/reportGeneration/reports',
    'exports' => __DIR__ . '/python/exports',
    'resources' => __DIR__ . '/uploads',
];
$extensions = [
    'reports' => ['pdf'],
    'exports' => ['xlsx', 'csv'],
    'resources' => ['pdf', 'doc', 'docx'],
];
$file = $_GET['file'] ?? '';
if (!is_string($type) || !isset($directories[$type]) || !is_string($file)
    || $file === '' || strpbrk($file, "/\\\0") !== false) {
    http_response_code(404);
    exit('File not found.');
}
$directory = realpath($directories[$type]);
$path = $directory === false ? false : realpath($directory . '/' . $file);
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($path === false || dirname($path) !== $directory || !is_file($path)
    || !in_array($extension, $extensions[$type], true)) {
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
