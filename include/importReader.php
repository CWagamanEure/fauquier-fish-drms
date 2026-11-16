<?php
// Reads an uploaded import file (.csv or .xlsx) into rows of heading => text. Prints nothing on load.
// .xlsx is read with ZipArchive + SimpleXML (no library): the first worksheet, shared strings, and Excel's
// date/time serial numbers turned into text the importer understands. XAMPP: ZipArchive needs
// extension=zip enabled in php.ini; without it, .xlsx files are refused with a message to save as CSV.

const IMPORT_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB
const IMPORT_MAX_ROWS = 2000;
const IMPORT_MAX_COLUMNS = 60;                  // the widest template has 19; anything past this is ignored
const IMPORT_MAX_PART_BYTES = 20 * 1024 * 1024; // one unzipped part of an .xlsx (a 2 MB zip can expand far more)

/**
 * Returns ['headers' => [...], 'rows' => [rowNumber => [heading => text]], 'error' => null|string].
 * Headings are kept as written (trimmed); blank rows are left out; row numbers are the spreadsheet's
 * (the heading row is row 1).
 */
function import_read_file($path, $ext) {
    if ($ext === 'csv') {
        $grid = import_read_csv($path);
    } elseif ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            return ['headers' => [], 'rows' => [], 'error' => 'This server cannot read .xlsx files. In Excel use File > Save As > CSV, then upload the .csv file.'];
        }
        $grid = import_read_xlsx($path);
        if ($grid === false) {
            return ['headers' => [], 'rows' => [], 'error' => 'The file is too large: at most ' . IMPORT_MAX_ROWS . ' rows, and it must be the template saved from Excel or Google Sheets.'];
        }
    } else {
        return ['headers' => [], 'rows' => [], 'error' => 'Upload a .csv or .xlsx file.'];
    }
    if ($grid === null) {
        return ['headers' => [], 'rows' => [], 'error' => 'The file could not be read. Make sure it is the template saved as .xlsx or .csv.'];
    }
    if (!$grid) {
        return ['headers' => [], 'rows' => [], 'error' => 'The file is empty.'];
    }
    $headers = array_map(fn($h) => trim((string) $h), array_shift($grid));
    $rows = [];
    $n = 1;
    foreach ($grid as $cells) {
        $n++;
        $row = [];
        foreach ($headers as $i => $h) {
            if ($h === '') { continue; }
            $row[$h] = trim((string) ($cells[$i] ?? ''));
        }
        if (implode('', $row) === '') { continue; }
        if (count($rows) >= IMPORT_MAX_ROWS) {
            return ['headers' => $headers, 'rows' => [], 'error' => 'A file can have at most ' . IMPORT_MAX_ROWS . ' rows. Split it into smaller files.'];
        }
        $rows[$n] = $row;
    }
    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

/** CSV: a list of rows (lists of cells); a UTF-8 byte-order mark (Excel's "CSV UTF-8") is dropped. */
function import_read_csv($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) { return null; }
    $grid = [];
    while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (!$grid && isset($cells[0])) { $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]); }
        $grid[] = $cells;
    }
    fclose($fh);
    return $grid;
}

/**
 * XLSX: the first worksheet as a list of rows. Numbers formatted as dates or times come back as text.
 * Returns null when the file cannot be read, false when it is too large: any part over
 * IMPORT_MAX_PART_BYTES unzipped, or a cell below row IMPORT_MAX_ROWS + 1 (cell positions come from the file,
 * so they are checked before anything is built from them).
 */
function import_read_xlsx($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return null; }
    $tooBig = false;
    $read = function ($name) use ($zip, &$tooBig) {
        $stat = $zip->statName($name);
        if ($stat === false) { return null; }
        if ($stat['size'] > IMPORT_MAX_PART_BYTES) { $tooBig = true; return null; }
        $xml = $zip->getFromName($name, IMPORT_MAX_PART_BYTES);
        if ($xml === false) { return null; }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);   // no network, no external entities
        libxml_use_internal_errors($prev);
        return $doc === false ? null : $doc;
    };

    // First sheet's file from the workbook relationships (usually xl/worksheets/sheet1.xml).
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $read('xl/workbook.xml');
    $rels = $read('xl/_rels/workbook.xml.rels');
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');
                $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
            }
        }
    }

    $shared = [];
    if ($ss = $read('xl/sharedStrings.xml')) {
        foreach ($ss->si as $si) {
            $text = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $run) { $text .= (string) $run->t; }
            $shared[] = $text;
        }
    }

    // Which cell styles are dates or times (built-in formats 14-22 and 45-47, or custom ones with d/m/y/h/s).
    $dateStyles = [];
    if ($st = $read('xl/styles.xml')) {
        $custom = [];
        if (isset($st->numFmts)) {
            foreach ($st->numFmts->numFmt as $f) { $custom[(int) $f['numFmtId']] = (string) $f['formatCode']; }
        }
        $i = 0;
        foreach ($st->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $code = $custom[$id] ?? '';
            $isDate = ($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)
                   || ($code !== '' && preg_match('/[dmyhs]/i', preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code)));
            if ($isDate) { $dateStyles[$i] = (strpos(strtolower($code), 'h') !== false || in_array($id, [18, 19, 20, 21, 45, 46, 47], true)) && !preg_match('/[dy]/i', $code) ? 'time' : 'date'; }
            $i++;
        }
    }

    $sheet = $read($sheetPath);
    $zip->close();
    if ($tooBig) { return false; }
    if (!$sheet) { return null; }

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int) $row['r'] - 1;
        if ($r < 0) { continue; }
        if ($r > IMPORT_MAX_ROWS) { return false; }
        foreach ($row->c as $c) {
            $col = import_xlsx_col(preg_replace('/\d+/', '', (string) $c['r']));
            if ($col < 0 || $col >= IMPORT_MAX_COLUMNS) { continue; }
            $type = (string) $c['t'];
            if ($type === 's') {
                $val = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) ($c->is->t ?? '');
            } else {
                $val = (string) $c->v;
                $style = (int) $c['s'];
                if ($val !== '' && is_numeric($val) && isset($dateStyles[$style])) {
                    $val = import_excel_serial($val, $dateStyles[$style]);
                }
            }
            $grid[$r][$col] = $val;
        }
    }
    if (!$grid) { return []; }
    $out = [];
    for ($r = 0, $max = max(array_keys($grid)); $r <= $max; $r++) {
        $cells = $grid[$r] ?? [];
        $row = [];
        for ($c = 0, $cmax = $cells ? max(array_keys($cells)) : -1; $c <= $cmax; $c++) { $row[] = $cells[$c] ?? ''; }
        $out[] = $row;
    }
    return $out;
}

/** "A" -> 0, "Z" -> 25, "AA" -> 26. */
function import_xlsx_col($letters) {
    $letters = strtoupper($letters);
    if ($letters === '' || strlen($letters) > 3 || !ctype_alpha($letters)) { return -1; }
    $n = 0;
    foreach (str_split($letters) as $ch) { $n = $n * 26 + (ord($ch) - 64); }
    return $n - 1;
}

/** Excel serial (days since 1899-12-30) as "MM/DD/YYYY", or a day fraction as "HH:MM". */
function import_excel_serial($serial, $kind) {
    $f = (float) $serial;
    if ($kind === 'time' || $f < 1) {
        $mins = (int) round(($f - floor($f)) * 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }
    $d = (new DateTime('1899-12-30'))->modify('+' . (int) floor($f) . ' days');
    return $d->format('m/d/Y');
}
