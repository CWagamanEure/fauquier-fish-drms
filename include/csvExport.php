<?php
// CSV exports (UF2-132..139): the shared pieces every export in exportData.php uses. Prints nothing on load.
require_once __DIR__ . '/../database/dbDonations.php';   // donation_valid_date()

/**
 * One cell as text. Plain signed decimals stay as they are, so amounts (including a negative Net on legacy
 * rows) remain numbers in Excel and Google Sheets. Any other text that a spreadsheet would read as a formula
 * gets a leading apostrophe (CSV injection).
 */
function fish_csv_cell($v) {
    if ($v === null) {
        return '';
    }
    $s = (string) $v;
    if (preg_match('/^-?\d+(\.\d+)?\z/', $s)) {
        return $s;
    }
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
}

/**
 * Sends $header and $rows (each an array of cells) as a download named $filename. Every request check
 * must be done before this is called: once it starts, the response is the file. $filename is built by the
 * caller from fixed text and validated values only.
 */
function fish_csv_send($filename, array $header, iterable $rows) {
    ini_set('display_errors', '0');   // a warning while writing rows goes to the log, never into the file
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads names with accents correctly
    $write = function (array $cells) use ($out) {
        fputcsv($out, array_map('fish_csv_cell', $cells), ',', '"', '', "\r\n");
    };
    $write($header);
    foreach ($rows as $row) {
        $write($row);
    }
    fclose($out);
}

/**
 * [$from, $to] when both are valid Y-m-d dates and $from <= $to, else null. Callers read the values with
 * fish_str(), so a non-string arrives here as ''.
 */
function fish_export_dates($from, $to) {
    if (!is_string($from) || !is_string($to) || !donation_valid_date($from) || !donation_valid_date($to) || $from > $to) {
        return null;
    }
    return [$from, $to];
}
