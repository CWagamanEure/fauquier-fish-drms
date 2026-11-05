"""One-on-one communications (addCommunication.php, UF2-146..148) and the donor profile's Communications tab,
against the real tables. Run via `make test-comms` (needs `make seed-demo` first). The suite creates and
removes its own donors, campaigns, communications and users; demo rows are never asserted on."""

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
OTHER = "__other__"

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
$cols = 'id, donor_id, campaign_id, occurred_at, comm_type, direction, subject, summary, person_contacted,
         person_contacted_id, status, follow_up_on, recorded_by';
switch ($req['action']) {
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
        $donor = function ($first, $last, $active) use ($run, $con) {
            $run("INSERT INTO donors (donor_type, `first`, `last`, city, state, notes, donor_since, is_active)
                  VALUES ('individual', ?, ?, 'Testville', 'VA', 'comms suite', '2020-01-15', ?)", 'ssi', $first, $last, $active);
            return (int) $con->insert_id;
        };
        $campaign = function ($name, $completed) use ($run, $con) {
            $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, description, completed) VALUES (?, 1000, '2026-01-01', '2026-12-31', 'comms suite', ?)",
                 'ss', $name, $completed);
            return (int) $con->insert_id;
        };
        $out = ['main' => $donor('Comm', $m, 1), 'inactive' => $donor('Gone', $m, 0), 'xss' => $donor($req['xss'], $m, 1)];
        $out['open'] = $campaign('Open comms ' . $m, '0');
        $out['done'] = $campaign('Done comms ' . $m, '1');
        break;
    case 'teardown':
        foreach ($req['donors'] as $id) { $run('DELETE FROM communications WHERE donor_id = ?', 'i', $id); }
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM communications WHERE campaign_id = ?', 'i', $id); }
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM dbevents WHERE id = ?', 'i', $id); }
        foreach ($req['donors'] as $id) { $run('DELETE FROM donors WHERE id = ?', 'i', $id); }
        break;
    case 'clear':
        foreach ($req['donors'] as $id) { $run('DELETE FROM communications WHERE donor_id = ?', 'i', $id); }
        break;
    case 'rows':
        $out = $run("SELECT $cols FROM communications WHERE donor_id = ? ORDER BY id", 'i', $req['donor']);
        break;
    case 'count':
        $out = (int) $run('SELECT COUNT(*) AS n FROM communications')[0]['n'];
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


class CommunicationsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-comms")
        prefix = "comms_test_" + secrets.token_hex(6)
        cls.users = {role: {"id": f"{prefix}_{role}", "name": f"Comms test {role}", "first": "Comms", "last": role.capitalize(),
                            "password": "Cmm9!" + secrets.token_hex(8), "role": role, "status": "active"}
                     for role in ("viewer", "staff", "nodonors", "nocomms", "gone")}
        for role in ("nodonors", "nocomms", "gone"):
            cls.users[role]["role"] = "staff"
        cls.users["gone"]["status"] = "inactive"
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        fixture("permission", user_id=cls.users["nodonors"]["id"], area="donors", can_view=0, can_edit=0, can_delete=0)
        fixture("permission", user_id=cls.users["nocomms"]["id"], area="communications", can_view=0, can_edit=0, can_delete=0)
        cls.marker = "Cm" + secrets.token_hex(5)
        cls.ids = fixture("setup", marker=cls.marker, xss=XSS)
        cls.addClassCleanup(fixture, "teardown", donors=[cls.ids[k] for k in ("main", "inactive", "xss")],
                            campaigns=[cls.ids["open"], cls.ids["done"]])
        days = fixture("today")
        cls.today, cls.tomorrow = days["today"], days["tomorrow"]

    def tearDown(self):
        fixture("clear", donors=[self.ids[k] for k in ("main", "inactive", "xss")])

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
        return body

    def post(self, browser, data, status=None):
        pairs = list(data.items())
        code, headers, body = browser.request("addCommunication.php", [("csrf_token", browser.token("communications.php"))] + pairs)
        assert_no_php_errors_in(self, body)
        if status is not None:
            self.assertEqual(code, status, body[:500])
        return code, headers, body

    def form(self, **overrides):
        data = {"kind": "one_on_one", "donor_id": str(self.ids["main"]), "person_contacted": self.users["staff"]["id"],
                "person_contacted_other": "", "comm_type": "phone", "direction": "inbound", "occurred_at": self.today,
                "status": "replied", "subject": "Subject " + self.marker, "campaign_id_one": str(self.ids["open"]),
                "summary": "Summary " + self.marker, "follow_up_on": ""}
        data.update(overrides)
        return {k: v for k, v in data.items() if v is not None}

    def rows(self, donor="main"):
        return fixture("rows", donor=self.ids[donor])

    # ---- the dropdowns (UF2-146, 147, 148) ----

    def test_dropdowns_come_from_the_database(self):
        body = self.get(self.login("staff"), "addCommunication.php")
        donors = options(body, "donor_id")
        self.assertEqual(donors[str(self.ids["main"])], "Comm " + self.marker)
        self.assertEqual(donors[str(self.ids["xss"])], XSS + " " + self.marker)
        self.assertNotIn(str(self.ids["inactive"]), donors)
        self.assertNotIn("999", donors)
        self.assertNotIn(XSS, body)   # escaped everywhere

        people = options(body, "person")
        self.assertEqual(people[self.users["staff"]["id"]], "Comms Staff")
        self.assertNotIn(self.users["gone"]["id"], people)   # inactive account
        self.assertNotIn("vmsroot", people)
        self.assertEqual(people[OTHER], "Someone else…")
        self.assertEqual(people[""], "Not recorded")

        campaigns = options(body, "campaign_one")
        self.assertEqual(campaigns[str(self.ids["open"])], "Open comms " + self.marker)
        self.assertNotIn(str(self.ids["done"]), campaigns)

    def test_donor_id_preselects_only_an_offered_donor(self):
        staff = self.login("staff")
        self.assertEqual(selected(self.get(staff, f"addCommunication.php?donor_id={self.ids['main']}"), "donor_id"), str(self.ids["main"]))
        for bad in (self.ids["inactive"], 999, "abc", "1e3"):
            with self.subTest(donor=bad):
                self.assertIsNone(selected(self.get(staff, f"addCommunication.php?donor_id={bad}"), "donor_id"))

    # ---- saving ----

    def test_save_with_staff_person_and_campaign(self):
        staff = self.login("staff")
        _, headers, _ = self.post(staff, self.form(), status=303)
        self.assertEqual(headers["Location"], f"donorProfile.php?id={self.ids['main']}&tab=comm&comm=1")
        [row] = self.rows()
        self.assertEqual(row["campaign_id"], self.ids["open"])
        self.assertTrue(row["occurred_at"].startswith(self.today + " "), row["occurred_at"])   # today: current time
        self.assertEqual((row["comm_type"], row["direction"], row["status"]), ("phone", "inbound", "replied"))
        self.assertEqual((row["subject"], row["summary"]), ("Subject " + self.marker, "Summary " + self.marker))
        self.assertEqual((row["person_contacted_id"], row["person_contacted"]), (self.users["staff"]["id"], "Comms Staff"))
        self.assertIsNone(row["follow_up_on"])
        self.assertEqual(row["recorded_by"], self.users["staff"]["id"])

        body = self.get(staff, headers["Location"])
        self.assertIn("Communication saved.", body)
        self.assertIn('id="tComm" checked', body)
        self.assertIn("Subject " + self.marker, body)
        self.assertIn(f'href="addCommunication.php?donor_id={self.ids["main"]}"', body)
        self.assertNotIn("logCommunication.php", body)

        campaign = self.get(staff, f"campaign.php?id={self.ids['open']}")
        self.assertIn("Subject " + self.marker, campaign)

    def test_save_someone_else_and_nothing_optional(self):
        staff = self.login("staff")
        self.post(staff, self.form(person_contacted=OTHER, person_contacted_other="  " + XSS + "  ",
                                   campaign_id_one="", subject="", summary=""), status=303)
        [row] = self.rows()
        self.assertEqual((row["person_contacted"], row["person_contacted_id"]), (XSS, None))
        self.assertEqual((row["campaign_id"], row["subject"], row["summary"]), (None, None, None))
        fixture("clear", donors=[self.ids["main"]])
        self.post(staff, self.form(person_contacted=""), status=303)
        [row] = self.rows()
        self.assertEqual((row["person_contacted"], row["person_contacted_id"]), (None, None))
        # A typed name with the select left on "Not recorded" is kept, not dropped.
        fixture("clear", donors=[self.ids["main"]])
        self.post(staff, self.form(person_contacted="", person_contacted_other="Typed Name"), status=303)
        [row] = self.rows()
        self.assertEqual((row["person_contacted"], row["person_contacted_id"]), ("Typed Name", None))
        # A past date is stored without a time.
        fixture("clear", donors=[self.ids["main"]])
        self.post(staff, self.form(occurred_at="2026-01-15"), status=303)
        self.assertEqual(self.rows()[0]["occurred_at"], "2026-01-15 00:00:00")

    def test_follow_up_rules(self):
        staff = self.login("staff")
        cases = [
            ({"follow_up": "on", "follow_up_on": self.tomorrow}, "follow_up", self.tomorrow),
            ({"follow_up": "on", "follow_up_on": self.tomorrow, "status": "bounced"}, "follow_up", self.tomorrow),
            ({"status": "follow_up"}, "follow_up", None),
            ({"status": "follow_up", "follow_up_on": self.tomorrow}, "follow_up", self.tomorrow),
            ({"follow_up_on": self.tomorrow}, "replied", self.tomorrow),   # a date alone is stored, status kept
        ]
        for extra, status, date in cases:
            with self.subTest(extra=extra):
                fixture("clear", donors=[self.ids["main"]])
                self.post(staff, self.form(**extra), status=303)
                [row] = self.rows()
                self.assertEqual((row["status"], row["follow_up_on"]), (status, date))
        fixture("clear", donors=[self.ids["main"]])
        self.post(staff, self.form(status="follow_up"), status=303)
        body = self.get(staff, f"donorProfile.php?id={self.ids['main']}&tab=comm")
        self.assertIn(">Follow up</span>", body)
        self.assertNotIn("Follow_up", body)

        fixture("clear", donors=[self.ids["main"]])
        _, _, body = self.post(staff, self.form(follow_up="on"), status=200)
        self.assertIn("Enter the follow-up date", body)
        _, _, body = self.post(staff, self.form(follow_up_on="2026-02-31"), status=200)
        self.assertIn("Enter a valid follow-up date.", body)
        # Status Follow up with no date plus another error: the re-rendered form posts back cleanly.
        _, _, body = self.post(staff, self.form(status="follow_up", comm_type=""), status=200)
        self.assertNotIn('name="follow_up" checked', body)
        self.assertEqual(selected(body, "status"), "follow_up")
        self.assertEqual(self.rows(), [])
        self.post(staff, self.form(status="follow_up"), status=303)

    def test_invalid_posts_write_nothing_and_keep_values(self):
        staff = self.login("staff")
        bad = {
            "donor 999": ({"donor_id": "999"}, "Choose a donor from the list."),
            "inactive donor": ({"donor_id": str(self.ids["inactive"])}, "Choose a donor from the list."),
            "no donor": ({"donor_id": ""}, "Choose a donor."),
            "unknown donor": ({"donor_id": "2147480000"}, "Choose a donor from the list."),
            "long summary": ({"summary": "s" * 5001}, "Summary must be 5000 characters or fewer."),
            "donor not digits": ({"donor_id": f"{self.ids['main']}.0"}, "Choose a donor from the list."),
            "unknown staff": ({"person_contacted": "nobody_" + self.marker}, "Choose the person contacted from the list."),
            "inactive staff": ({"person_contacted": self.users["gone"]["id"]}, "Choose the person contacted from the list."),
            "staff position 0": ({"person_contacted": "0"}, "Choose the person contacted from the list."),
            "other without name": ({"person_contacted": OTHER}, "Type the name of the person contacted"),
            "other too long": ({"person_contacted": OTHER, "person_contacted_other": "x" * 256}, "255 characters or fewer"),
            "completed campaign": ({"campaign_id_one": str(self.ids["done"])}, "Choose a campaign from the list, or None."),
            "campaign position 0": ({"campaign_id_one": "0"}, "Choose a campaign from the list, or None."),
            "bad type": ({"comm_type": "fax"}, "Choose the type of communication."),
            "bad status": ({"status": "delivered"}, "Choose a status from the list."),
            "future date": ({"occurred_at": self.tomorrow}, "The date cannot be in the future."),
            "bad date": ({"occurred_at": "2026-13-01"}, "Enter the date of the communication."),
            "long subject": ({"subject": "s" * 256}, "Subject must be 255 characters or fewer."),
        }
        before = fixture("count")
        for name, (extra, message) in bad.items():
            with self.subTest(name):
                data = self.form(subject=XSS)
                data.update(extra)
                _, _, body = self.post(staff, data, status=200)
                self.assertIn(message, html.unescape(body))
                self.assertNotIn(XSS, body)
                if "subject" not in extra:
                    self.assertIn('value="' + html.escape(XSS, quote=True) + '"', body)   # posted values kept, escaped
        self.assertEqual(fixture("count"), before)

    def test_outreach_post_writes_nothing(self):
        before = fixture("count")
        _, _, body = self.post(self.login("staff"), {"kind": "outreach", "channel": "facebook", "posted_on": self.today}, status=200)
        self.assertIn("Saving outreach posts is not available yet.", body)
        self.assertIn('id="kindOut" checked', body)
        self.assertEqual(fixture("count"), before)

    # ---- access ----

    def test_csrf_and_sign_in_required(self):
        before = fixture("count")
        status, headers, _ = Browser().request("addCommunication.php")
        self.assertEqual((status, headers["Location"]), (303, "/login.php"))
        staff = self.login("staff")
        self.assertEqual(staff.request("addCommunication.php", self.form())[0], 403)
        self.assertEqual(staff.request("addCommunication.php", [("csrf_token", "x" * 64)] + list(self.form().items()))[0], 403)
        self.assertEqual(fixture("count"), before)

    def test_permissions(self):
        before = fixture("count")
        profile = f"donorProfile.php?id={self.ids['main']}&tab=comm"

        viewer = self.login("viewer")   # communications view only
        body = self.get(viewer, "addCommunication.php")
        self.assertIn("can view communications but cannot log them", body)
        self.assertIn('<fieldset class="plain" disabled>', body)
        self.assertNotIn("Save contact", body)
        self.assertEqual(self.post(viewer, self.form())[0], 403)
        body = self.get(viewer, profile)
        self.assertNotIn("Log communication", body)
        self.assertNotIn("+Log Communication", self.get(viewer, "dashboard.php"))

        nodonors = self.login("nodonors")   # communications edit, no donors view: read-only, no donor names
        body = self.get(nodonors, "addCommunication.php")
        self.assertEqual(set(options(body, "donor_id")), {""})
        self.assertNotIn("Save contact", body)
        self.assertEqual(self.post(nodonors, self.form())[0], 403)

        nocomms = self.login("nocomms")   # donors view, communications off
        body = self.get(nocomms, "addCommunication.php")
        self.assertIn("Your account cannot view communications.", body)
        self.assertNotIn('name="donor_id"', body)
        self.assertEqual(self.post(nocomms, self.form())[0], 403)
        body = self.get(nocomms, profile)
        self.assertIn("Your account cannot view communications.", body)
        self.assertNotIn("Log communication", body)

        staff = self.login("staff")
        self.assertIn('href="addCommunication.php" class="btn btn-outline">+Log Communication', self.get(staff, "dashboard.php"))
        # No new-contact button for an inactive donor or the anonymous donor.
        self.assertNotIn("Log communication", self.get(staff, f"donorProfile.php?id={self.ids['inactive']}&tab=comm"))
        self.assertNotIn("Log communication", self.get(staff, "donorProfile.php?id=999&tab=comm"))
        # Staff account ids are not listed to an account that cannot save.
        self.assertEqual(set(options(self.get(viewer, "addCommunication.php"), "person")), {"", OTHER})
        self.assertEqual(fixture("count"), before)


if __name__ == "__main__":
    unittest.main(verbosity=2)
