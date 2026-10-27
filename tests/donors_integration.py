"""Donor pages against the real tables. Run via `make test-donors` (needs `make seed-demo` first)."""

import html
import json
import re
import secrets
import subprocess
import sys
import unittest
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

DEMO_NAMES = ["Maria Lopez", "James Whitaker", "Warrenton Rotary Club", "Thomas Barker",
              "Grace Community Church", "Maria Lopez-Hernandez"]
PAGES = ("donor.php", "donorProfile.php?id={id}", "addDonor.php", "updateDonor.php?id={id}")

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
    case 'users_create':
        foreach ($req['users'] as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $run('INSERT INTO dbpersons (id, name, password, role) VALUES (?, ?, ?, ?)', 'ssss', $u['id'], $u['name'], $hash, $u['role']);
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
    case 'donor_create':
        $m = $req['marker'];
        $email = $m . '@example.test';
        $run("INSERT INTO donors (donor_type, `first`, `last`, email, phone, street, city, state, zip, preferred_contact, notes, donor_since)
              VALUES ('individual', 'Suite', ?, ?, '(540) 555-0199', 'Fixture St', 'Testville', 'DC', '20001', 'text', 'suite notes', '2020-01-15')", 'ss', $m, $email);
        $id = (int) $con->insert_id;
        $run("INSERT INTO donations (donorID, amount, donation_date, payment_method, check_number, send_receipt, thanked, reason) VALUES (?, 25.00, '2024-05-05', 'check', '<1042', 0, 0, 'suite')", 'i', $id);
        $run("INSERT INTO communications (donor_id, occurred_at, comm_type, subject, status) VALUES (?, NOW(), 'email', 'Suite bounce', 'bounced')", 'i', $id);
        $run("INSERT INTO non_cash_donations (donor_id, date_received, item_description, category, quantity, unit, estimated_value, intended_use, send_receipt) VALUES (?, '2024-06-06', 'Suite cans', 'canned_food', 5, 'items', 10.00, 'Food pantry', 0)", 'i', $id);
        $run("INSERT INTO volunteer_hours (donor_id, activity_date, hours, job) VALUES (?, '2024-07-07', 3.5, 'Suite sorting'), (?, '2024-07-08', 2.5, 'Suite packing')", 'ii', $id, $id);
        $out = ['id' => $id];
        break;
    case 'donor_delete':
        $id = (int) $req['id'];
        foreach (['communications' => 'donor_id', 'volunteer_hours' => 'donor_id', 'non_cash_donations' => 'donor_id', 'donations' => 'donorID'] as $t => $c) {
            $run("DELETE FROM $t WHERE $c = ?", 'i', $id);
        }
        $run('DELETE FROM donors WHERE id = ?', 'i', $id);
        break;
    case 'donor_read':
        $out = ['donor' => $run('SELECT * FROM donors WHERE id = ?', 'i', $req['id'])[0] ?? null,
                'summary' => $run('SELECT * FROM donor_summary WHERE id = ?', 'i', $req['id'])[0] ?? null];
        break;
    case 'demo_rows':
        // Seed donors only: other rows may share a seed name (names are not unique; see sql/seed/demo_fish.sql).
        $out = $run("SELECT s.* FROM donor_summary s JOIN donors d ON d.id = s.id
                     WHERE s.display_name = ? AND s.is_anonymous = 0 AND d.notes LIKE '[demo]%' ORDER BY s.id", 's', $req['name']);
        break;
    case 'counts':
        $tiles = $run("SELECT COUNT(*) AS total, SUM(donor_since_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_month, SUM(status <> 'active') AS lapsed
                       FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1")[0];
        $dupes = $run("SELECT COUNT(*) AS n FROM donors d WHERE d.is_active = 1 AND d.id <> 999 AND (
                        (COALESCE(d.email,'') <> '' AND EXISTS (SELECT 1 FROM donors o WHERE o.id <> d.id AND o.is_active = 1 AND o.id <> 999 AND LOWER(o.email) = LOWER(d.email)))
                        OR (REGEXP_REPLACE(COALESCE(d.phone,''), '[^0-9]', '') <> '' AND EXISTS (SELECT 1 FROM donors o WHERE o.id <> d.id AND o.is_active = 1 AND o.id <> 999
                            AND REGEXP_REPLACE(COALESCE(o.phone,''), '[^0-9]', '') = REGEXP_REPLACE(COALESCE(d.phone,''), '[^0-9]', ''))))")[0]['n'];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $req['q']) . '%';
        $nameOnly = $run("SELECT COUNT(*) AS n FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1 AND (display_name LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\')", 'ss', $like, $like)[0]['n'];
        $out = ['total' => (int) $tiles['total'], 'new_month' => (int) $tiles['new_month'], 'lapsed' => (int) $tiles['lapsed'], 'dupes' => (int) $dupes, 'name_only' => (int) $nameOnly];
        break;
    case 'search_ids':
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $req['q']) . '%';
        $out = array_map('intval', array_column($run("SELECT id FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1 AND (display_name LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\') ORDER BY id", 'ss', $like, $like), 'id'));
        break;
    case 'status_donors_create':
        // One throwaway donor per giving-status case; months_ago null means no gift at all.
        $ids = [];
        foreach ($req['cases'] as $label => $monthsAgo) {
            $run("INSERT INTO donors (donor_type, `first`, `last`, notes) VALUES ('individual', 'Status', ?, 'status suite')", 's', $req['marker'] . $label);
            $id = (int) $con->insert_id;
            if ($monthsAgo !== null) {
                $run("INSERT INTO donations (donorID, amount, donation_date, payment_method, send_receipt, thanked, reason)
                      VALUES (?, 10.00, CURDATE() - INTERVAL ? MONTH, 'cash', 0, 0, 'status suite')", 'ii', $id, $monthsAgo);
            }
            $ids[$label] = $id;
        }
        $out = $ids;
        break;
    case 'seed_check':
        $out = ['demo' => (int) $run("SELECT COUNT(*) AS n FROM donors WHERE notes LIKE '[demo]%'")[0]['n']];
        break;
    case 'legacy_undated':
        $out = $run("SELECT d.donorID AS id FROM donations d JOIN donors r ON r.id = d.donorID WHERE d.donation_date IS NULL AND d.payment_method IS NULL AND r.id <> 999 AND r.is_active = 1 LIMIT 1")[0] ?? null;
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


def rows_in(body):
    """(name, type, contact, lifetime, last_gift, status, id) for every donor row in a list page."""
    out = []
    for tr in re.findall(r"<tr>\s*<td><span class=\"initials[^\"]*\">.*?</tr>", body, re.S):
        cells = re.findall(r"<td[^>]*>(.*?)</td>", tr, re.S)
        name = html.unescape(re.search(r"<strong>(.*?)</strong>", cells[0]).group(1))
        status = re.search(r'<span class="status (\w+)"[^>]*>', cells[5]).group(1)
        donor_id = int(re.search(r"donorProfile\.php\?id=(\d+)", tr).group(1))
        out.append((name, html.unescape(cells[1]), html.unescape(cells[2]), cells[3], html.unescape(cells[4]), status, donor_id))
    return out


def fmt_date(value):
    import datetime
    return datetime.date.fromisoformat(value).strftime("%b %-d, %Y") if value else "—"


class DonorPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-donors")
        prefix = "donor_test_" + secrets.token_hex(6)
        cls.users = {role: {"id": f"{prefix}_{role}", "name": f"Donor test {role}", "password": "Donor9!" + secrets.token_hex(8), "role": role}
                     for role in ("viewer", "staff", "manager")}
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        cls.marker = "Suite" + secrets.token_hex(6)
        cls.donor_id = fixture("donor_create", marker=cls.marker)["id"]
        cls.addClassCleanup(fixture, "donor_delete", id=cls.donor_id)
        cls.created = []

    @classmethod
    def tearDownClass(cls):
        for donor_id in cls.created:
            fixture("donor_delete", id=donor_id)
        super().tearDownClass()

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

    def donor_form(self, **overrides):
        form = {"type": "Individual", "first": "Suite", "middle": "Q", "last": self.marker + "New", "org_name": "",
                "email": self.marker.lower() + "new@example.test", "phone": "5405550177", "preferred": "Text",
                "street": "1 Test St", "city": "Warrenton", "state": "VA", "zip": "20186", "email_updates": "on", "action": "save"}
        form.update(overrides)
        return form

    # ---- access ----

    def test_anonymous_requests_redirect_and_posts_need_the_token(self):
        for page in PAGES:
            path = page.format(id=self.donor_id)
            with self.subTest(path=path):
                status, headers, body = Browser().request(path)
                self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""))
        manager = self.login("manager")
        self.assertEqual(manager.request("addDonor.php", self.donor_form())[0], 403)
        self.assertEqual(manager.request(f"updateDonor.php?id={self.donor_id}", {"action": "deactivate"})[0], 403)

    def test_permissions_gate_writes_by_role_and_row(self):
        viewer = self.login("viewer")
        _, body = self.get(viewer, f"updateDonor.php?id={self.donor_id}")
        self.assertIn("can view donors but not change them", body)
        self.assertNotIn('value="deactivate"', body)
        self.assertNotIn("Save changes", body)
        _, body = self.get(viewer, "donor.php")
        self.assertNotIn("+ New donor", body)
        token = viewer.token(f"updateDonor.php?id={self.donor_id}")
        self.assertEqual(viewer.request(f"updateDonor.php?id={self.donor_id}", {"csrf_token": token, **self.donor_form()})[0], 403)
        self.assertEqual(viewer.request("addDonor.php", {"csrf_token": token, **self.donor_form()})[0], 403)

        staff = self.login("staff")
        _, body = self.get(staff, f"updateDonor.php?id={self.donor_id}")
        self.assertNotIn('value="deactivate"', body)
        self.assertIn("Save changes", body)
        token = staff.token(f"updateDonor.php?id={self.donor_id}")
        self.assert_deactivate_ignored(staff, token)
        stored = fixture("donor_read", id=self.donor_id)["donor"]
        status, headers, _ = staff.request(f"updateDonor.php?id={self.donor_id}", {
            "csrf_token": token, "type": "Individual", "first": stored["first"], "middle": "", "last": stored["last"],
            "email": stored["email"], "phone": stored["phone"], "preferred": "Text", "street": stored["street"],
            "city": "Staffville", "state": "DC", "zip": stored["zip"], "action": "save"})
        self.assertEqual((status, headers["Location"]), (303, f"donorProfile.php?id={self.donor_id}&saved=1"))
        self.assertEqual(fixture("donor_read", id=self.donor_id)["donor"]["city"], "Staffville")

        # A per-user row overrides the role preset: the viewer can edit once it has can_edit = 1.
        fixture("permission", user_id=self.users["viewer"]["id"], area="donors", can_view=1, can_edit=1, can_delete=0)
        viewer = self.login("viewer")
        token = viewer.token(f"updateDonor.php?id={self.donor_id}")
        status, headers, _ = viewer.request(f"updateDonor.php?id={self.donor_id}", {
            "csrf_token": token, "type": "Individual", "first": stored["first"], "middle": "", "last": stored["last"],
            "email": stored["email"], "phone": stored["phone"], "preferred": "Text", "street": stored["street"],
            "city": "Viewerville", "state": "DC", "zip": stored["zip"], "action": "save"})
        self.assertEqual(status, 303)
        self.assertEqual(fixture("donor_read", id=self.donor_id)["donor"]["city"], "Viewerville")

    def demo_donor(self, name):
        """The seed donor with this display name; other donors may share the name."""
        rows = fixture("demo_rows", name=name)
        self.assertEqual(len(rows), 1, f"expected one [demo] donor named {name!r} from sql/seed/demo_fish.sql, found {len(rows)}")
        return rows[0]

    def list_row(self, body, donor):
        """The list row for this donor_summary row, matched by id; its rendered name must match too."""
        row = next((r for r in rows_in(body) if r[6] == int(donor["id"])), None)
        self.assertIsNotNone(row, f"donor {donor['display_name']!r} (id {donor['id']}) not in the donor.php list; it may be inactive")
        self.assertEqual(row[0], donor["display_name"])
        return row

    # ---- list ----

    def test_list_reads_the_database(self):
        manager = self.login("manager")
        counts = fixture("counts", q="Maria Lopez")
        _, body = self.get(manager, "donor.php")
        nums = [int(n.replace(",", "")) for n in re.findall(r'<p class="num">([\d,]+)</p>', body)]
        self.assertEqual(nums[:4], [counts["total"], counts["new_month"], counts["lapsed"], counts["dupes"]])
        for name in DEMO_NAMES:
            with self.subTest(name=name):
                expected = self.demo_donor(name)
                _, body = self.get(manager, "donor.php?q=" + urllib.parse.quote(name))
                row = self.list_row(body, expected)
                self.assertEqual(row[1], "Organization" if expected["donor_type"] == "organization" else "Individual")
                self.assertEqual(row[2], expected["email"] or expected["phone"])
                self.assertEqual(row[3], "$" + f"{float(expected['lifetime_cash']):,.2f}")
                self.assertEqual(row[4], fmt_date(expected["last_gift_date"]))
                self.assertEqual(row[5], expected["status"])
        _, body = self.get(manager, "donor.php?q=Maria+Lopez")
        expected_ids = fixture("search_ids", q="Maria Lopez")
        self.assertLessEqual(len(expected_ids), 8, "more Maria Lopez matches than one page (DONORS_PER_PAGE); clean up test rows")
        self.assertTrue({int(self.demo_donor(n)["id"]) for n in ("Maria Lopez", "Maria Lopez-Hernandez")} <= set(expected_ids))
        self.assertEqual(sorted(r[6] for r in rows_in(body)), expected_ids)
        self.assertEqual(len(rows_in(body)), counts["name_only"])
        _, body = self.get(manager, "donor.php?filter=organizations&sort=name")
        types = [r[1] for r in rows_in(body)]
        self.assertTrue(types and set(types) == {"Organization"})
        names = [r[0].lower() for r in rows_in(body)]
        self.assertEqual(names, sorted(names))
        _, body = self.get(manager, "donor.php?q[]=x&page[]=1&sort=zzz&filter[]=lapsed")
        self.assertIn('<option value="last_gift" selected>', body)
        self.assertIn('class="pill on">All</button>', body)
        self.get(manager, "donor.php?page=2")

    def test_giving_status_boundaries_and_lapsed_filter(self):
        # Active: gift in the last 12 months; LYBUNT 12-24; SYBUNT 24-36; inactive 36+ or never.
        # A gift exactly 12 / 24 / 36 months ago falls into the older group.
        cases = {"A11": 11, "B12": 12, "C13": 13, "D24": 24, "E25": 25, "F36": 36, "G37": 37, "Hnone": None}
        expected = {"A11": "active", "B12": "lybunt", "C13": "lybunt", "D24": "sybunt", "E25": "sybunt",
                    "F36": "inactive", "G37": "inactive", "Hnone": "inactive"}
        marker = "Gs" + secrets.token_hex(5)
        ids = fixture("status_donors_create", marker=marker, cases=cases)
        for donor_id in ids.values():
            self.addCleanup(fixture, "donor_delete", id=donor_id)
        manager = self.login("manager")
        for label, donor_id in ids.items():
            with self.subTest(case=label):
                self.assertEqual(fixture("donor_read", id=donor_id)["summary"]["status"], expected[label])
        _, body = self.get(manager, "donor.php?sort=name&q=" + marker)
        shown = {r[0].split(marker)[1]: r[5] for r in rows_in(body)}
        self.assertEqual(shown, expected)
        _, body = self.get(manager, "donor.php?filter=lapsed&sort=name&q=" + marker)
        lapsed = sorted(r[0].split(marker)[1] for r in rows_in(body))
        self.assertEqual(lapsed, sorted(k for k, v in expected.items() if v != "active"))

    # ---- profile ----

    def test_profile_reads_every_tab(self):
        manager = self.login("manager")
        summary = fixture("donor_read", id=self.donor_id)["summary"]
        _, body = self.get(manager, f"donorProfile.php?id={self.donor_id}")
        self.assertIn(f"<strong>Suite {self.marker}</strong>", body)
        self.assertNotIn("Jhon", body)
        self.assertIn("&lt;1042", body)
        self.assertNotIn("#<1042", body)
        self.assertIn('<span class="status pending">Bounced</span>', body)
        self.assertIn("Not requested", body)
        self.assertIn("1 gift &middot; avg $25.00", body)
        self.assertIn(f'Cash <span class="count">{summary["cash_gift_count"]}</span>', body)
        self.assertIn(f'Non-cash <span class="count">{summary["noncash_gift_count"]}</span>', body)
        self.assertIn(f'Communications <span class="count">{summary["communication_count"]}</span>', body)
        self.assertIn("Donor since 2020", body)
        self.assertIn("3 hours served on avg", body)   # 3.5 + 2.5 over two shifts: the frequency reading for UF2-27
        self.assertIn('Volunteer <span class="count">2</span>', body)

        maria = self.demo_donor("Maria Lopez")
        _, body = self.get(manager, f"donorProfile.php?id={maria['id']}")
        for panel in ("p-cash", "p-vol", "p-comm"):
            with self.subTest(panel=panel):
                section = body.split(f'class="panel-tab {panel}"')[1].split('class="panel-tab')[0]
                self.assertIn("Fall Food Drive 2026", section)

        grace = self.demo_donor("Grace Community Church")
        _, listing = self.get(manager, "donor.php?q=" + urllib.parse.quote("Grace Community Church"))
        row = self.list_row(listing, grace)
        _, profile = self.get(manager, f"donorProfile.php?id={grace['id']}")
        self.assertIn(f'Last gift</p><p class="num">{row[4]}</p>', profile)
        self.assertIn("Holiday Meals 2025", profile.split('class="panel-tab p-cash"')[1].split('class="panel-tab')[0])

        legacy = fixture("legacy_undated")
        self.assertIsNotNone(legacy, "baseline has no undated donation")
        _, body = self.get(manager, f"donorProfile.php?id={legacy['id']}")
        self.assertIn("&mdash;", body)

    # ---- writes ----

    def test_add_and_update(self):
        manager = self.login("manager")
        token = manager.token("addDonor.php")
        status, _, body = manager.request("addDonor.php", {"csrf_token": token, **self.donor_form(street="x" * 101)})
        self.assertEqual(status, 200)
        self.assertIn("Street must be 100 characters or fewer", body)

        status, headers, _ = manager.request("addDonor.php", {"csrf_token": token, **self.donor_form()})
        self.assertEqual(status, 303)
        match = re.fullmatch(r"donorProfile\.php\?id=(\d+)&saved=1", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        new_id = int(match.group(1))
        self.created.append(new_id)
        read = fixture("donor_read", id=new_id)
        self.assertEqual((read["donor"]["donor_type"], read["donor"]["middle_name"], read["donor"]["preferred_contact"],
                          read["donor"]["email_updates"], read["donor"]["mail_receipts"], read["donor"]["updated_by"]),
                         ("individual", "Q", "text", 1, 0, self.users["manager"]["id"]))
        self.assertEqual(read["summary"]["status"], "inactive")   # no gifts yet
        _, body = self.get(manager, headers["Location"])
        self.assertIn("Donor saved.", body)

        status, headers, _ = manager.request("addDonor.php", {"csrf_token": token, **self.donor_form(
            type="Organization", org_name="Suite Org " + self.marker, first="", last="", middle="", volunteer="on", do_not_contact="on")})
        self.assertEqual(status, 303)
        org_id = int(re.search(r"id=(\d+)", headers["Location"]).group(1))
        self.created.append(org_id)
        org = fixture("donor_read", id=org_id)
        self.assertEqual((org["donor"]["donor_type"], org["donor"]["org_name"], org["donor"]["is_volunteer"], org["donor"]["do_not_contact"], org["summary"]["display_name"]),
                         ("organization", "Suite Org " + self.marker, 1, 1, "Suite Org " + self.marker))

        # Edit: only the posted columns change; notes, the Text preference and the legacy state survive.
        stored = fixture("donor_read", id=self.donor_id)["donor"]
        token = manager.token(f"updateDonor.php?id={self.donor_id}")
        _, body = self.get(manager, f"updateDonor.php?id={self.donor_id}")
        self.assertIn('<option value="DC" selected>Other: DC</option>', body)
        status, headers, _ = manager.request(f"updateDonor.php?id={self.donor_id}", {
            "csrf_token": token, "type": "Individual", "first": stored["first"], "middle": "", "last": stored["last"],
            "email": stored["email"], "phone": stored["phone"], "preferred": "Text", "street": stored["street"],
            "city": "Edited", "state": "DC", "zip": stored["zip"], "action": "save"})
        self.assertEqual(status, 303)
        after = fixture("donor_read", id=self.donor_id)["donor"]
        self.assertEqual((after["city"], after["notes"], after["preferred_contact"], after["state"], after["updated_by"]),
                         ("Edited", "suite notes", "text", "DC", self.users["manager"]["id"]))

        # Deactivate/reactivate were removed from the UI (PR #28): no button, and a stray action changes nothing.
        _, body = self.get(manager, f"updateDonor.php?id={self.donor_id}")
        self.assertNotIn('value="deactivate"', body)
        self.assertNotIn('value="reactivate"', body)
        self.assert_deactivate_ignored(manager, token)

        before = fixture("donor_read", id=999)["donor"]
        status, headers, _ = manager.request("updateDonor.php?id=999", {"csrf_token": token, **self.donor_form()})
        self.assertEqual((status, headers["Location"]), (303, "donor.php"))
        self.assertEqual(fixture("donor_read", id=999)["donor"], before)


if __name__ == "__main__":
    unittest.main(verbosity=2)
