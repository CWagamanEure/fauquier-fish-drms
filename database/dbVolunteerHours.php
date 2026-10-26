<?php
// Volunteer hours data layer for addHours.php and hours.php (UF2-116..120): one entry, its change history,
// the donor side summary, the form (blank / from row / from post / validate) and the writes. Every
// statement is prepared. Entries of the anonymous donor (999) are never written. Write functions return
// false (and log) on a database error.
require_once __DIR__ . '/dbDonations.php';   // donation_valid_date(), donation_today(), donation_campaigns(), donation_recorder_name()
require_once __DIR__ . '/dbDonors.php';      // donor_get(), DONOR_ANONYMOUS_ID

// PR #26's job list; every job already stored is offered too (hours_job_options()).
const HOURS_JOBS = ['Pantry sorting', 'Pantry intake', 'Drop-off table', 'Delivery driver', 'Office help', 'Other'];
const HOURS_NUMBER_PATTERN = '/^\d{1,2}(\.\d{1,2})?$/';
const HOURS_MAX = 24;
const HOURS_EXTRA_MAX = 20;            // "Same shift for more people"
const HOURS_TEXT_MAX = ['location' => 255, 'notes' => 2000];
const HOURS_QUOTE_MAX = 80;            // longest value quoted in a change summary

// 4.00 -> "4", 4.50 -> "4.5", 2.25 -> "2.25". The one hours formatter for the hours pages and the profile.
function hours_label($n) {
    return rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
}

// "09:00" from "09:00", "09:00:00" or "9:00"; '' for ''; null when not a valid 00:00-23:59 time.
function hours_norm_time($s) {
    $s = trim((string) $s);
    if ($s === '') { return ''; }
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $s, $m)) { return null; }
    return sprintf('%02d:%s', (int) $m[1], $m[2]);
}

function hours_minutes($hhmm) {
    return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
}

// Hours as integer hundredths ("4" and "4.00" are both 400); null when not a number.
function hours_hundredths($s) {
    $s = trim((string) $s);
    return preg_match(HOURS_NUMBER_PATTERN, $s) ? (int) round((float) $s * 100) : null;
}

function hours_date_label($ymd) {
    return $ymd === '' ? '—' : date('M j, Y', strtotime($ymd));
}

// ---------- reads ----------

