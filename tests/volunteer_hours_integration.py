"""Volunteer hours pages (addHours.php, hours.php, the profile's Volunteer tab) against the real tables.
Run via `make test-hours` (needs `make seed-demo` first). The suite creates and removes its own donors,
campaigns, entries and users; demo rows are never asserted on. Dates are today, so the "this year" figures
never straddle a new year."""

import html
import json
import re
import secrets
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

XSS = "<img src=x onerror=alert(1)>"

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
$entryCols = 'id, donor_id, campaign_id, activity_date, hours, start_time, end_time, job, location, notes, is_verified,
              counts_for_letter, recorded_by';
switch ($req['action']) {
    case 'users_create':
        foreach ($req['users'] as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $run('INSERT INTO dbpersons (id, name, first_name, last_name, password, role) VALUES (?, ?, ?, ?, ?, ?)',
                 'ssssss', $u['id'], $u['name'], $u['first'], $u['last'], $hash, $u['role']);
        }
        break;
    case 'users_delete':
        foreach ($req['users'] as $u) { $run('DELETE FROM dbpersons WHERE id = ?', 's', $u['id']); }
        break;
    case 'permission':
        $run('INSERT INTO user_permissions (user_id, area, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)',
             'ssiii', $req['user_id'], $req['area'], $req['can_view'], $req['can_edit'], $req['can_delete']);
        break;
    case 'setup':
        $m = $req['marker'];
        $donor = function ($first, $last, $active) use ($run, $con) {
            $run("INSERT INTO donors (donor_type, `first`, `last`, city, state, notes, donor_since, is_active)
                  VALUES ('individual', ?, ?, 'Testville', 'VA', 'hours suite', '2020-01-15', ?)", 'ssi', $first, $last, $active);
            return (int) $con->insert_id;
        };
        $campaign = function ($name, $completed) use ($run, $con) {
            $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, description, completed) VALUES (?, 1000, '2026-01-01', '2026-12-31', 'hours suite', ?)",
                 'ss', $name, $completed);
            return (int) $con->insert_id;
        };
        $entry = function ($donorId, $hours, $start, $end, $job, $location, $notes, $recorder) use ($run, $con) {
            $run('INSERT INTO volunteer_hours (donor_id, activity_date, hours, start_time, end_time, job, location, notes, recorded_by)
                  VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)', 'idssssss', $donorId, $hours, $start, $end, $job, $location, $notes, $recorder);
            return (int) $con->insert_id;
        };
        $out = ['main' => $donor('Vol', $m, 1), 'extra1' => $donor('Helper', $m . 'A', 1), 'extra2' => $donor('Helper', $m . 'B', 1),
                'inactive' => $donor('Gone', $m . 'I', 0), 'xss' => $donor($req['xss'], $m . 'X', 1), 'tabdonor' => $donor('Tab', $m, 1)];
        $out['open'] = $campaign('Open hours ' . $m, '0');
        $out['done'] = $campaign('Done hours ' . $m, '1');
        // Legacy-shaped: a job not in PR #26's list, no times, location or recorder, multi-line notes.
        $out['legacy'] = $entry($out['main'], 4.00, null, null, 'Legacy job ' . $m, null, "line one\nline two", null);
        $out['timed'] = $entry($out['main'], 3.00, '09:00:00', '12:00:00', 'Pantry sorting', 'Hall', null, $req['recorder']);
        $out['on_inactive'] = $entry($out['inactive'], 2.00, null, null, 'Office help', null, null, null);
        $out['on_xss'] = $entry($out['xss'], 1.00, null, null, $req['xss'] . $m, $req['xss'], $req['xss'], null);
        $out['quarter'] = $entry($out['tabdonor'], 2.25, null, null, 'Office help', null, null, null);
        $out['anon'] = $entry(999, 1.50, null, null, 'Office help', null, 'hours suite ' . $m, null);
        break;
    case 'teardown':
        foreach ($req['entries'] as $id) { $run('DELETE FROM volunteer_hours WHERE id = ?', 'i', $id); }
        foreach ($req['donors'] as $id) { $run('DELETE FROM volunteer_hours WHERE donor_id = ?', 'i', $id); }
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM dbevents WHERE id = ?', 'i', $id); }
        foreach ($req['donors'] as $id) { $run('DELETE FROM donors WHERE id = ?', 'i', $id); }
        break;
    case 'entry':
        $out = $run("SELECT $entryCols FROM volunteer_hours WHERE id = ?", 'i', $req['id'])[0] ?? null;
        break;
    case 'rows':
        $out = $run("SELECT $entryCols FROM volunteer_hours WHERE donor_id = ? ORDER BY id", 'i', $req['donor']);
        break;
    case 'changes':
        $out = $run('SELECT summary, changed_by FROM volunteer_hours_changes WHERE volunteer_hours_id = ? ORDER BY id', 'i', $req['id']);
        break;
    case 'counts':
        $out = ['hours' => (int) $run('SELECT COUNT(*) AS n FROM volunteer_hours')[0]['n'],
                'changes' => (int) $run('SELECT COUNT(*) AS n FROM volunteer_hours_changes')[0]['n']];
        break;
    case 'today':
        $out = ['today' => date('Y-m-d'), 'tomorrow' => date('Y-m-d', strtotime('+1 day'))];
        break;
    case 'seed_check':
        $out = ['demo' => (int) $run("SELECT COUNT(*) AS n FROM donors WHERE notes LIKE '[demo]%'")[0]['n']];
        break;
}
$con->commit();
$con->close();
echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def fixture(action, **payload):
    result = subprocess.run(["php", "-r", FIXTURES_PHP], input=json.dumps({"action": action, **payload}),
                            text=True, check=True, cwd=ROOT, capture_output=True)
    return json.loads(result.stdout)


