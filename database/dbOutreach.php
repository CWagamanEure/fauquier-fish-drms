<?php
// Outreach data layer (social posts and mass mailings): the form (blank / from post / validate), the lookups it is
// checked against, the photo check, the transactional writes, the list and the private photo download. Every
// statement is prepared. Outreach is not tied to a donor. Writes return false (and log) on a database or file error.
require_once __DIR__ . '/dbinfo.php';
require_once __DIR__ . '/dbDonations.php';   // donation_valid_date(), donation_today(), donation_recorder_name()
require_once __DIR__ . '/dbDonors.php';      // donor_like_escape()

const OUTREACH_CHANNELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X / Twitter',
                           'linkedin' => 'LinkedIn', 'mass_email' => 'Mass email', 'newsletter' => 'Newsletter'];
const OUTREACH_PERSON_OTHER = '__other__';
const OUTREACH_PHOTO_MAX = 5242880;          // 5 MB; PHP's upload_max_filesize and post_max_size must be at least this
const OUTREACH_PHOTO_PIXELS = 10000;         // longest side
const OUTREACH_PHOTO_TYPES = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png'],
                              IMAGETYPE_WEBP => ['image/webp', 'webp']];
const OUTREACH_TEXT_MAX = ['caption' => 5000, 'url' => 500, 'person_other' => 255];
const OUTREACH_COUNT_MAX = 2000000000;
const OUTREACH_COUNT_LABELS = ['reach' => 'Reach', 'likes' => 'Likes', 'shares' => 'Shares', 'comments' => 'Comments'];
const OUTREACH_PER_PAGE = 10;
const OUTREACH_TAB_LIMIT = 50;
const OUTREACH_EMAIL_CHANNELS = ['mass_email', 'newsletter'];
const OUTREACH_SORTS = ['newest' => 'Sort: Newest', 'oldest' => 'Sort: Oldest', 'campaign' => 'Sort: Campaign', 'reach' => 'Sort: Reach'];

function outreach_dir() {
    return dirname(__DIR__) . '/uploads/outreach';
}

// String-compared key test: PHP turns numeric string keys into integers, so in_array/array_key_exists on raw
// posted values would let "0" or "07" through as list positions.
function outreach_in_map(array $map, $key) {
    foreach (array_keys($map) as $k) { if ((string) $k === (string) $key) { return true; } }
    return false;
}

