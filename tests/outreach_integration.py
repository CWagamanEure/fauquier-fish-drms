"""Outreach posts (the outreach branch of addCommunication.php): channels, posted-by, active campaigns, the photo
upload and its private download, against the real tables. Run via `make test-outreach`. The suite creates and removes
its own users, campaigns, outreach rows and photo files; nothing else is asserted on."""

import base64
import html
import json
import re
import secrets
import subprocess
import sys
import unittest
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

OTHER = "__other__"
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==")
GIF = (b"GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,"
       b"\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;")
UPLOAD_DIR = ROOT / "uploads" / "outreach"

FIXTURES_PHP = r"""
require 'database/dbinfo.php';
$req = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$con = connect();
$con->begin_transaction();
$out = null;
$run = function ($sql, $types = '', ...$values) use ($con) {
    $stmt = $con->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$values); }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : null;
    $stmt->close();
    return $rows;
};
switch ($req['action']) {

    case 'post':
        $run('INSERT INTO outreach (campaign_id, posted_on, posted_by_name, post_url, caption, reach, likes) VALUES (?, ?, ?, ?, ?, ?, ?)',
                'issssii', $req['campaign'], $req['date'], $req['by'], $req['url'], $req['caption'], $req['reach'], $req['likes']);
        $id = (int) $con->insert_id;
        foreach ($req['channels'] as $c) { $run('INSERT INTO outreach_channels (outreach_id, channel) VALUES (?, ?)', 'is', $id, $c); }
        $out = ['id' => $id, 'file' => null];
        if (!empty($req['file'])) {
            file_put_contents('uploads/outreach/' . $req['file'], base64_decode($req['data']));
            $run('INSERT INTO outreach_files (outreach_id, stored_name, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?)',
                    'isssi', $id, $req['file'], 'seed.png', 'image/png', strlen(base64_decode($req['data'])));
            $out['file'] = (int) $con->insert_id;
        }
        break;
    case 'users_create':
        foreach ($req['users'] as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $run('INSERT INTO dbpersons (id, name, first_name, last_name, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                 'sssssss', $u['id'], $u['name'], $u['first'], $u['last'], $hash, $u['role'], $u['status']);
        }
        break;
    case 'users_delete':
        foreach ($req['users'] as $u) {
            $run('DELETE FROM user_permissions WHERE user_id = ?', 's', $u['id']);
            $run('DELETE FROM dbpersons WHERE id = ?', 's', $u['id']);
        }
        break;
    case 'permission':
        $run('INSERT INTO user_permissions (user_id, area, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)',
             'ssiii', $req['user_id'], $req['area'], $req['can_view'], $req['can_edit'], $req['can_delete']);
        break;
    case 'setup':
        $m = $req['marker'];
        $day = function ($n) { return date('Y-m-d', strtotime("$n days")); };
        $campaign = function ($name, $start, $end, $completed) use ($run, $con) {
            $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, startTime, endTime, description, completed, location)
                  VALUES (?, 0, ?, ?, '00:00', '23:59', 'outreach suite', ?, 'FISH pantry')", 'ssss', $name, $start, $end, $completed);
            return (int) $con->insert_id;
        };
        $out = ['open' => $campaign('Open out ' . $m, $day(-5), $day(20), '0'),
                'upcoming' => $campaign('Upcoming out ' . $m, $day(10), $day(40), '0'),
                'done' => $campaign('Done out ' . $m, $day(-60), $day(60), '1'),
                'ended' => $campaign('Ended out ' . $m, $day(-30), $day(-5), '0')];
        break;
    case 'teardown':
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM dbevents WHERE id = ?', 'i', $id); }
        break;
    case 'clean':
        // Remove this suite's posts (captions start with the marker) and their photo files.
        foreach ($run('SELECT f.stored_name FROM outreach_files f JOIN outreach o ON o.id = f.outreach_id WHERE o.caption LIKE ?', 's', $req['marker'] . '%') as $f) {
            @unlink('uploads/outreach/' . $f['stored_name']);
        }
        $run('DELETE FROM outreach WHERE caption LIKE ?', 's', $req['marker'] . '%');
        break;
    case 'rows':
        $out = $run('SELECT id, campaign_id, posted_on, posted_by, posted_by_name, post_url, caption, reach, likes, shares, comments, recorded_by
                     FROM outreach WHERE caption LIKE ? ORDER BY id', 's', $req['marker'] . '%');
        foreach ($out as &$r) {
            $r['channels'] = array_column($run('SELECT channel FROM outreach_channels WHERE outreach_id = ? ORDER BY channel', 'i', $r['id']), 'channel');
            $r['files'] = $run('SELECT id, stored_name, original_name, mime_type, size_bytes FROM outreach_files WHERE outreach_id = ?', 'i', $r['id']);
        }
        unset($r);
        break;
    case 'counts':
        $disk = is_dir('uploads/outreach') ? count(array_diff(scandir('uploads/outreach'), ['.', '..', '.htaccess', '.gitkeep'])) : 0;
        $out = ['outreach' => (int) $run('SELECT COUNT(*) AS n FROM outreach')[0]['n'],
                'channels' => (int) $run('SELECT COUNT(*) AS n FROM outreach_channels')[0]['n'],
                'files' => (int) $run('SELECT COUNT(*) AS n FROM outreach_files')[0]['n'], 'disk' => $disk];
        break;
    case 'today':
        $out = ['today' => date('Y-m-d'), 'far' => date('Y-m-d', strtotime('+2 years'))];
        break;
}
$con->commit();
$con->close();
echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def fixture(action, **payload):
    result = subprocess.run(
        ["php", "-d", "display_errors=1", "-r", FIXTURES_PHP],
        input=json.dumps({"action": action, **payload}),
        text=True,
        capture_output=True
    )
    result.check_returncode()

    return json.loads(result.stdout)


def options(body, select_id):
    """value => label of a <select>'s options."""
    block = re.search(r'<select id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', body, re.S)
    assert block, select_id
    return {html.unescape(v): html.unescape(re.sub(r"<[^>]+>", "", t)).strip()
            for v, t in re.findall(r'<option value="([^"]*)"[^>]*>(.*?)</option>', block.group(1), re.S)}