def input_value(body, name):
    match = re.search(r'name="' + re.escape(name) + r'"[^>]*value="(.*?)"', body)
    assert match, name
    return html.unescape(match.group(1))


class VolunteerHoursTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-hours")
        prefix = "hours_test_" + secrets.token_hex(6)
        cls.users = {role: {"id": f"{prefix}_{role}", "name": f"Hours test {role}", "first": "Test", "last": role.capitalize(),
                            "password": "Hrs9!" + secrets.token_hex(8), "role": role}
                     for role in ("viewer", "staff", "manager", "noview", "nogifts")}
        cls.users["noview"]["role"] = "staff"
        cls.users["nogifts"]["role"] = "staff"
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        # donors edit without donors view; donors view and edit without donations view.
        fixture("permission", user_id=cls.users["noview"]["id"], area="donors", can_view=0, can_edit=1, can_delete=1)
        fixture("permission", user_id=cls.users["nogifts"]["id"], area="donations", can_view=0, can_edit=0, can_delete=0)
        cls.marker = "Hv" + secrets.token_hex(5)
        cls.ids = fixture("setup", marker=cls.marker, xss=XSS, recorder=cls.users["manager"]["id"])
        cls.addClassCleanup(fixture, "teardown", entries=[cls.ids["anon"]],
                            donors=[cls.ids[k] for k in ("main", "extra1", "extra2", "inactive", "xss", "tabdonor")],
                            campaigns=[cls.ids["open"], cls.ids["done"]])
        days = fixture("today")
        cls.today, cls.tomorrow = days["today"], days["tomorrow"]

    def login(self, role):
        browser = Browser()
        user = self.users[role]
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": user["id"], "password": user["password"]})
        self.assertEqual(status, 303, role)
        return browser

    def get(self, browser, path, status=200):
        code, headers, body = browser.request(path)
        self.assertEqual(code, status, path)
        assert_no_php_errors_in(self, body)
        return headers, body

    def post(self, browser, path, data):
        pairs = list(data.items()) if isinstance(data, dict) else list(data)
        status, headers, body = browser.request(path, [("csrf_token", browser.token("donor.php"))] + pairs)
        assert_no_php_errors_in(self, body)
        return status, headers, body

    def add_url(self, donor="main"):
        return f"addHours.php?donor_id={self.ids[donor]}"

    def hours_form(self, **overrides):
        form = {"date": self.today, "start_time": "09:00", "end_time": "13:30", "hours": "", "job": "Pantry sorting",
                "campaign_id": str(self.ids["open"]), "location": "Main hall", "notes": "Suite shift " + self.marker,
                "action": "save"}
        form.update(overrides)
        return {k: v for k, v in form.items() if v is not None}

    def edit_form(self, entry, **overrides):
        """The values hours.php renders for a stored entry, as a browser would post them back."""
        _, body = self.get(self.login("staff"), f"hours.php?id={entry}")
        form = {"date": input_value(body, "date"), "start_time": input_value(body, "start_time"),
                "end_time": input_value(body, "end_time"), "hours": input_value(body, "hours"),
                "job": html.unescape(re.search(r'<select id="job".*?<option value="([^"]*)" selected', body, re.S).group(1)),
                "campaign_id": (re.search(r'<select id="campaign".*?<option value="(\d*)" selected', body, re.S) or [None, ""])[1],
                "location": input_value(body, "location"),
                "notes": html.unescape(re.search(r'<textarea id="notes"[^>]*>(.*?)</textarea>', body, re.S).group(1)).replace("\n", "\r\n"),
                "action": "save"}
        if 'name="verified" checked' in body:
            form["verified"] = "on"
        if 'name="service_letter" checked' in body:
            form["service_letter"] = "on"
        form.update(overrides)
        return {k: v for k, v in form.items() if v is not None}

    def saved_count(self, headers):
        match = re.fullmatch(r"donorProfile\.php\?id=" + str(self.ids["main"]) + r"&tab=vol&hours=(\d+)", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        return int(match.group(1))

    def new_rows(self, before):
        return [r for r in fixture("rows", donor=self.ids["main"]) if r["id"] not in before]

    def row_ids(self):
        return {r["id"] for r in fixture("rows", donor=self.ids["main"])}

    def tearDown(self):
        # Rows a test logged for the main donor are removed so each test sees only the fixtures.
        keep = {self.ids["legacy"], self.ids["timed"]}
        extra = [r["id"] for d in ("main", "extra1", "extra2") for r in fixture("rows", donor=self.ids[d]) if r["id"] not in keep]
        if extra:
            fixture("teardown", entries=extra, donors=[], campaigns=[])

    # ---- access ----

    def test_anonymous_requests_redirect_and_posts_need_the_token(self):
        for path in (self.add_url(), f"hours.php?id={self.ids['legacy']}"):
            with self.subTest(path=path):
                status, headers, body = Browser().request(path)
                self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""))
        before = fixture("counts")
        manager = self.login("manager")
        self.assertEqual(manager.request(self.add_url(), self.hours_form())[0], 403)
        self.assertEqual(manager.request(f"hours.php?id={self.ids['legacy']}", {"action": "delete"})[0], 403)
        self.assertEqual(fixture("counts"), before)

    def test_permissions(self):
        before = fixture("counts")
        entry = f"hours.php?id={self.ids['timed']}"

        viewer = self.login("viewer")   # donors view only
        _, body = self.get(viewer, f"donorProfile.php?id={self.ids['main']}&tab=vol")
        self.assertNotIn("+ Log hours", body)
        self.assertIn(f'href="hours.php?id={self.ids["timed"]}" class="select">View</a>', body)
        _, body = self.get(viewer, self.add_url())
        self.assertIn("Your account cannot log volunteer hours.", body)
        self.assertNotIn('name="hours"', body)
        _, body = self.get(viewer, entry)
        self.assertIn("can view volunteer hours but not change them", body)
        self.assertIn('<fieldset class="plain" disabled>', body)
        self.assertNotIn("Save changes", body)
        self.assertNotIn("Delete entry", body)
        self.assertIn('value="Hall"', body)
        self.assertEqual(self.post(viewer, self.add_url(), self.hours_form())[0], 403)
        self.assertEqual(self.post(viewer, entry, self.edit_form(self.ids["timed"], hours="2"))[0], 403)
        self.assertEqual(self.post(viewer, entry, {"action": "delete"})[0], 403)
        # A bad donor still redirects first (donors view, then the donor, then the right to log).
        status, headers, _ = self.post(viewer, f"addHours.php?donor_id={self.ids['inactive']}", self.hours_form())
        self.assertEqual((status, headers["Location"]), (303, "donor.php"))

        noview = self.login("noview")   # donors edit and delete without donors view
        for path in (self.add_url(), entry):
            _, body = self.get(noview, path)
            self.assertIn("cannot view donors", body)
            self.assertNotIn("Vol " + self.marker, body)
            self.assertNotIn("Hall", body)
            self.assertNotIn('name="hours"', body)
        self.assertEqual(self.post(noview, self.add_url(), self.hours_form())[0], 403)
        self.assertEqual(self.post(noview, entry, self.edit_form(self.ids["timed"], hours="2"))[0], 403)
        self.assertEqual(self.post(noview, entry, {"action": "delete"})[0], 403)
        _, body = self.get(noview, f"donorProfile.php?id={self.ids['main']}&tab=vol")
        self.assertNotIn("+ Log hours", body)

        nogifts = self.login("nogifts")   # donors view and edit, donations view removed: no money on the hours pages
        for path in (self.add_url(), entry):
            _, body = self.get(nogifts, path)
            self.assertIn('name="hours"', body)
            self.assertNotIn("$", re.sub(r"<script.*?</script>", "", body, flags=re.S))

        staff = self.login("staff")
        self.assertEqual(staff.request(self.add_url(), self.hours_form())[0], 403)   # no form token
        self.assertEqual(fixture("counts"), before)

    # ---- log ----

    def test_log_hours_from_profile(self):
        staff = self.login("staff")
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['main']}")
        self.assertIn(f'href="{self.add_url()}"', body)
        _, body = self.get(staff, self.add_url())
        self.assertIn("Vol " + self.marker, body)
        self.assertEqual(input_value(body, "date"), self.today)
        before = self.row_ids()
        status, headers, _ = self.post(staff, self.add_url(), self.hours_form())
        self.assertEqual(status, 303)
        self.assertEqual(self.saved_count(headers), 1)
        rows = self.new_rows(before)
        self.assertEqual(len(rows), 1)
        row = rows[0]
        del row["id"]
        self.assertEqual(row, {"donor_id": self.ids["main"], "campaign_id": self.ids["open"], "activity_date": self.today,
                               "hours": "4.50", "start_time": "09:00:00", "end_time": "13:30:00", "job": "Pantry sorting",
                               "location": "Main hall", "notes": "Suite shift " + self.marker, "is_verified": 0,
                               "counts_for_letter": 0, "recorded_by": self.users["staff"]["id"]})
        _, body = self.get(staff, headers["Location"])
        self.assertIn("Hours saved.", body)
        self.assertIn('id="tVol" checked', body)
        self.assertIn("Open hours " + self.marker, body)

    def test_hours_from_times(self):
        staff = self.login("staff")
        for start, end, typed, stored in (("09:00", "13:40", "", "4.67"), ("09:00", "13:40", "4.67", "4.67"),
                                          ("09:00", "09:40", "", "0.67"), ("07:00", "08:10", "1.17", "1.17"),
                                          ("09:00", "13:30", "4", "4.00")):
            with self.subTest(start=start, end=end, typed=typed):
                before = self.row_ids()
                status, headers, body = self.post(staff, self.add_url(), self.hours_form(start_time=start, end_time=end, hours=typed))
                self.assertEqual(status, 303, body[:2000])
                self.assertEqual([r["hours"] for r in self.new_rows(before)], [stored])
        for typed in ("4.68", "5"):
            with self.subTest(typed=typed):
                end = "13:40" if typed == "4.68" else "13:30"
                before = fixture("counts")
                status, _, body = self.post(staff, self.add_url(), self.hours_form(end_time=end, hours=typed))
                self.assertEqual(status, 200)
                self.assertIn("Hours cannot be more than the time between start and end", body)
                self.assertEqual(fixture("counts"), before)

    def test_rejected_input_writes_nothing_and_keeps_the_form(self):
        staff = self.login("staff")
        cases = {
            "missing date": ({"date": ""}, "Enter the date of the shift."),
            "future date": ({"date": self.tomorrow}, "cannot be in the future"),
            "impossible date": ({"date": "2026-02-30"}, "Enter a real date"),
            "hours 0": ({"hours": "0", "start_time": "", "end_time": ""}, "more than 0"),
            "hours 25": ({"hours": "25", "start_time": "", "end_time": ""}, "at most 24"),
            "hours abc": ({"hours": "abc"}, "must be a number"),
            "hours 1.234": ({"hours": "1.234"}, "must be a number"),
            "no hours no times": ({"hours": "", "start_time": "", "end_time": ""}, "Enter the hours worked"),
            "end before start": ({"start_time": "13:00", "end_time": "09:00", "hours": "2"}, "end time must be after"),
            "bad time": ({"start_time": "25:00"}, "start time as HH:MM"),
            "job off list": ({"job": "Not a job " + secrets.token_hex(4)}, "Choose a job from the list."),
            "unknown campaign": ({"campaign_id": "99999999"}, "Choose an open campaign"),
            "done campaign": ({"campaign_id": str(self.ids["done"])}, "Choose an open campaign"),
            "long location": ({"location": "x" * 256}, "Location can be at most 255"),
        }
        for label, (overrides, message) in cases.items():
            with self.subTest(label):
                before = fixture("counts")
                status, _, body = self.post(staff, self.add_url(), self.hours_form(**overrides))
                self.assertEqual(status, 200)
                self.assertIn(html.escape(message, quote=False), body)
                self.assertIn("Suite shift " + self.marker, body)   # the typed notes come back
                self.assertEqual(fixture("counts"), before)
        for bad in (str(self.ids["inactive"]), "999", "99999999", "x"):
            with self.subTest(extra=bad):
                before = fixture("counts")
                data = list(self.hours_form().items()) + [("extra_donor[]", str(self.ids["extra1"])), ("extra_donor[]", bad)]
                status, _, body = self.post(staff, self.add_url(), data)
                self.assertEqual(status, 200)
                self.assertIn("One of the extra volunteers could not be added", body)
                self.assertIn(f'name="extra_donor[]" value="{self.ids["extra1"]}"', body)   # the valid chip survives
                self.assertNotIn(f'name="extra_donor[]" value="{bad}"', body)
                self.assertEqual(fixture("counts"), before)
        # A valid group survives an unrelated error, so fixing the date saves every volunteer.
        data = list(self.hours_form(date=self.tomorrow).items()) + [("extra_donor[]", str(self.ids["extra1"]))]
        _, _, body = self.post(staff, self.add_url(), data)
        self.assertIn(f'name="extra_donor[]" value="{self.ids["extra1"]}"', body)
        self.assertIn("Helper " + self.marker + "A", body)
        data = list(self.hours_form().items()) + [("extra_donor[]", str(i)) for i in range(1, 23)]
        _, _, body = self.post(staff, self.add_url(), data)
        self.assertIn("Add at most 20 more volunteers", body)

    def test_same_shift_for_more_people(self):
        staff = self.login("staff")
        before = fixture("counts")
        data = list(self.hours_form(hours="3", start_time="", end_time="").items()) + [
            ("extra_donor[]", str(self.ids["extra1"])), ("extra_donor[]", str(self.ids["extra2"])),
            ("extra_donor[]", str(self.ids["extra1"])), ("extra_donor[]", str(self.ids["main"]))]
        status, headers, _ = self.post(staff, self.add_url(), data)
        self.assertEqual(status, 303)
        self.assertEqual(self.saved_count(headers), 3)
        self.assertEqual(fixture("counts")["hours"], before["hours"] + 3)
        shape = lambda r: {k: v for k, v in r.items() if k not in ("id", "donor_id")}  # noqa: E731
        rows = [fixture("rows", donor=self.ids[d])[-1] for d in ("main", "extra1", "extra2")]
        self.assertEqual([r["donor_id"] for r in rows], [self.ids[d] for d in ("main", "extra1", "extra2")])
        self.assertEqual(shape(rows[0]), shape(rows[1]))
        self.assertEqual(shape(rows[0]), shape(rows[2]))
        _, body = self.get(staff, headers["Location"])
        self.assertIn("Hours saved for 3 volunteers.", body)

    def test_bad_donor_redirects(self):
        staff = self.login("staff")
        before = fixture("counts")
        for donor in ("", "abc", "99999999", "999", str(self.ids["inactive"])):
            with self.subTest(donor=donor):
                for method in ("GET", "POST"):
                    path = f"addHours.php?donor_id={donor}"
                    status, headers, _ = self.post(staff, path, self.hours_form()) if method == "POST" else staff.request(path)
                    self.assertEqual((status, headers["Location"]), (303, "donor.php"))
        self.assertEqual(fixture("counts"), before)

    # ---- view and edit ----

    def test_view_entry(self):
        staff = self.login("staff")
        _, body = self.get(staff, f"hours.php?id={self.ids['timed']}")
        self.assertEqual(input_value(body, "hours"), "3")
        self.assertEqual(input_value(body, "start_time"), "09:00")
        self.assertIn("Vol " + self.marker, body)
        self.assertIn("Change history", body)
        self.assertIn("Entry logged", body)
        self.assertIn("Test M.", body)   # recorder = the manager fixture user
        self.assertIn("<dt>Usual role</dt>", body)
        _, body = self.get(staff, f"hours.php?id={self.ids['legacy']}")
        self.assertIn("Legacy job " + self.marker, body)
        for bad in ("", "0", "abc", "99999999", "1e3"):
            with self.subTest(id=bad):
                status, headers, _ = staff.request(f"hours.php?id={bad}")
                self.assertEqual((status, headers["Location"]), (303, "donor.php"))

    def test_edit_entry(self):
        staff = self.login("staff")
        legacy, timed = self.ids["legacy"], self.ids["timed"]
        stored = fixture("entry", id=legacy)
        # Saved untouched: no change row, NULLs and the off-list job kept.
        for entry, overrides in ((legacy, {}), (timed, {}), (timed, {"hours": "3.00"}), (timed, {"start_time": "09:00:00"})):
            with self.subTest(entry=entry, overrides=overrides):
                before = fixture("entry", id=entry)
                status, headers, body = self.post(staff, f"hours.php?id={entry}", self.edit_form(entry, **overrides))
                self.assertEqual((status, headers.get("Location")), (303, f"hours.php?id={entry}&saved=1"), body[:3000])
                self.assertEqual(fixture("changes", id=entry), [])
                self.assertEqual(fixture("entry", id=entry), before)
        self.assertEqual(fixture("entry", id=legacy), stored)

        # A real change: one change row, only this row updated, posted id and donor ignored.
        other = fixture("entry", id=timed)
        form = self.edit_form(legacy, hours="4.5", job="Office help", verified="on",
                              donor_id=str(self.ids["extra1"]), id=str(timed))
        status, headers, _ = self.post(staff, f"hours.php?id={legacy}", form)
        self.assertEqual(status, 303)
        row = fixture("entry", id=legacy)
        self.assertEqual((row["donor_id"], row["hours"], row["job"], row["is_verified"], row["location"], row["start_time"]),
                         (self.ids["main"], "4.50", "Office help", 1, None, None))
        self.assertEqual(fixture("entry", id=timed), other)
        changes = fixture("changes", id=legacy)
        self.assertEqual(changes, [{"summary": f"Hours changed 4 → 4.5; Job changed Legacy job {self.marker} → Office help; Marked verified",
                                    "changed_by": self.users["staff"]["id"]}])
        _, body = self.get(staff, headers["Location"])
        self.assertIn("Hours saved.", body)
        self.assertIn(html.escape(changes[0]["summary"], quote=False), body)
        self.assertIn("Test S.", body)
        # The service-letter box and the stored donor of an inactive volunteer.
        self.post(staff, f"hours.php?id={timed}", self.edit_form(timed, service_letter="on", location=""))
        row = fixture("entry", id=timed)
        self.assertEqual((row["counts_for_letter"], row["location"]), (1, None))
        self.assertEqual(fixture("changes", id=timed)[-1]["summary"], "Location changed Hall → —; Counts toward service letter")
        status, _, _ = self.post(staff, f"hours.php?id={self.ids['on_inactive']}", self.edit_form(self.ids["on_inactive"], hours="2.5"))
        self.assertEqual(status, 303)
        self.assertEqual(fixture("entry", id=self.ids["on_inactive"])["hours"], "2.50")
        # Restore the fixtures for the other tests.
        self.post(staff, f"hours.php?id={legacy}", self.edit_form(legacy, hours="4", job="Pantry sorting", verified=None))
        self.post(staff, f"hours.php?id={timed}", self.edit_form(timed, service_letter=None, location="Hall"))

    def test_delete(self):
        staff, manager = self.login("staff"), self.login("manager")
        _, body = self.get(staff, f"hours.php?id={self.ids['timed']}")
        self.assertNotIn("Delete entry", body)
        status, headers, _ = self.post(staff, self.add_url(), self.hours_form(hours="1", start_time="", end_time=""))
        entry = max(self.row_ids())
        self.post(manager, f"hours.php?id={entry}", self.edit_form(entry, hours="1.5"))
        self.assertEqual(len(fixture("changes", id=entry)), 1)
        self.assertEqual(self.post(staff, f"hours.php?id={entry}", {"action": "delete"})[0], 403)
        self.assertIsNotNone(fixture("entry", id=entry))
        _, body = self.get(manager, f"hours.php?id={entry}")
        self.assertIn("Delete entry", body)
        self.assertIn("Removes these 1.5 hours", body)
        status, headers, _ = self.post(manager, f"hours.php?id={entry}", {"action": "delete"})
        self.assertEqual((status, headers["Location"]), (303, f"donorProfile.php?id={self.ids['main']}&tab=vol&hours_deleted=1"))
        self.assertIsNone(fixture("entry", id=entry))
        self.assertEqual(fixture("changes", id=entry), [])
        _, body = self.get(manager, headers["Location"])
        self.assertIn("Hours entry deleted.", body)

    def test_anonymous_donor_entries_are_read_only(self):
        manager = self.login("manager")
        anon = self.ids["anon"]
        before = fixture("entry", id=anon)
        _, body = self.get(manager, f"hours.php?id={anon}")
        self.assertIn('<fieldset class="plain" disabled>', body)
        self.assertNotIn("Save changes", body)
        self.assertNotIn("Delete entry", body)
        form = {"date": self.today, "hours": "2", "job": "Office help", "action": "save"}
        self.assertEqual(self.post(manager, f"hours.php?id={anon}", form)[0], 403)
        self.assertEqual(self.post(manager, f"hours.php?id={anon}", {"action": "delete"})[0], 403)
        self.assertEqual(fixture("entry", id=anon), before)
        self.assertEqual(fixture("changes", id=anon), [])

    # ---- profile tab ----

    def test_volunteer_tab(self):
        staff = self.login("staff")
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['tabdonor']}&tab=vol")
        self.assertIn('id="tVol" checked', body)
        self.assertIn("<strong>2.25</strong>", body)
        self.assertIn('<p class="num">2.25</p>', body)   # the Volunteer hours tile
        self.assertIn(f'href="hours.php?id={self.ids["quarter"]}" class="select">Edit</a>', body)
        self.assertIn("+ Log hours", body)
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['inactive']}&tab=vol")
        self.assertNotIn("+ Log hours", body)                                                       # inactive donor
        self.assertIn(f'href="hours.php?id={self.ids["on_inactive"]}" class="select">Edit</a>', body)  # still editable
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['main']}&hours=abc")
        self.assertNotIn("Hours saved", body)
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['main']}&hours=2")
        self.assertIn("Hours saved for 2 volunteers.", body)

    def test_names_render_as_text(self):
        staff = self.login("staff")
        pages = [f"donorProfile.php?id={self.ids['xss']}&tab=vol", f"addHours.php?donor_id={self.ids['xss']}",
                 f"hours.php?id={self.ids['on_xss']}"]
        self.post(staff, f"hours.php?id={self.ids['on_xss']}", self.edit_form(self.ids["on_xss"], location=XSS + "2"))
        for path in pages:
            with self.subTest(path=path):
                _, body = self.get(staff, path)
                self.assertNotIn(XSS, body)
                self.assertIn(html.escape(XSS), body)


if __name__ == "__main__":
    unittest.main(verbosity=2)