// One entry with its donor, campaign and recorder, or null. Times and NULLs are raw; created_ts is a Unix
// time so PHP formats it in America/New_York whatever the database session zone is.
function hours_get($id) {
    $con = connect();
    $stmt = $con->prepare('SELECT v.id, v.donor_id, v.campaign_id, v.activity_date, v.hours, v.start_time, v.end_time,
                                  v.job, v.location, v.notes, v.is_verified, v.counts_for_letter, v.recorded_by,
                                  UNIX_TIMESTAMP(v.created_at) AS created_ts, d.display_name AS donor_name,
                                  d.is_active AS donor_active, e.name AS campaign, p.first_name, p.last_name
                           FROM volunteer_hours v
                           JOIN donor_summary d ON d.id = v.donor_id
                           LEFT JOIN dbevents e ON e.id = v.campaign_id
                           LEFT JOIN dbpersons p ON p.id = v.recorded_by
                           WHERE v.id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($con);
    if (!$row) { return null; }
    $row['id'] = (int) $row['id'];
    $row['donor_id'] = (int) $row['donor_id'];
    $row['recorder'] = donation_recorder_name($row['first_name'], $row['last_name']);
    return $row;
}

// The change rows of one entry, newest first: ['ts' => Unix time, 'by' => "Maria L." or '', 'summary'].
function hours_changes($id) {
    $con = connect();
    $stmt = $con->prepare('SELECT UNIX_TIMESTAMP(c.changed_at) AS ts, c.summary, p.first_name, p.last_name
                           FROM volunteer_hours_changes c LEFT JOIN dbpersons p ON p.id = c.changed_by
                           WHERE c.volunteer_hours_id = ? ORDER BY c.changed_at DESC, c.id DESC');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = ['ts' => (int) $r['ts'], 'by' => donation_recorder_name($r['first_name'], $r['last_name']), 'summary' => $r['summary']];
    }
    $stmt->close();
    mysqli_close($con);
    return $rows;
}

// Side panel: hours and shifts this calendar year (bounds from PHP's date), the last shift, and the most
// frequent job (ties go to the most recent use).
function hours_summary($donorId) {
    $first = date('Y-01-01');
    $last = date('Y-12-31');
    $con = connect();
    $stmt = $con->prepare('SELECT COALESCE(SUM(CASE WHEN activity_date BETWEEN ? AND ? THEN hours END), 0) AS year_hours,
                                  COALESCE(SUM(activity_date BETWEEN ? AND ?), 0) AS year_shifts,
                                  MAX(activity_date) AS last_shift
                           FROM volunteer_hours WHERE donor_id = ?');
    $stmt->bind_param('ssssi', $first, $last, $first, $last, $donorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $con->prepare("SELECT job FROM volunteer_hours WHERE donor_id = ? AND job IS NOT NULL AND TRIM(job) <> ''
                           GROUP BY job ORDER BY COUNT(*) DESC, MAX(activity_date) DESC, MAX(id) DESC LIMIT 1");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_row();
    $stmt->close();
    mysqli_close($con);
    return ['Hours this year' => hours_label($row['year_hours']), 'Shifts this year' => (string) (int) $row['year_shifts'],
            'Last shift' => $row['last_shift'] === null ? '—' : hours_date_label($row['last_shift']),
            'Usual role' => $job ? (string) $job[0] : '—'];
}

// PR #26's list, then every other non-empty job already stored, so seeded and legacy values stay selectable.
function hours_job_options() {
    $con = connect();
    $stmt = $con->prepare("SELECT DISTINCT TRIM(job) AS job FROM volunteer_hours WHERE job IS NOT NULL AND TRIM(job) <> '' ORDER BY job");
    $stmt->execute();
    $stored = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'job');
    $stmt->close();
    mysqli_close($con);
    return array_values(array_unique(array_merge(HOURS_JOBS, $stored)));
}

// id => name of the open campaigns plus $keepId; no goal or raised money reaches the hours pages.
function hours_campaign_names($keepId = null) {
    return array_map(function ($c) { return $c['name']; }, donation_campaigns($keepId));
}

// ---------- form ----------

function hours_form_blank() {
    return ['date' => donation_today(), 'start' => '', 'end' => '', 'hours' => '', 'job' => '', 'campaign' => '',
            'location' => 'FISH pantry', 'notes' => '', 'verified' => 0, 'letter' => 0, 'extra' => []];
}

// The form-shaped, normalised values of a stored entry (NULL -> '', TIME -> HH:MM, hours and text trimmed),
// matching hours_form_from_post() so an untouched save compares equal.
function hours_form_from_row(array $row) {
    return ['date' => (string) $row['activity_date'], 'start' => (string) hours_norm_time($row['start_time']),
            'end' => (string) hours_norm_time($row['end_time']), 'hours' => hours_label($row['hours']),
            'job' => trim((string) $row['job']), 'campaign' => $row['campaign_id'] === null ? '' : (string) (int) $row['campaign_id'],
            'location' => trim((string) $row['location']), 'notes' => trim(str_replace("\r\n", "\n", (string) $row['notes'])),
            'verified' => (int) $row['is_verified'], 'letter' => (int) $row['counts_for_letter'], 'extra' => []];
}

// Only strings are accepted (arrays become ''), as fish_str() does; notes get LF line ends.
function hours_form_from_post(array $post) {
    $str = function ($k) use ($post) { return isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : ''; };
    $extra = isset($post['extra_donor']) && is_array($post['extra_donor']) ? $post['extra_donor'] : [];
    return ['date' => $str('date'), 'start' => $str('start_time'), 'end' => $str('end_time'), 'hours' => $str('hours'),
            'job' => $str('job'), 'campaign' => $str('campaign_id'), 'location' => $str('location'),
            'notes' => str_replace("\r\n", "\n", $str('notes')),
            'verified' => isset($post['verified']) ? 1 : 0, 'letter' => isset($post['service_letter']) ? 1 : 0,
            'extra' => array_map(function ($v) { return is_string($v) ? trim($v) : ''; }, array_values($extra))];
}

// Validates and normalises $form in place; returns the list of error messages. $stored is the hours_get()
// row when editing (null when adding). On edit a field left exactly as stored (after normalising) passes
// unchanged, so a legacy or imported value never blocks saving another field. $jobs and $campaigns are
// hours_job_options() and hours_campaign_names(). When adding, $mainDonorId is the page's donor and
// $form['extra'] becomes a list of ['id', 'name'] for the valid extra volunteers.
function hours_form_validate(array &$form, ?array $stored, array $jobs, array $campaigns, $mainDonorId = 0) {
    $errors = [];
    $old = $stored ? hours_form_from_row($stored) : null;
    $same = function ($k) use (&$form, $old) { return $old !== null && $form[$k] === $old[$k]; };

    if (!$same('date')) {
        if ($form['date'] === '') { $errors[] = 'Enter the date of the shift.'; }
        elseif (!donation_valid_date($form['date'])) { $errors[] = 'Enter a real date for the shift.'; }
        elseif ($form['date'] > donation_today()) { $errors[] = 'The shift date cannot be in the future.'; }
    }

    $times = [];
    foreach (['start' => 'start', 'end' => 'end'] as $k => $label) {
        $t = hours_norm_time($form[$k]);
        if ($t === null) { $errors[] = "Enter the $label time as HH:MM."; $times[$k] = null; }
        else { $form[$k] = $t; $times[$k] = $t; }
    }
    $span = null;
    if ($times['start'] && $times['end']) {
        $span = hours_minutes($times['end']) - hours_minutes($times['start']);
        if ($span <= 0) {
            $span = null;
            if (!($same('start') && $same('end'))) { $errors[] = 'The end time must be after the start time.'; }
        }
    }

    if ($form['hours'] === '' && $span !== null) {
        $form['hours'] = hours_label(round($span / 60, 2));   // the same rounding as js/volunteerHours.js
    }
    $hundredths = $old !== null && $form['hours'] === $old['hours'] ? (int) round((float) $stored['hours'] * 100) : hours_hundredths($form['hours']);
    // Compared with the stored DECIMAL itself, so a legacy value outside the form's rules (100+) still passes.
    $hoursSame = $old !== null && $hundredths !== null && $hundredths === (int) round((float) $stored['hours'] * 100);
    if ($hoursSame) {
        $form['hours'] = $old['hours'];
    } elseif ($form['hours'] === '') {
        $errors[] = 'Enter the hours worked, or a start and end time.';
    } elseif ($hundredths === null) {
        $errors[] = 'Hours must be a number with at most two decimals, for example 4.5.';
    } elseif ($hundredths <= 0 || $hundredths > HOURS_MAX * 100) {
        $errors[] = 'Hours must be more than 0 and at most ' . HOURS_MAX . '.';
    } else {
        $form['hours'] = hours_label($hundredths / 100);
    }
    // A break makes the hours shorter than the shift, never longer. Compared in whole minutes, so the
    // value filled in from the times (rounded to 0.01 h, at most 0.3 minutes off) always passes.
    if ($span !== null && $hundredths !== null && (int) round($hundredths * 0.6) > $span
        && !($hoursSame && $same('start') && $same('end'))) {
        $errors[] = 'Hours cannot be more than the time between start and end (' . hours_label(round($span / 60, 2)) . ').';
    }

    if ($form['job'] !== '' && !in_array($form['job'], $jobs, true) && !$same('job')) {
        $errors[] = 'Choose a job from the list.';
    }
    if ($form['campaign'] !== '' && !(ctype_digit($form['campaign']) && isset($campaigns[(int) $form['campaign']])) && !$same('campaign')) {
        $errors[] = 'Choose an open campaign, or None.';
    } elseif ($form['campaign'] !== '' && ctype_digit($form['campaign'])) {
        $form['campaign'] = (string) (int) $form['campaign'];   // "07" and "7" are the same campaign
    }
    foreach (HOURS_TEXT_MAX as $k => $max) {
        if (mb_strlen($form[$k], 'UTF-8') > $max && !$same($k)) { $errors[] = ucfirst($k) . " can be at most $max characters."; }
    }

    if ($stored) {
        $form['extra'] = [];
    } else {
        $form['verified'] = $form['letter'] = 0;   // the status boxes are only on the edit page
        $ids = [];
        $bad = 0;
        foreach ($form['extra'] as $v) {
            if (!ctype_digit($v) || (int) $v === 0) { $bad++; continue; }
            if ((int) $v !== (int) $mainDonorId) { $ids[(int) $v] = true; }
        }
        $valid = [];
        if (count($ids) > HOURS_EXTRA_MAX) {
            $errors[] = 'Add at most ' . HOURS_EXTRA_MAX . ' more volunteers to one shift.';
            $ids = [];
        }
        foreach (array_keys($ids) as $id) {
            $d = $id === DONOR_ANONYMOUS_ID ? null : donor_get($id);
            if ($d && (int) $d['is_active'] === 1 && (int) $d['is_anonymous'] === 0) {
                $valid[] = ['id' => $id, 'name' => (string) $d['display_name']];
            } else {
                $bad++;
            }
        }
        if ($bad) { $errors[] = $bad === 1 ? 'One of the extra volunteers could not be added. Remove it and search again.'
                                           : "$bad of the extra volunteers could not be added. Remove them and search again."; }
        $form['extra'] = $valid;
    }
    return $errors;
}

// ---------- writes ----------

const HOURS_COLUMNS = 'activity_date = ?, start_time = ?, end_time = ?, hours = ?, job = ?, campaign_id = ?, location = ?,
                       notes = ?, is_verified = ?, counts_for_letter = ?';
const HOURS_TYPES = 'sssssissii';

function hours_bind_values(array $form) {
    $nul = function ($v) { return $v === '' ? null : $v; };
    return [$form['date'], $nul($form['start']), $nul($form['end']), number_format((float) $form['hours'], 2, '.', ''),
            $nul($form['job']), $form['campaign'] === '' ? null : (int) $form['campaign'], $nul($form['location']),
            $nul($form['notes']), (int) $form['verified'], (int) $form['letter']];
}

// One summary line for an edit, e.g. "Hours changed 4 → 4.5; Job changed A → B"; '' when nothing changed.
// $old and $new are normalised forms; $campaigns maps ids to names.
function hours_change_summary(array $old, array $new, array $campaigns) {
    $q = function ($v) {
        $v = (string) $v;
        return $v === '' ? '—' : (mb_strlen($v, 'UTF-8') > HOURS_QUOTE_MAX ? mb_substr($v, 0, HOURS_QUOTE_MAX - 1, 'UTF-8') . '…' : $v);
    };
    $camp = function ($id) use ($campaigns) { return $id === '' ? 'None' : ($campaigns[(int) $id] ?? 'Campaign #' . (int) $id); };
    $parts = [];
    if (hours_hundredths($old['hours']) !== hours_hundredths($new['hours'])) { $parts[] = 'Hours changed ' . $old['hours'] . ' → ' . $new['hours']; }
    if ($old['date'] !== $new['date']) { $parts[] = 'Date changed ' . hours_date_label($old['date']) . ' → ' . hours_date_label($new['date']); }
    if ($old['start'] !== $new['start']) { $parts[] = 'Start time changed ' . $q($old['start']) . ' → ' . $q($new['start']); }
    if ($old['end'] !== $new['end']) { $parts[] = 'End time changed ' . $q($old['end']) . ' → ' . $q($new['end']); }
    if ($old['job'] !== $new['job']) { $parts[] = 'Job changed ' . $q($old['job']) . ' → ' . $q($new['job']); }
    if ($old['campaign'] !== $new['campaign']) { $parts[] = 'Campaign changed ' . $q($camp($old['campaign'])) . ' → ' . $q($camp($new['campaign'])); }
    if ($old['location'] !== $new['location']) { $parts[] = 'Location changed ' . $q($old['location']) . ' → ' . $q($new['location']); }
    if ($old['notes'] !== $new['notes']) { $parts[] = 'Notes edited'; }
    if ($old['verified'] !== $new['verified']) { $parts[] = $new['verified'] ? 'Marked verified' : 'Unmarked verified'; }
    if ($old['letter'] !== $new['letter']) { $parts[] = $new['letter'] ? 'Counts toward service letter' : 'No longer counts toward service letter'; }
    return implode('; ', $parts);
}

// Inserts one row per volunteer ($donorIds: the page's donor first, then the extra volunteers) in one
// transaction, after re-checking every donor is active and not anonymous. Returns the row count or false.
function hours_create(array $donorIds, array $form, $byUser) {
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $marks = implode(',', array_fill(0, count($donorIds), '?'));
        $stmt = $con->prepare("SELECT COUNT(*) FROM donors WHERE id IN ($marks) AND is_active = 1 AND id <> ? LOCK IN SHARE MODE");
        $args = array_merge(array_map('intval', $donorIds), [DONOR_ANONYMOUS_ID]);
        $stmt->bind_param(str_repeat('i', count($args)), ...$args);
        $stmt->execute();
        $ok = (int) $stmt->get_result()->fetch_row()[0] === count($donorIds);
        $stmt->close();
        if (!$ok) { $con->rollback(); mysqli_close($con); return false; }
        $stmt = $con->prepare('INSERT INTO volunteer_hours SET donor_id = ?, ' . HOURS_COLUMNS . ', recorded_by = ?');
        foreach ($donorIds as $id) {
            $values = array_merge([(int) $id], hours_bind_values($form), [$byUser]);
            $stmt->bind_param('i' . HOURS_TYPES . 's', ...$values);
            $stmt->execute();
        }
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        return count($donorIds);
    } catch (mysqli_sql_exception $e) {
        error_log('hours_create failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (mysqli_sql_exception $ignored) {} }
        return false;
    }
}

// The stored entry locked for this transaction, or null when it is gone or belongs to the anonymous donor.
function hours_lock($con, $id) {
    $stmt = $con->prepare('SELECT id, donor_id, campaign_id, activity_date, hours, start_time, end_time, job, location, notes,
                                  is_verified, counts_for_letter FROM volunteer_hours WHERE id = ? AND donor_id <> ? FOR UPDATE');
    $anon = DONOR_ANONYMOUS_ID;
    $stmt->bind_param('ii', $id, $anon);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Updates one entry and writes its change row in one transaction; the summary is computed from the row as
// locked, so a concurrent edit is described correctly. Writes nothing when no field changed.
// Returns true on success (including no change), false on error or when the entry is gone or anonymous.
function hours_update($id, array $form, $byUser, array $campaigns) {
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $row = hours_lock($con, $id);
        if (!$row) { $con->rollback(); mysqli_close($con); return false; }
        $summary = hours_change_summary(hours_form_from_row($row), $form, $campaigns);
        if ($summary !== '') {
            $stmt = $con->prepare('UPDATE volunteer_hours SET ' . HOURS_COLUMNS . ' WHERE id = ? AND donor_id <> ?');
            $values = array_merge(hours_bind_values($form), [(int) $id, DONOR_ANONYMOUS_ID]);
            $stmt->bind_param(HOURS_TYPES . 'ii', ...$values);
            $stmt->execute();
            $stmt->close();
            $stmt = $con->prepare('INSERT INTO volunteer_hours_changes (volunteer_hours_id, changed_by, summary) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $id, $byUser, $summary);
            $stmt->execute();
            $stmt->close();
        }
        $con->commit();
        mysqli_close($con);
        return true;
    } catch (mysqli_sql_exception $e) {
        error_log('hours_update failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (mysqli_sql_exception $ignored) {} }
        return false;
    }
}

// Deletes one entry (its change rows cascade) and logs what was removed and by whom.
// Returns true on success, false on error or when the entry is gone or anonymous.
function hours_delete($id, $byUser) {
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $row = hours_lock($con, $id);
        if (!$row) { $con->rollback(); mysqli_close($con); return false; }
        error_log(sprintf('volunteer hours entry %d deleted by %s (donor %d, %s, %s hours)',
                          $row['id'], $byUser, $row['donor_id'], $row['activity_date'], $row['hours']));
        $stmt = $con->prepare('DELETE FROM volunteer_hours WHERE id = ? AND donor_id <> ?');
        $anon = DONOR_ANONYMOUS_ID;
        $stmt->bind_param('ii', $id, $anon);
        $stmt->execute();
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        return true;
    } catch (mysqli_sql_exception $e) {
        error_log('hours_delete failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (mysqli_sql_exception $ignored) {} }
        return false;
    }
}