def selected(body, select_id):
    block = re.search(r'<select id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', body, re.S)
    match = re.search(r'<option value="([^"]*)" selected', block.group(1))
    return html.unescape(match.group(1)) if match else None


def multipart(pairs, file=None):
    """A multipart/form-data body from (name, value) pairs (names may repeat) and one optional file."""
    boundary = "outreach-" + secrets.token_hex(12)
    body = b""
    for name, value in pairs:
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
    if file:
        field, filename, content, ctype = file
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\n'
                 f'Content-Type: {ctype}\r\n\r\n').encode() + content + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, {"Content-Type": "multipart/form-data; boundary=" + boundary}


def raw_get(browser, path):
    """(status, headers, bytes): Browser.request decodes as text, which breaks on image bytes."""
    request = urllib.request.Request(browser.base_url + "/" + path)
    try:
        response = browser.opener.open(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


class OutreachTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        prefix = "out_test_" + secrets.token_hex(5)
        cls.users = {}
        for name, role in (("viewer", "viewer"), ("staff", "staff"), ("nocomms", "staff"), ("gone", "staff"), ("manager", "manager")):
            cls.users[name] = {"id": f"{prefix}_{name}", "name": f"Outreach test {name}", "first": "Outreach", "last": name.capitalize(),
                               "password": "Out9!" + secrets.token_hex(8), "role": role,
                               "status": "inactive" if name == "gone" else "active"}
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        fixture("permission", user_id=cls.users["nocomms"]["id"], area="communications", can_view=0, can_edit=0, can_delete=0)
        cls.marker = "Ot" + secrets.token_hex(5)
        cls.ids = fixture("setup", marker=cls.marker)
        cls.addClassCleanup(fixture, "teardown", campaigns=list(cls.ids.values()))
        cls.addClassCleanup(fixture, "clean", marker=cls.marker)
        days = fixture("today")
        cls.today, cls.far = days["today"], days["far"]

    def tearDown(self):
        fixture("clean", marker=self.marker)

    # ---- helpers ----

    def login(self, name):
        browser = Browser()
        user = self.users[name]
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": user["id"], "password": user["password"]})
        self.assertEqual(status, 303, name)
        return browser

    def get(self, browser, path, status=200):
        code, _, body = browser.request(path)
        self.assertEqual(code, status, path)
        assert_no_php_errors_in(self, body)
        return body

    def post(self, browser, pairs, file=None):
        token = browser.token("addCommunication.php")
        body, headers = multipart([("csrf_token", token)] + list(pairs), file)
        code, response_headers, text = browser.request("addCommunication.php", body, headers)
        assert_no_php_errors_in(self, text)
        return code, response_headers, text

    def form(self, channels=("facebook",), **overrides):
        data = {"kind": "outreach", "campaign_id": str(self.ids["open"]), "posted_on": self.today,
                "posted_by": self.users["staff"]["id"], "posted_by_other": "",
                "post_url": "https://facebook.com/fauquierfish/posts/1", "caption": self.marker + " caption",
                "reach": "2,340", "likes": "128", "shares": "37", "comments": "14"}
        data.update(overrides)
        return [("channel[]", c) for c in channels] + [(k, v) for k, v in data.items() if v is not None]

    def rows(self):
        return fixture("rows", marker=self.marker)

    # ---- access ----

    def test_anonymous_and_token(self):
        status, headers, body = Browser().request("addCommunication.php")
        self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""))
        before = fixture("counts")
        staff = self.login("staff")
        body, headers = multipart(self.form())          # no csrf_token field
        self.assertEqual(staff.request("addCommunication.php", body, headers)[0], 403)
        self.assertEqual(fixture("counts"), before)

    def test_permissions(self):
        before = fixture("counts")
        viewer = self.login("viewer")                    # communications view only
        body = self.get(viewer, "addCommunication.php")
        self.assertIn("can view communications but cannot log them", body)
        self.assertNotIn("Save outreach", body.replace("only-out-inline", ""))   # no save button for a viewer
        self.assertEqual(self.post(viewer, self.form())[0], 403)

        nocomms = self.login("nocomms")                  # communications view removed
        body = self.get(nocomms, "addCommunication.php")
        self.assertIn("Your account cannot view communications.", body)
        self.assertNotIn('name="channel[]"', body)
        self.assertEqual(self.post(nocomms, self.form())[0], 403)
        self.assertEqual(fixture("counts"), before)

    # ---- the form's lists ----

    def test_dropdowns_and_channels(self):
        staff = self.login("staff")
        body = self.get(staff, "addCommunication.php")
        self.assertEqual(len(re.findall(r'name="channel\[\]"', body)), 6)
        self.assertEqual(len(re.findall(r'type="checkbox" name="channel\[\]"', body)), 6)   # several can be picked

        people = options(body, "posted_by")
        self.assertEqual(people[self.users["staff"]["id"]], "Outreach Staff")
        self.assertNotIn(self.users["gone"]["id"], people)    # inactive account
        self.assertNotIn("vmsroot", people)
        self.assertEqual(people[""], "Not recorded")
        self.assertEqual(people[OTHER], "Someone else…")
        self.assertEqual(selected(body, "posted_by"), self.users["staff"]["id"])   # defaults to the signed-in user

        campaigns = options(body, "campaign_out")
        self.assertEqual(campaigns[str(self.ids["open"])], "Open out " + self.marker)
        for key in ("upcoming", "done", "ended"):             # active only
            self.assertNotIn(str(self.ids[key]), campaigns, key)

    # ---- saving ----

    def test_save_with_several_channels(self):
        staff = self.login("staff")
        status, headers, _ = self.post(staff, self.form(channels=("facebook", "instagram", "mass_email")))
        self.assertEqual((status, headers["Location"]), (303, "communications.php?tab=outreach&saved=1"))
        [row] = self.rows()
        self.assertEqual(row["channels"], ["facebook", "instagram", "mass_email"])
        self.assertEqual((row["campaign_id"], row["posted_on"], row["posted_by"], row["posted_by_name"]),
                         (self.ids["open"], self.today, self.users["staff"]["id"], None))
        self.assertEqual((row["reach"], row["likes"], row["shares"], row["comments"]), (2340, 128, 37, 14))
        self.assertEqual(row["post_url"], "https://facebook.com/fauquierfish/posts/1")
        self.assertEqual((row["recorded_by"], row["files"]), (self.users["staff"]["id"], []))

        # Optional everything: one channel, no campaign, no link, no results.
        fixture("clean", marker=self.marker)
        self.post(staff, self.form(channels=("x", "x"), campaign_id="", post_url="", posted_by="", reach="", likes="",
                                   shares="", comments=""))
        [row] = self.rows()
        self.assertEqual(row["channels"], ["x"])                # a repeated channel is stored once
        self.assertEqual((row["campaign_id"], row["posted_by"], row["post_url"], row["reach"], row["comments"]),
                         (None, None, None, None, None))

    def test_posted_by_variants(self):
        staff = self.login("staff")
        self.post(staff, self.form(posted_by=OTHER, posted_by_other="  Guest <b>Poster</b>  "))
        [row] = self.rows()
        self.assertEqual((row["posted_by"], row["posted_by_name"]), (None, "Guest <b>Poster</b>"))   # stored as typed, escaped on output
        fixture("clean", marker=self.marker)
        # A name typed and then left behind after picking a staff member (or Not recorded) is not saved.
        for person in (self.users["staff"]["id"], ""):
            fixture("clean", marker=self.marker)
            self.post(staff, self.form(posted_by=person, posted_by_other="Left Behind"))
            [row] = self.rows()
            self.assertIsNone(row["posted_by_name"])
            self.assertEqual(row["posted_by"], person or None)

    def test_validation_writes_nothing_and_keeps_the_form(self):
        staff = self.login("staff")
        before = fixture("counts")
        bad = {
            "no channel": (dict(channels=()), "Choose at least one channel."),
            "unknown channel": (dict(channels=("facebook", "tiktok")), "Choose a channel from the list."),
            "no date": (dict(posted_on=""), "Enter the post date."),
            "bad date": (dict(posted_on="2026-02-30"), "Enter the post date."),
            "far future": (dict(posted_on=self.far), "The post date cannot be more than a year ahead."),
            "javascript link": (dict(post_url="javascript:alert(1)"), "Enter a full link starting with http:// or https://."),
            "no scheme": (dict(post_url="facebook.com/post"), "Enter a full link starting with http:// or https://."),
            "upcoming campaign": (dict(campaign_id=str(self.ids["upcoming"])), "Choose an active campaign from the list, or None."),
            "completed campaign": (dict(campaign_id=str(self.ids["done"])), "Choose an active campaign from the list, or None."),
            "ended campaign": (dict(campaign_id=str(self.ids["ended"])), "Choose an active campaign from the list, or None."),
            "unknown campaign": (dict(campaign_id="99999999"), "Choose an active campaign from the list, or None."),
            "unknown staff": (dict(posted_by="nobody_" + self.marker), "Choose who posted it from the list."),
            "inactive staff": (dict(posted_by=self.users["gone"]["id"]), "Choose who posted it from the list."),
            "other without name": (dict(posted_by=OTHER), "Type the name of who posted it, or pick one from the list."),
            "long caption": (dict(caption=self.marker + "x" * 5000), "Caption must be 5000 characters or fewer."),
            "negative reach": (dict(reach="-5"), "Reach must be a whole number of 0 or more."),
            "letters in likes": (dict(likes="abc"), "Likes must be a whole number of 0 or more."),
            "decimal shares": (dict(shares="1.5"), "Shares must be a whole number of 0 or more."),
        }
        for label, (overrides, message) in bad.items():
            with self.subTest(label):
                channels = overrides.pop("channels", ("facebook",))
                status, _, body = self.post(staff, self.form(channels=channels, **overrides))
                self.assertEqual(status, 200)
                self.assertIn(message, html.unescape(body))
                self.assertIn('class="form-errors"', body)
                self.assertEqual(fixture("counts"), before)
        # The re-rendered form keeps what was typed and the channels that were ticked.
        status, _, body = self.post(staff, self.form(channels=("facebook", "linkedin"), posted_on="", caption=self.marker + " kept <i>text</i>"))
        self.assertEqual(status, 200)
        self.assertIn(html.escape(self.marker + " kept <i>text</i>"), body)
        self.assertNotIn("<i>text</i>", body)
        self.assertRegex(body, r'name="channel\[\]" value="facebook" checked')
        self.assertRegex(body, r'name="channel\[\]" value="linkedin" checked')
        self.assertNotRegex(body, r'name="channel\[\]" value="x" checked')
        self.assertIn('id="kindOut" checked', body)

    # ---- the photo ----

    def test_photo_upload_and_private_download(self):
        staff = self.login("staff")
        status, headers, _ = self.post(staff, self.form(), file=("photo", "My Screenshot.png", PNG, "image/png"))
        self.assertEqual(status, 303)
        [row] = self.rows()
        [photo] = row["files"]
        self.assertRegex(photo["stored_name"], r"^[a-f0-9]{32}\.png$")        # a random name, never the user's
        self.assertEqual((photo["original_name"], photo["mime_type"], photo["size_bytes"]), ("My Screenshot.png", "image/png", len(PNG)))
        self.assertEqual((UPLOAD_DIR / photo["stored_name"]).read_bytes(), PNG)

        url = f"download.php?type=outreach&id={photo['id']}"
        status, response_headers, content = raw_get(staff, url)
        self.assertEqual((status, content), (200, PNG))
        self.assertEqual(response_headers["Content-Type"], "image/png")
        self.assertEqual(response_headers["X-Content-Type-Options"], "nosniff")
        # Anyone with communications view can see it; nobody else can, and the file is not reachable directly.
        self.assertEqual(raw_get(self.login("viewer"), url)[0], 200)
        self.assertEqual(raw_get(self.login("nocomms"), url)[0], 403)
        self.assertEqual(raw_get(Browser(), url)[0], 303)
        self.assertIn(raw_get(staff, "uploads/outreach/" + photo["stored_name"])[0], (403, 404))
        for bad in ("download.php?type=outreach&id=99999999", "download.php?type=outreach&id=abc",
                    "download.php?type=outreach&id[]=1", "download.php?type=outreach"):
            with self.subTest(url=bad):
                self.assertEqual(raw_get(staff, bad)[0], 404)

    def test_rejected_photos_leave_nothing_behind(self):
        staff = self.login("staff")
        before = fixture("counts")
        cases = {
            "not an image": (("photo", "evil.png", b"<?php echo 1; ?>", "image/png"), "The photo must be a JPG, PNG or WEBP image."),
            "gif": (("photo", "a.gif", GIF, "image/gif"), "The photo must be a JPG, PNG or WEBP image."),
            "svg": (("photo", "a.svg", b'<svg xmlns="http://www.w3.org/2000/svg"/>', "image/svg+xml"), "The photo must be a JPG, PNG or WEBP image."),
            "too big": (("photo", "big.png", PNG + b"0" * (5 * 1024 * 1024), "image/png"), "The photo is too large (5 MB at most)."),
        }
        for label, (file, message) in cases.items():
            with self.subTest(label):
                status, _, body = self.post(staff, self.form(), file=file)
                self.assertEqual(status, 200)
                self.assertIn(message, html.unescape(body))
                self.assertEqual(fixture("counts"), before)       # no row, no channel rows, no file on disk
        # A good post with a bad field and a good photo saves nothing either: the file is not kept.
        status, _, _ = self.post(staff, self.form(posted_on=""), file=("photo", "ok.png", PNG, "image/png"))
        self.assertEqual(status, 200)
        self.assertEqual(fixture("counts"), before)


            # ---- list, view, results, delete ----

    def make_post(self, caption, channels=("facebook",), campaign=None, by="Guest Poster", url="https://example.test/p", file=False):
        stored = secrets.token_hex(16) + ".png" if file else None
        return fixture("post", campaign=campaign, date=self.today, by=by, url=url, caption=self.marker + " " + caption,
                       reach=500, likes=7, channels=list(channels), file=stored, data=base64.b64encode(PNG).decode()) | {"stored": stored}

    def pairs(self, browser, path, data):
        token = browser.token("communications.php")
        code, headers, body = browser.request(path, {"csrf_token": token, **data})
        assert_no_php_errors_in(self, body)
        return code, headers, body

    def test_list_filters_and_pager(self):
        a = self.make_post("alpha", ("facebook", "instagram"), campaign=self.ids["open"])
        b = self.make_post("beta <i>x</i>", ("mass_email",))
        viewer = self.login("viewer")
        body = self.get(viewer, f"communications.php?tab=outreach&q={self.marker}")
        self.assertIn(f"viewCommunication.php?id={a['id']}", body)
        self.assertIn(f"viewCommunication.php?id={b['id']}", body)
        self.assertNotIn("<i>x</i>", body)                               # captions are escaped
        self.assertEqual(body.count("viewCommunication.php?id="), 2)
        body = self.get(viewer, f"communications.php?tab=outreach&q={self.marker}&channel=mass_email")
        self.assertIn(f"id={b['id']}", body)
        self.assertNotIn(f"id={a['id']}", body)
        body = self.get(viewer, f"communications.php?tab=outreach&q={self.marker}&campaign={self.ids['open']}")
        self.assertIn(f"id={a['id']}", body)
        self.assertNotIn(f"id={b['id']}", body)
        self.assertIn("Facebook", body)
        self.assertIn("Instagram", body)                                  # one row, every channel shown
        for junk in ("channel=zzz", "campaign=abc", "page=-4", "page=999", "q[]=x", "channel[]=x"):
            self.get(viewer, "communications.php?tab=outreach&" + junk)
        body = self.get(viewer, f"communications.php?tab=outreach&q={self.marker}zzz")
        self.assertIn("No outreach matches your filters.", body)
        self.assertIn("Your account cannot view communications.", self.get(self.login("nocomms"), "communications.php?tab=outreach"))
        self.assertNotIn(f"id={a['id']}", self.get(self.login("nocomms"), "communications.php?tab=outreach"))

    def test_view_page_and_photo(self):
        p = self.make_post("gamma <b>bold</b>", ("facebook", "x"), campaign=self.ids["open"], file=True)
        staff = self.login("staff")
        body = self.get(staff, f"viewCommunication.php?id={p['id']}")
        self.assertIn("gamma &lt;b&gt;bold&lt;/b&gt;", body)
        self.assertNotIn("<b>bold</b>", body)
        self.assertIn('<p class="num">500</p>', body)
        self.assertIn("Facebook", body)
        self.assertIn("X / Twitter", body)
        self.assertIn(f"download.php?type=outreach&amp;id={p['file']}", body)
        self.assertEqual(raw_get(staff, f"download.php?type=outreach&id={p['file']}")[0], 200)
        self.assertIn(f"campaign.php?id={self.ids['open']}", body)
        email = self.make_post("email body", ("newsletter",))
        self.assertIn("Email content", self.get(staff, f"viewCommunication.php?id={email['id']}"))
        for bad in ("", "abc", "0", "99999999", "id[]=1"):
            code, headers, _ = staff.request("viewCommunication.php?id=" + bad)
            self.assertEqual((code, headers["Location"]), (303, "communications.php?tab=outreach"))
        self.assertIn("Your account cannot view communications.", self.get(self.login("nocomms"), f"viewCommunication.php?id={p['id']}"))

    def test_update_results(self):
        p = self.make_post("delta")
        path = f"viewCommunication.php?id={p['id']}"
        staff = self.login("staff")
        status, headers, _ = self.pairs(staff, path, {"action": "results", "reach": "2,500", "likes": "", "shares": "9", "comments": "3"})
        self.assertEqual((status, headers["Location"]), (303, path + "&saved=1"))
        [row] = self.rows()
        self.assertEqual((row["reach"], row["likes"], row["shares"], row["comments"]), (2500, None, 9, 3))
        before = self.rows()
        status, _, body = self.pairs(staff, path, {"action": "results", "reach": "-1", "likes": "1", "shares": "", "comments": ""})
        self.assertEqual(status, 200)
        self.assertIn("Reach must be a whole number of 0 or more.", body)
        self.assertEqual(self.rows(), before)
        viewer = self.login("viewer")
        self.assertNotIn('value="results"', self.get(viewer, path))
        self.assertEqual(self.pairs(viewer, path, {"action": "results", "reach": "1"})[0], 403)
        self.assertEqual(self.pairs(staff, path, {"action": "bogus"})[0], 400)
        self.assertEqual(self.rows(), before)

    def test_delete_removes_row_and_photo(self):
        p = self.make_post("epsilon", ("facebook", "linkedin"), file=True)
        path = f"viewCommunication.php?id={p['id']}"
        disk = UPLOAD_DIR / p["stored"]
        self.assertTrue(disk.exists())
        staff = self.login("staff")                         # staff has no delete right
        self.assertNotIn('value="delete"', self.get(staff, path))
        self.assertEqual(self.pairs(staff, path, {"action": "delete"})[0], 403)
        self.assertEqual(len(self.rows()), 1)
        manager = self.login("manager")
        self.assertIn('value="delete"', self.get(manager, path))
        status, headers, _ = self.pairs(manager, path, {"action": "delete"})
        self.assertEqual((status, headers["Location"]), (303, "communications.php?tab=outreach&deleted=1"))
        self.assertEqual(self.rows(), [])
        self.assertFalse(disk.exists())                      # file removed with the row
        self.assertEqual(fixture("counts")["files"], 0)
        self.assertEqual(self.pairs(manager, path, {"action": "delete"})[1]["Location"], "communications.php?tab=outreach")

    def test_campaign_page_lists_outreach(self):
        p = self.make_post("zeta campaign post", ("instagram",), campaign=self.ids["open"])
        body = self.get(self.login("manager"), f"campaign.php?id={self.ids['open']}")
        self.assertIn(f"viewCommunication.php?id={p['id']}", body)
        self.assertIn("zeta campaign post", body)
        self.assertRegex(body, r'Communications <span class="count">1</span>')


if __name__ == "__main__":
    unittest.main(verbosity=2)