function outreach_stored_name_ok($name) {
    return (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', (string) $name);
}

function outreach_unlink_stored($name) {
    $path = outreach_dir() . '/' . $name;
    if (outreach_stored_name_ok($name) && is_file($path)) { @unlink($path); }
}

// id => name of the active campaigns only (campaign_summary.status = 'active').
function outreach_campaign_options() {
    $con = connect();
    $stmt = $con->prepare("SELECT id, name FROM campaign_summary WHERE status = 'active' ORDER BY name, id");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return array_column($rows, 'name', 'id');
}

// ---------- the add form ----------

function outreach_form_blank($myId) {
    return ['channels' => [], 'urls' => [], 'campaign' => '', 'posted_on' => donation_today(), 'posted_by' => (string) $myId,
            'posted_by_other' => '', 'caption' => '',
            'reach' => '', 'likes' => '', 'shares' => '', 'comments' => ''];
}

// The form array from a POST; only strings are accepted, arrays become ''. channel[] may repeat;
// channel_url[<channel>] holds one link per channel.
function outreach_form_from_post(array $post) {
    $str = function ($k) use ($post) { return isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : ''; };
    $channels = [];
    if (isset($post['channel']) && is_array($post['channel'])) {
        foreach ($post['channel'] as $c) { if (is_string($c)) { $channels[] = trim($c); } }
    }
    $urls = [];
    if (isset($post['channel_url']) && is_array($post['channel_url'])) {
        foreach ($post['channel_url'] as $k => $v) { if (is_string($k) && is_string($v)) { $urls[$k] = trim($v); } }
    }
    return ['channels' => array_values(array_unique($channels)), 'urls' => $urls, 'campaign' => $str('campaign_id'),
            'posted_on' => $str('posted_on'), 'posted_by' => $str('posted_by'), 'posted_by_other' => $str('posted_by_other'),
            'caption' => str_replace("\r\n", "\n", $str('caption')),
            'reach' => $str('reach'), 'likes' => $str('likes'), 'shares' => $str('shares'), 'comments' => $str('comments')];
}

// Validates and normalises $form in place against the lists the form offered (id => name); returns the list of
// error messages. Sets $form['posted_by_staff'] to whether a staff account was picked. Links typed for channels
// that are not ticked are dropped; links for ticked channels are kept as typed so an error page shows them again.
function outreach_form_validate(array &$form, array $staff, array $campaigns) {
    $errors = [];
    $valid = array_values(array_filter($form['channels'], function ($c) { return array_key_exists($c, OUTREACH_CHANNELS); }));
    if (count($valid) !== count($form['channels'])) { $errors[] = 'Choose a channel from the list.'; }
    elseif (!$valid) { $errors[] = 'Choose at least one channel.'; }
    $form['channels'] = $valid;

    $kept = [];
    foreach ($valid as $c) {
        $u = $form['urls'][$c] ?? '';
        if ($u === '') { continue; }
        $kept[$c] = $u;
        $label = OUTREACH_CHANNELS[$c];
        if (mb_strlen($u, 'UTF-8') > OUTREACH_TEXT_MAX['url']) {
            $errors[] = "$label link must be " . OUTREACH_TEXT_MAX['url'] . ' characters or fewer.';
        } elseif (!preg_match('#^https?://#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
            $errors[] = "$label: enter a full link starting with http:// or https://.";
        }
    }
    $form['urls'] = $kept;

    if (!donation_valid_date($form['posted_on'])) {
        $errors[] = 'Enter the post date.';
    } elseif ($form['posted_on'] > date('Y-m-d', strtotime('+1 year'))) {
        $errors[] = 'The post date cannot be more than a year ahead.';
    }

    $form['posted_by_staff'] = $form['posted_by'] !== '' && outreach_in_map($staff, $form['posted_by']);
    if ($form['posted_by_staff'] || $form['posted_by'] === '') {
        $form['posted_by_other'] = '';
    } elseif ($form['posted_by'] === OUTREACH_PERSON_OTHER) {
        if ($form['posted_by_other'] === '') {
            $errors[] = 'Type the name of who posted it, or pick one from the list.';
        } elseif (mb_strlen($form['posted_by_other'], 'UTF-8') > OUTREACH_TEXT_MAX['person_other']) {
            $errors[] = 'The name of who posted it must be ' . OUTREACH_TEXT_MAX['person_other'] . ' characters or fewer.';
        }
    } else {
        $errors[] = 'Choose who posted it from the list.';
        $form['posted_by'] = '';
    }

    if ($form['campaign'] !== '' && (!ctype_digit($form['campaign']) || !outreach_in_map($campaigns, $form['campaign']))) {
        $errors[] = 'Choose an active campaign from the list, or None.';
        $form['campaign'] = '';
    }
    if (mb_strlen($form['caption'], 'UTF-8') > OUTREACH_TEXT_MAX['caption']) {
        $errors[] = 'Caption must be ' . OUTREACH_TEXT_MAX['caption'] . ' characters or fewer.';
    }
    foreach (OUTREACH_COUNT_LABELS as $k => $label) {
        if ($form[$k] === '') { continue; }
        $clean = str_replace([',', ' '], '', $form[$k]);
        if (!preg_match('/^\d{1,10}$/', $clean) || (int) $clean > OUTREACH_COUNT_MAX) {
            $errors[] = "$label must be a whole number of 0 or more.";
        } else {
            $form[$k] = (string) (int) $clean;
        }
    }
    return $errors;
}

// ---------- photo ----------

// Checks one uploaded photo ($_FILES['photo'] or null). Returns [photo|null, error|null]; no file chosen is
// [null, null]. The content is checked, not the file name or the browser's type: only real JPG, PNG and WEBP images.
function outreach_photo_check($file) {
    $retry = 'The photo could not be uploaded. Please choose it again.';
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) { return [null, null]; }
    switch ((int) $file['error']) {
        case UPLOAD_ERR_NO_FILE: return [null, null];
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return [null, 'The photo is too large (5 MB at most).'];
        default: return [null, $retry];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { return [null, $retry]; }
    $size = filesize($tmp);
    if ($size === false || $size <= 0) { return [null, $retry]; }
    if ($size > OUTREACH_PHOTO_MAX) { return [null, 'The photo is too large (5 MB at most).']; }
    $info = @getimagesize($tmp);
    if (!$info || !isset(OUTREACH_PHOTO_TYPES[$info[2]])) { return [null, 'The photo must be a JPG, PNG or WEBP image.']; }
    if ($info[0] > OUTREACH_PHOTO_PIXELS || $info[1] > OUTREACH_PHOTO_PIXELS) { return [null, 'The photo is too large in pixels (10,000 on the longest side at most).']; }
    [$mime, $ext] = OUTREACH_PHOTO_TYPES[$info[2]];
    $original = preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', (string) ($file['name'] ?? 'photo'))));
    $original = mb_substr($original === '' ? 'photo' : $original, 0, 255, 'UTF-8');
    return [['tmp' => $tmp, 'mime' => $mime, 'ext' => $ext, 'size' => (int) $size, 'original' => $original], null];
}

// Moves the upload into the private folder and inserts its row, inside the caller's transaction. Sets $dest to the
// moved file so the caller can remove it if a later step fails.
function outreach_store_photo(mysqli $con, $id, array $photo, &$dest) {
    $dir = outreach_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { throw new RuntimeException('cannot create ' . $dir); }
    $name = bin2hex(random_bytes(16)) . '.' . $photo['ext'];
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($photo['tmp'], $path)) { throw new RuntimeException('cannot store the photo'); }
    $dest = $path;
    $stmt = $con->prepare('INSERT INTO outreach_files (outreach_id, stored_name, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('isssi', $id, $name, $photo['original'], $photo['mime'], $photo['size']);
    $stmt->execute();
    $stmt->close();
}

function outreach_file_names(mysqli $con, $id) {
    $stmt = $con->prepare('SELECT stored_name FROM outreach_files WHERE outreach_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'stored_name');
    $stmt->close();
    return $names;
}

// Adds a photo to a post, or replaces its current one. The new file is moved in and the old rows deleted in one
// transaction; the old file is removed from disk only after the commit. Returns true, or false on any failure
// (the post is unchanged and no stray file is left).
function outreach_set_photo($id, array $photo) {
    $con = null;
    $dest = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $stmt = $con->prepare('SELECT id FROM outreach WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if (!$exists) { $con->rollback(); mysqli_close($con); return false; }
        $old = outreach_file_names($con, $id);
        $stmt = $con->prepare('DELETE FROM outreach_files WHERE outreach_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        outreach_store_photo($con, $id, $photo, $dest);
        $con->commit();
        mysqli_close($con);
        foreach ($old as $name) { outreach_unlink_stored($name); }
        return true;
    } catch (Throwable $e) {
        error_log('outreach_set_photo failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (Throwable $ignored) {} }
        if ($dest !== null && is_file($dest)) { @unlink($dest); }
        return false;
    }
}

// Removes a post's photo (row, then file). Returns true when a photo was removed.
function outreach_remove_photo($id) {
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $names = outreach_file_names($con, $id);
        $stmt = $con->prepare('DELETE FROM outreach_files WHERE outreach_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        foreach ($names as $name) { outreach_unlink_stored($name); }
        return count($names) > 0;
    } catch (Throwable $e) {
        error_log('outreach_remove_photo failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (Throwable $ignored) {} }
        return false;
    }
}

// ---------- create ----------

// Inserts the post, its channels (each with its own link) and its photo in one transaction. The photo is moved into
// place before the commit; on any failure the transaction rolls back and the moved file is removed. $form must have
// passed outreach_form_validate(). Returns the new id or false.
function outreach_create(array $form, ?array $photo, array $staff, $byUser) {
    $con = null;
    $dest = null;
    $postedBy = !empty($form['posted_by_staff']) ? $form['posted_by'] : null;
    $postedName = $postedBy === null && $form['posted_by'] === OUTREACH_PERSON_OTHER ? $form['posted_by_other'] : null;
    $campaignId = $form['campaign'] === '' ? null : (int) $form['campaign'];
    $caption = $form['caption'] === '' ? null : $form['caption'];
    $counts = [];
    foreach (array_keys(OUTREACH_COUNT_LABELS) as $k) { $counts[$k] = $form[$k] === '' ? null : (int) $form[$k]; }
    try {
        $con = connect();
        $con->begin_transaction();
        if ($campaignId !== null) {   // still active when saved
            $stmt = $con->prepare("SELECT 1 FROM campaign_summary WHERE id = ? AND status = 'active'");
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $ok = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$ok) { $con->rollback(); mysqli_close($con); return false; }
        }
        $stmt = $con->prepare('INSERT INTO outreach (campaign_id, posted_on, posted_by, posted_by_name, caption,
                                   reach, likes, shares, comments, recorded_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssiiiis', $campaignId, $form['posted_on'], $postedBy, $postedName, $caption,
                          $counts['reach'], $counts['likes'], $counts['shares'], $counts['comments'], $byUser);
        $stmt->execute();
        $id = (int) $con->insert_id;
        $stmt->close();

        $stmt = $con->prepare('INSERT INTO outreach_channels (outreach_id, channel, post_url) VALUES (?, ?, ?)');
        $channel = '';
        $chanUrl = null;
        $stmt->bind_param('iss', $id, $channel, $chanUrl);
        foreach ($form['channels'] as $channel) {
            $chanUrl = ($form['urls'][$channel] ?? '') === '' ? null : $form['urls'][$channel];
            $stmt->execute();
        }
        $stmt->close();

        if ($photo !== null) { outreach_store_photo($con, $id, $photo, $dest); }
        $con->commit();
        mysqli_close($con);
        return $id;
    } catch (Throwable $e) {
        error_log('outreach_create failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (Throwable $ignored) {} }
        if ($dest !== null && is_file($dest)) { @unlink($dest); }
        return false;
    }
}

// Streams one stored photo (outreach_files.id) to a signed-in account with communications view, and ends the
// request. 403 without the permission, 404 for an unknown id or a file that is gone. Never takes a path from the request.
function outreach_send_file($fileId) {
    if (!fish_can('communications', 'view')) {
        http_response_code(403);
        exit('You do not have access to outreach photos.');
    }
    $row = null;
    if ($fileId > 0) {
        $con = connect();
        $stmt = $con->prepare('SELECT stored_name, mime_type FROM outreach_files WHERE id = ?');
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        mysqli_close($con);
    }
    $path = $row && outreach_stored_name_ok($row['stored_name']) ? outreach_dir() . '/' . $row['stored_name'] : '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: ' . $row['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    header('Content-Length: ' . filesize($path));
    session_write_close();
    readfile($path);
    exit;
}

// ---------- list, view, results and delete ----------

// An item whose every channel is an email channel is shown as an email, anything else as a post.
function outreach_is_email(array $channels) {
    return $channels && !array_diff($channels, OUTREACH_EMAIL_CHANNELS);
}

// "facebook,x" -> ['Facebook', 'X / Twitter']
function outreach_channel_labels($csv) {
    $out = [];
    foreach (array_filter(explode(',', (string) $csv)) as $c) { $out[] = OUTREACH_CHANNELS[$c] ?? $c; }
    return $out;
}

// Campaigns that have at least one post, for the list's Campaign filter (id => name).
function outreach_filter_campaigns() {
    $con = connect();
    $stmt = $con->prepare('SELECT DISTINCT e.id, e.name FROM outreach o JOIN dbevents e ON e.id = o.campaign_id ORDER BY e.name, e.id');
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    mysqli_close($con);
    return array_column($rows, 'name', 'id');
}

// WHERE clause and bound values shared by the list and its count. The count query must join dbpersons as p.
function outreach_where($channel, $campaign, $q, $from = '', $to = '') {
    $where = ['1 = 1'];
    $types = '';
    $values = [];
    if ($channel !== '' && array_key_exists($channel, OUTREACH_CHANNELS)) {
        $where[] = 'EXISTS (SELECT 1 FROM outreach_channels x WHERE x.outreach_id = o.id AND x.channel = ?)';
        $types .= 's';
        $values[] = $channel;
    }
    if ($campaign !== '' && ctype_digit($campaign)) {
        $where[] = 'o.campaign_id = ?';
        $types .= 'i';
        $values[] = (int) $campaign;
    }
    if ($from !== '') { $where[] = 'o.posted_on >= ?'; $types .= 's'; $values[] = $from; }
    if ($to !== '')   { $where[] = 'o.posted_on <= ?'; $types .= 's'; $values[] = $to; }
    if ($q !== '') {
        $like = '%' . donor_like_escape($q) . '%';
        $where[] = "(o.caption LIKE ? ESCAPE '\\\\' OR o.posted_by_name LIKE ? ESCAPE '\\\\'
                     OR TRIM(CONCAT_WS(' ', p.first_name, p.last_name)) LIKE ? ESCAPE '\\\\')";
        $types .= 'sss';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
    }
    return [implode(' AND ', $where), $types, $values];
}

// One page of posts: id, date, caption, reach, campaign, by, channels (labels). Unknown sort falls back to newest;
// an invalid date is ignored and a reversed range is swapped. Returns the effective filters too.
function outreach_list($channel, $campaign, $q, $page, $from = '', $to = '', $sort = 'newest') {
    $from = donation_valid_date($from) ? $from : '';
    $to = donation_valid_date($to) ? $to : '';
    if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }
    $sort = array_key_exists($sort, OUTREACH_SORTS) ? $sort : 'newest';
    $orderBy = ['newest' => 'o.posted_on DESC, o.id DESC',
                'oldest' => 'o.posted_on ASC, o.id ASC',
                'campaign' => 'e.name IS NULL, e.name ASC, o.posted_on DESC, o.id DESC',
                'reach' => 'o.reach IS NULL, o.reach DESC, o.posted_on DESC, o.id DESC'][$sort];
    [$where, $types, $values] = outreach_where($channel, $campaign, $q, $from, $to);
    $con = connect();
    $stmt = $con->prepare("SELECT COUNT(*) FROM outreach o LEFT JOIN dbpersons p ON p.id = o.posted_by WHERE $where");
    if ($types !== '') { $stmt->bind_param($types, ...$values); }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $pages = max(1, (int) ceil($total / OUTREACH_PER_PAGE));
    $page = min(max(1, (int) $page), $pages);
    $offset = ($page - 1) * OUTREACH_PER_PAGE;
    $limit = OUTREACH_PER_PAGE;
    $stmt = $con->prepare("SELECT o.id, o.posted_on, o.caption, o.reach, e.name AS campaign,
                                  COALESCE(o.posted_by_name, NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), '')) AS by_name,
                                  (SELECT GROUP_CONCAT(c.channel ORDER BY c.channel) FROM outreach_channels c WHERE c.outreach_id = o.id) AS channels
                           FROM outreach o
                           LEFT JOIN dbevents e ON e.id = o.campaign_id
                           LEFT JOIN dbpersons p ON p.id = o.posted_by
                           WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?");
    $allTypes = $types . 'ii';
    $allValues = array_merge($values, [$limit, $offset]);
    $stmt->bind_param($allTypes, ...$allValues);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = ['id' => (int) $r['id'], 'date' => (string) $r['posted_on'], 'caption' => (string) $r['caption'],
                   'reach' => $r['reach'] === null ? null : (int) $r['reach'], 'campaign' => (string) $r['campaign'],
                   'by' => (string) $r['by_name'], 'channels' => array_filter(explode(',', (string) $r['channels'])),
                   'labels' => outreach_channel_labels($r['channels'])];
    }
    $stmt->close();
    mysqli_close($con);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => OUTREACH_PER_PAGE,
            'from' => $from, 'to' => $to, 'sort' => $sort];
}

// Posts linked to one campaign (latest OUTREACH_TAB_LIMIT) and their total, for campaign.php.
function outreach_for_campaign($campaignId) {
    $id = (int) $campaignId;
    $con = connect();
    $stmt = $con->prepare('SELECT COUNT(*) FROM outreach WHERE campaign_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $limit = OUTREACH_TAB_LIMIT;
    $stmt = $con->prepare('SELECT o.id, o.posted_on, o.caption, o.reach,
                                  (SELECT GROUP_CONCAT(c.channel ORDER BY c.channel) FROM outreach_channels c WHERE c.outreach_id = o.id) AS channels
                           FROM outreach o WHERE o.campaign_id = ? ORDER BY o.posted_on DESC, o.id DESC LIMIT ?');
    $stmt->bind_param('ii', $id, $limit);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = ['id' => (int) $r['id'], 'date' => (string) $r['posted_on'], 'caption' => (string) $r['caption'],
                   'reach' => $r['reach'] === null ? null : (int) $r['reach'], 'labels' => outreach_channel_labels($r['channels'])];
    }
    $stmt->close();
    mysqli_close($con);
    return ['rows' => $rows, 'total' => $total];
}

// One post with its channels, per-channel links ('links': channel => url) and photo, or null.
function outreach_get($id) {
    $con = connect();
    $stmt = $con->prepare("SELECT o.id, o.campaign_id, e.name AS campaign, o.posted_on, o.caption,
                                  o.reach, o.likes, o.shares, o.comments, UNIX_TIMESTAMP(o.created_at) AS created_ts,
                                  COALESCE(o.posted_by_name, NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), '')) AS by_name,
                                  rp.first_name AS rec_first, rp.last_name AS rec_last
                           FROM outreach o
                           LEFT JOIN dbevents e ON e.id = o.campaign_id
                           LEFT JOIN dbpersons p ON p.id = o.posted_by
                           LEFT JOIN dbpersons rp ON rp.id = o.recorded_by
                           WHERE o.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { mysqli_close($con); return null; }
    $stmt = $con->prepare('SELECT channel, post_url FROM outreach_channels WHERE outreach_id = ? ORDER BY channel');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row['channels'] = [];
    $row['links'] = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $row['channels'][] = $c['channel'];
        if ($c['post_url'] !== null && $c['post_url'] !== '' && preg_match('#^https?://#i', $c['post_url'])) { $row['links'][$c['channel']] = $c['post_url']; }
    }
    $stmt->close();
    $stmt = $con->prepare('SELECT id, original_name FROM outreach_files WHERE outreach_id = ? ORDER BY id LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row['file'] = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    mysqli_close($con);
    $row['id'] = (int) $row['id'];
    $row['recorder'] = donation_recorder_name($row['rec_first'], $row['rec_last']);
    return $row;
}

// Validates posted results (reach, likes, shares, comments) in place; same rule as the add form.
function outreach_counts_validate(array &$vals) {
    $errors = [];
    foreach (OUTREACH_COUNT_LABELS as $k => $label) {
        $vals[$k] = $vals[$k] ?? '';
        if ($vals[$k] === '') { continue; }
        $clean = str_replace([',', ' '], '', $vals[$k]);
        if (!preg_match('/^\d{1,10}$/', $clean) || (int) $clean > OUTREACH_COUNT_MAX) {
            $errors[] = "$label must be a whole number of 0 or more.";
        } else {
            $vals[$k] = (string) (int) $clean;
        }
    }
    return $errors;
}

// Saves the four result counts (a blank clears one). Returns true, or false on an error.
function outreach_update_results($id, array $vals) {
    $n = function ($k) use ($vals) { return ($vals[$k] ?? '') === '' ? null : (int) $vals[$k]; };
    $reach = $n('reach'); $likes = $n('likes'); $shares = $n('shares'); $comments = $n('comments');
    try {
        $con = connect();
        $stmt = $con->prepare('UPDATE outreach SET reach = ?, likes = ?, shares = ?, comments = ? WHERE id = ?');
        $stmt->bind_param('iiiii', $reach, $likes, $shares, $comments, $id);
        $stmt->execute();
        $ok = $stmt->errno === 0;
        $stmt->close();
        mysqli_close($con);
        return $ok;
    } catch (mysqli_sql_exception $e) {
        error_log('outreach_update_results failed: ' . $e->getMessage());
        return false;
    }
}

// Deletes a post (channels and file rows cascade), then removes its photo files from disk.
// Returns true when a row was deleted.
function outreach_delete($id) {
    $con = null;
    try {
        $con = connect();
        $con->begin_transaction();
        $names = outreach_file_names($con, $id);
        $stmt = $con->prepare('DELETE FROM outreach WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        $con->commit();
        mysqli_close($con);
        if ($deleted) { foreach ($names as $name) { outreach_unlink_stored($name); } }
        return $deleted;
    } catch (mysqli_sql_exception $e) {
        error_log('outreach_delete failed: ' . $e->getMessage());
        if ($con instanceof mysqli) { try { $con->rollback(); } catch (Throwable $ignored) {} }
        return false;
    }
}