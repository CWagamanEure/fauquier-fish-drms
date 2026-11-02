<?php
// UF2-124: how a non-cash gift's size is shown everywhere ("24 items · 18.5 lbs", or "24 items" for gifts
// recorded before weights were required). Plain text: callers escape it with h(). No includes, so the donor,
// donation and campaign data layers can all load it.

function noncash_num($v) {
    return rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
}

function donation_qty_text($quantity, $weight_lbs) {
    $text = noncash_num($quantity) . ' items';
    if ($weight_lbs !== null && $weight_lbs !== '') {
        $text .= ' · ' . noncash_num($weight_lbs) . ' lbs';
    }
    return $text;
}
