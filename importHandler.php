<?php
// Data & Imports actions (POST only; the layout checks login and the CSRF token on every POST):
//   action=upload  type + file      -> read and check every row, store the batch, go to importPreview.php
//   action=import  batch            -> create the records for the ready rows; the rest wait in Needs review
//   action=cancel  batch            -> drop a checked batch without importing anything
//   action=skip    row              -> a review row is set aside (nothing is created)
//   action=force   row              -> a duplicate is imported anyway
//   action=fix     row + cells[...] -> the row's cells are replaced, re-checked and imported if they pass
// Every action checks import_can() for the batch's type.
require_once 'include/fishnet-layout.php';
require_once 'include/importReader.php';
require_once 'database/dbImports.php';
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

function import_back($page, array $query = []) {
    header('Location: ' . $page . ($query ? '?' . http_build_query($query) : ''), true, 303);
    exit;
}

function import_forbidden() {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'You do not have access to imports.';
    exit;
}

$action = fish_str($_POST, 'action');
$user = auth_current_user()->get_id();

try {
    if ($action === 'upload') {
        $type = fish_str($_POST, 'type');
        if (!isset(IMPORT_TYPES[$type])) { import_back('dataImports.php', ['import_error' => 'type']); }
        if (!import_can($type)) { import_forbidden(); }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            import_back('dataImports.php', ['import_error' => 'nofile']);
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > IMPORT_MAX_BYTES) {
            import_back('dataImports.php', ['import_error' => 'size']);
        }
        if (!is_uploaded_file($f['tmp_name'])) { import_back('dataImports.php', ['import_error' => 'nofile']); }
        $name = basename((string) $f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $parsed = import_read_file($f['tmp_name'], $ext);
        @unlink($f['tmp_name']);   // the file itself is never kept; only the checked rows are stored
        if ($parsed['error'] !== null) {
            $_SESSION['import_message'] = $parsed['error'];
            import_back('dataImports.php', ['import_error' => 'file']);
        }
        $missing = import_missing_headers($type, $parsed['headers']);
        if ($missing) {
            $_SESSION['import_message'] = 'This does not look like the ' . IMPORT_TYPES[$type]['label']
                . ' template. Missing columns: ' . implode(', ', $missing) . '.';
            import_back('dataImports.php', ['import_error' => 'file']);
        }
        if (!$parsed['rows']) {
            $_SESSION['import_message'] = 'The file has no rows to import below the headings.';
            import_back('dataImports.php', ['import_error' => 'file']);
        }
        $batchId = import_batch_create($type, $name, $parsed['rows'], $user);
        if (!$batchId) { import_back('dataImports.php', ['import_error' => 'failed']); }
        import_back('importPreview.php', ['id' => $batchId]);
    }

    if ($action === 'import' || $action === 'cancel') {
        $batch = import_batch_get((int) fish_str($_POST, 'batch'));
        if (!$batch) { import_back('dataImports.php'); }
        if (!import_can($batch['import_type'])) { import_forbidden(); }
        if ($batch['status'] !== 'checked') { import_back('importPreview.php', ['id' => $batch['id']]); }
        if ($action === 'cancel') {
            if (!import_batch_cancel((int) $batch['id'])) { import_back('importPreview.php', ['id' => $batch['id']]); }
            import_back('dataImports.php', ['import_done' => 'cancelled']);
        }
        // Claim the batch so a double-clicked Import (or a resubmit) cannot run the rows twice. The session
        // stays open while the rows are saved (2000 rows at most), so a sign-out waits for it to finish.
        if (!import_batch_claim((int) $batch['id'])) { import_back('importPreview.php', ['id' => $batch['id']]); }
        set_time_limit(300);
        try {
            foreach (import_batch_rows((int) $batch['id'], ['ready']) as $row) {
                // A row that fails now (e.g. the donor was removed) moves to review; "Import anyway" rows skip the
                // duplicate check. One row's database error must not stop the rest of the file.
                try {
                    import_row_save($batch, $row, $user, $row['problem'] === IMPORT_FORCED);
                } catch (Throwable $e) {
                    error_log('import row ' . $row['id'] . ' failed: ' . $e->getMessage());
                    try { import_row_mark((int) $row['id'], 'error', 'Could not be checked or saved. Fix it (save without changes) to try again.', null, $user, null, $row['dup_key']); }
                    catch (Throwable $ignored) {}
                }
            }
        } finally {
            import_batch_set_status((int) $batch['id'], 'imported');
        }
        import_back('importPreview.php', ['id' => $batch['id'], 'done' => 1]);
    }

    if (in_array($action, ['skip', 'force', 'fix'], true)) {
        $row = import_row_get((int) fish_str($_POST, 'row'));
        $batch = $row ? import_batch_get((int) $row['batch_id']) : null;
        if (!$row || !$batch) { import_back('dataImports.php'); }
        if (!import_can($batch['import_type'])) { import_forbidden(); }
        $back = fish_str($_POST, 'back') === 'preview' ? ['importPreview.php', ['id' => $batch['id']]] : ['dataImports.php', []];
        if (!in_array($row['status'], ['error', 'duplicate'], true)) { import_back(...$back); }
        if ($action === 'skip') {
            import_row_mark((int) $row['id'], 'skipped', $row['problem'], null, $user, $row['match_id'] === null ? null : (int) $row['match_id'], $row['dup_key']);
            import_back($back[0], $back[1] + ['review' => 'skipped']);
        }
        if ($action === 'force' && $row['status'] !== 'duplicate') { import_back(...$back); }
        if ($action === 'fix') {
            $cells = isset($_POST['cells']) && is_array($_POST['cells']) ? $_POST['cells'] : [];
            $row['data'] = import_row_set_data((int) $row['id'], $batch['import_type'], array_map(fn($v) => is_string($v) ? $v : '', $cells));
        }
        if ($batch['status'] === 'checked') {
            // Still at the preview step: re-check only (same checks as the upload, including the other rows of
            // the file); Import creates the record with the rest.
            $seen = import_batch_keys((int) $batch['id'], (int) $row['id']);
            $r = import_check_full($batch['import_type'], import_cells($batch['import_type'], $row['data']), $seen, (int) $row['sheet_row']);
            $status = $action === 'force' && $r['status'] !== 'error' ? 'ready' : $r['status'];
            $note = $status === 'ready' ? ($action === 'force' ? IMPORT_FORCED : null) : implode('; ', $r['errors']);
            import_row_mark((int) $row['id'], $status, $note, null, $user, $r['match'], $r['key']);
            // If Import claimed the batch meanwhile, a row made ready here would never be imported: send it to review.
            $now = import_batch_get((int) $batch['id']);
            if ($now && $now['status'] !== 'checked' && $status === 'ready') {
                import_row_mark((int) $row['id'], $action === 'force' ? 'duplicate' : 'error', 'The file was imported while this row was being changed. Use Fix or Import anyway again.', null, $user, $r['match'], $r['key']);
            }
            import_back(...$back);
        }
        $result = import_row_save($batch, $row, $user, $action === 'force');
        if ($result['ok']) { import_back($back[0], $back[1] + ['review' => 'imported']); }
        if ($action === 'fix') { import_back('importFix.php', ['row' => $row['id'], 'failed' => 1] + ($back[0] === 'importPreview.php' ? ['back' => 'preview'] : [])); }
        import_back($back[0], $back[1] + ['review' => 'failed']);
    }
} catch (mysqli_sql_exception $e) {
    error_log('importHandler ' . $action . ' failed: ' . $e->getMessage());
    import_back('dataImports.php', ['import_error' => 'failed']);
}

http_response_code(400);
echo 'Unknown action.';
