"""Data & Imports CSV exports against the real tables. Run via `make test-exports` (needs `make seed-demo` first).
The suite creates and removes its own donors, campaign, gifts and users. Its gifts are dated in 1901, where no
seed or legacy row can be, so the files it downloads hold only rows it made (plus nothing else in that range,
which setup asserts)."""

import csv
import io
import json
import re
import secrets
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

PREFIX = "ExportSuite"   # fixed, so a crashed run's leftovers can be found and removed
HEADER = ["Gift ID", "Date", "Donor ID", "Donor", "Amount", "Fee", "Net", "Payment method", "Check number",
          "Platform", "Deposit date", "Campaign", "In honor of", "Receipt sent", "Recorded by", "Notes"]
HOURS_HEADER = ["Entry ID", "Date", "Volunteer ID", "Volunteer", "Hours", "Start time", "End time", "Job", "Location",
                "Campaign", "Verified", "Counts for letter", "Recorded by", "Notes"]
EMAILS_HEADER = ["Donor ID", "Donor", "Contact person", "Contact title", "Email", "Email updates"]
DONOR_LIST_HEADER = ["Donor ID", "Donor", "Type", "Email", "Phone", "Street", "City", "State", "ZIP", "Main contact",
                     "Main contact email", "Main contact phone", "Preferred contact", "Do not contact", "Email updates",
                     "Mail receipts", "Status", "Last gift", "Donor since"]
NC_HEADER = ["Gift ID", "Date received", "Donor ID", "Donor", "Item description", "Category", "Quantity (items)",
             "Weight (lbs)", "Estimated value", "Condition", "Given out so far (items)", "Intended use", "Campaign",
             "Receipt sent", "Recorded by", "Notes"]
MULTILINE_NOTE = 'comma, "quoted"\nsecond line'

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
$outside = "(d.donation_date BETWEEN '1901-01-01' AND '1901-12-31' OR d.donation_date BETWEEN '1900-01-01' AND '1900-01-02')";
$outsideVh = "(v.activity_date BETWEEN '1901-01-01' AND '1901-12-31' OR v.activity_date BETWEEN '1900-01-01' AND '1900-01-02')";
$outsideNc = "(n.date_received BETWEEN '1901-01-01' AND '1901-12-31' OR n.date_received BETWEEN '1900-01-01' AND '1900-01-02')";
switch ($req['action']) {
    case 'clean':
        // Leftovers of an earlier run that stopped before its cleanup: rows tied to the suite's fixed prefix.
        $p = $req['prefix'] . '%';
        // Hours and non-cash gifts first: their donor FKs are RESTRICT and their campaign FKs SET NULL. Hours changes
        // and distributions cascade.
        $run("DELETE v FROM volunteer_hours v LEFT JOIN donors o ON o.id = v.donor_id LEFT JOIN dbevents e ON e.id = v.campaign_id
              WHERE $outsideVh AND (o.`last` LIKE ? OR e.name LIKE ?)", 'ss', $p, '@' . $p);
        // Non-cash gifts: their donor FK is RESTRICT and their campaign FK is SET NULL. Distributions cascade.
        $run("DELETE n FROM non_cash_donations n LEFT JOIN donors o ON o.id = n.donor_id LEFT JOIN dbevents e ON e.id = n.campaign_id
              WHERE $outsideNc AND (o.`last` LIKE ? OR e.name LIKE ?)", 'ss', $p, '@' . $p);
        $run("DELETE d FROM donations d LEFT JOIN donors o ON o.id = d.donorID LEFT JOIN dbevents e ON e.id = d.eventID
              WHERE $outside AND (o.`last` LIKE ? OR e.name LIKE ?)", 'ss', $p, '@' . $p);
        $run("DELETE FROM dbevents WHERE name LIKE ?", 's', '@' . $p);
        // Donor list status donors (UF2-136) have recent gifts outside the 1901 dates: removed by their donor's prefix only.
        $run("DELETE d FROM donations d JOIN donors o ON o.id = d.donorID WHERE o.`last` LIKE ?", 's', $p);
        $run("DELETE FROM donors WHERE `last` LIKE ?", 's', $p);
        $run("DELETE FROM user_permissions WHERE user_id LIKE 'export\\_test\\_%'");
        $run("DELETE FROM dbpersons WHERE id LIKE 'export\\_test\\_%'");
        $out = ['left' => (int) $run("SELECT COUNT(*) AS n FROM donations d WHERE $outside")[0]['n'],
                'left_hours' => (int) $run("SELECT COUNT(*) AS n FROM volunteer_hours v WHERE $outsideVh")[0]['n'],
                'left_noncash' => (int) $run("SELECT COUNT(*) AS n FROM non_cash_donations n WHERE $outsideNc")[0]['n'],
                'demo' => (int) $run("SELECT COUNT(*) AS n FROM donors WHERE notes LIKE '[demo]%'")[0]['n']];
        break;
    case 'users_create':
        foreach ($req['users'] as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $run('INSERT INTO dbpersons (id, name, first_name, last_name, password, role) VALUES (?, ?, ?, ?, ?, ?)',
                 'ssssss', $u['id'], $u['name'], $u['first'], $u['last'], $hash, $u['role']);
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
        $run("INSERT INTO donors (donor_type, `first`, `last`, city, state, notes, donor_since, is_active)
              VALUES ('individual', '+Export', ?, 'Testville', 'VA', 'export suite', '1900-06-01', 1)", 's', $m);
        $donor = (int) $con->insert_id;
        $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, description, completed)
              VALUES (?, 0, '1901-01-01', '1901-12-31', 'export suite', '0')", 's', '@' . $m);
        $campaign = (int) $con->insert_id;
        $gift = function ($key, $date, array $set) use ($run, $con, $donor, &$out) {
            $cols = ['donorID' => $donor, 'donation_date' => $date, 'amount' => '25.00', 'fee' => null,
                     'payment_method' => 'cash', 'platform' => 'in_person', 'thanked' => 0] + [];
            $cols = array_merge($cols, $set);
            $names = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $run("INSERT INTO donations ($names) VALUES ($marks)", str_repeat('s', count($cols)),
                 ...array_map(function ($v) { return $v === null ? null : (string) $v; }, array_values($cols)));
            $out[$key] = (int) $con->insert_id;
        };
        $out = ['donor' => $donor, 'campaign' => $campaign];
        $gift('before', '1901-02-28', []);
        $gift('first', '1901-03-01', ['amount' => '100.00', 'fee' => '2.50', 'payment_method' => 'check',
              'check_number' => '1001', 'deposit_date' => '1901-03-02', 'eventID' => $campaign, 'in_honor_of' => 1,
              'honoree_name' => 'Pat Doe', 'receipt_sent_at' => '1901-03-05 14:30:00', 'recorded_by' => $req['recorder'],
              'reason' => '=SUM(1)']);
        $gift('anon', '1901-03-10', ['donorID' => 999, 'eventID' => $campaign]);
        $gift('mid', '1901-03-15', ['payment_method' => 'card', 'platform' => 'benevity', 'in_honor_of' => 0,
              'honoree_name' => 'Ignored', 'reason' => $req['multiline']]);
        $gift('dash', '1901-03-20', ['reason' => '-x', 'platform' => null, 'payment_method' => null]);
        $gift('tab', '1901-03-21', ['reason' => "\tTab"]);
        $gift('zero', '1901-03-25', ['amount' => '0.00', 'fee' => '10.00']);
        $gift('last', '1901-03-31', ['amount' => '7.00']);
        $gift('after', '1901-04-01', []);
        // Non-cash gifts: every one on the suite campaign, so clean can always find it through the prefix.
        $item = function ($key, $date, array $set) use ($run, $con, $donor, $campaign, &$out) {
            $cols = array_merge(['donor_id' => $donor, 'campaign_id' => $campaign, 'date_received' => $date,
                                 'item_description' => 'Canned beans', 'category' => 'canned_food', 'quantity' => '10.00',
                                 'weight_lbs' => '12.50', 'intended_use' => 'Food pantry'], $set);
            $names = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $run("INSERT INTO non_cash_donations ($names) VALUES ($marks)", str_repeat('s', count($cols)),
                 ...array_map(function ($v) { return $v === null ? null : (string) $v; }, array_values($cols)));
            $out['noncash'][$key] = (int) $con->insert_id;
        };
        $out['noncash'] = [];
        $item('before', '1901-02-28', []);
        $item('first', '1901-03-01', ['item_description' => '-Canned beans', 'category' => 'clothing', 'quantity' => '24.00',
              'weight_lbs' => '18.50', 'estimated_value' => '60.00', 'item_condition' => 'gently_used',
              'intended_use' => '+Pantry', 'receipt_sent_at' => '1901-03-05 09:00:00', 'recorded_by' => $req['recorder'],
              'notes' => '=SUM(1)']);
        foreach (['3.00', '2.00'] as $qty) {
            $run("INSERT INTO non_cash_distributions (non_cash_donation_id, distributed_on, quantity) VALUES (?, '1901-03-20', ?)",
                 'is', $out['noncash']['first'], $qty);
        }
        $item('anon', '1901-03-10', ['donor_id' => 999]);
        $item('last', '1901-03-31', ['weight_lbs' => null, 'item_condition' => null]);
        $item('after', '1901-04-01', []);
        // Volunteer hours: the suite donor's entries, and one for donor 999 that only the suite campaign ties to the suite.
        $shift = function ($key, $date, array $set) use ($run, $con, $donor, $campaign, &$out) {
            $cols = array_merge(['donor_id' => $donor, 'campaign_id' => $campaign, 'activity_date' => $date, 'hours' => '1.00'], $set);
            $names = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $run("INSERT INTO volunteer_hours ($names) VALUES ($marks)", str_repeat('s', count($cols)),
                 ...array_map(function ($v) { return $v === null ? null : (string) $v; }, array_values($cols)));
            $out['hours'][$key] = (int) $con->insert_id;
        };
        $out['hours'] = [];
        $shift('first', '1901-03-01', ['hours' => '4.50', 'start_time' => '09:00:00', 'end_time' => '13:30:00',
               'job' => '=Driver', 'location' => '+Annex', 'notes' => '@note', 'is_verified' => 1,
               'counts_for_letter' => 1, 'recorded_by' => $req['recorder']]);
        $shift('second', '1901-03-01', ['job' => 'Pantry sorting']);
        $shift('anon', '1901-03-10', ['donor_id' => 999]);
        $shift('last', '1901-06-15', ['hours' => '2.00', 'campaign_id' => null]);
        // Donor emails (UF2-132): the suite donor's own email, plus donors whose `last` is the marker so clean finds
        // them. Contacts cascade when a donor is deleted; these donors have no gifts or hours.
        $run("UPDATE donors SET email = ?, email_updates = 1 WHERE id = ?", 'si', "  =a.$m@example.test  ", $donor);
        $person = function ($key, array $set) use ($run, $con, $m, &$out) {
            $cols = array_merge(['donor_type' => 'organization', 'first' => null, 'last' => $m, 'notes' => 'export suite',
                                 'donor_since' => '1900-06-01'], $set);
            $names = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $run("INSERT INTO donors ($names) VALUES ($marks)", str_repeat('s', count($cols)),
                 ...array_map(function ($v) { return $v === null ? null : (string) $v; }, array_values($cols)));
            $out['email_donors'][$key] = (int) $con->insert_id;
        };
        $contact = function ($donorKey, $key, array $set) use ($run, $con, &$out) {
            $cols = array_merge(['donor_id' => $out['email_donors'][$donorKey], 'name' => null, 'title' => null,
                                 'email' => null, 'is_primary' => 0, 'sort_order' => 0], $set);
            $names = implode(', ', array_keys($cols));
            $marks = implode(', ', array_fill(0, count($cols), '?'));
            $run("INSERT INTO donor_contacts ($names) VALUES ($marks)", str_repeat('s', count($cols)),
                 ...array_map(function ($v) { return $v === null ? null : (string) $v; }, array_values($cols)));
            $out['contacts'][$key] = (int) $con->insert_id;
        };
        $out['email_donors'] = [];
        $out['contacts'] = [];
        $person('org', ['org_name' => "@Org $m", 'email' => "   ", 'email_updates' => 0]);
        $contact('org', 'lee', ['name' => "-Lee $m", 'title' => 'Director', 'email' => "lee.$m@example.test"]);
        $contact('org', 'info', ['email' => "info.$m@example.test"]);
        $contact('org', 'blank', ['name' => "Blank $m", 'email' => " \t", 'sort_order' => 1]);
        $contact('org', 'nbsp', ['name' => "Nbsp $m", 'email' => "\u{00A0}\u{00A0}", 'sort_order' => 1]);
        $contact('org', 'main', ['name' => "Main $m", 'email' => "main.$m@example.test", 'phone' => '+1 540 555 0100',
                                 'is_primary' => 1, 'sort_order' => 2]);
        $person('dnc', ['org_name' => "Dnc $m", 'email' => "dnc.$m@example.test", 'do_not_contact' => 1]);
        $contact('dnc', 'dnc', ['name' => "Dnc contact $m", 'email' => "dnc.contact.$m@example.test"]);
        $person('gone', ['donor_type' => 'foundation', 'org_name' => "Gone $m", 'email' => "gone.$m@example.test", 'is_active' => 0]);
        $contact('gone', 'gone', ['name' => "Gone contact $m", 'email' => "gone.contact.$m@example.test"]);
        $person('fdn', ['donor_type' => 'foundation', 'org_name' => "Fdn $m"]);
        $contact('fdn', 'fran', ['name' => "Fran $m", 'email' => "fran.$m@example.test", 'phone' => '540-555-0102',
                                 'is_primary' => 1]);
        $person('solo', ['donor_type' => 'individual', 'first' => 'Solo']);
        $contact('solo', 'hidden', ['name' => "Hidden $m", 'email' => "hidden.$m@example.test", 'phone' => '540-555-0103',
                                    'is_primary' => 1]);
        // Donor list (UF2-136): one individual per giving status, each with one cash gift dated months away from the
        // 12/24/36-month edges, relative to PHP's today.
        $out['status_donors'] = [];
        $out['status_gifts'] = [];
        foreach (['active' => ['Active', '-6 months'], 'lybunt' => ['Lybunt', '-18 months'],
                  'sybunt' => ['Sybunt', '-30 months']] as $key => [$first, $ago]) {
            $run("INSERT INTO donors (donor_type, `first`, `last`, notes, donor_since) VALUES ('individual', ?, ?, 'export suite', '1900-06-01')",
                 'ss', $first, $m);
            $id = (int) $con->insert_id;
            $out['status_donors'][$key] = $id;
            $date = date('Y-m-d', strtotime($ago));
            $gift('status_gift', $date, ['donorID' => $id]);
            $out['status_gifts'][$key] = $date;
        }
        unset($out['status_gift']);
        $run("UPDATE donors SET email = ?, phone = '540-555-0111', street = '=1 Main St', city = 'Warrenton', state = 'VA',
              zip = '20186', preferred_contact = 'mail', mail_receipts = 1, email_updates = 1 WHERE id = ?",
             'si', "active.$m@example.test", $out['status_donors']['active']);
        break;
    case 'teardown':
        // Hours, then non-cash, before dbevents and donors: RESTRICT donor FKs, and SET NULL campaign FKs would
        // otherwise orphan the donor-999 rows that only the campaign ties to the suite. Children cascade.
        $run('DELETE FROM volunteer_hours WHERE donor_id = ? OR campaign_id = ?', 'ii', $req['donor'], $req['campaign']);
        $run('DELETE FROM non_cash_donations WHERE donor_id = ? OR campaign_id = ?', 'ii', $req['donor'], $req['campaign']);
        $run('DELETE FROM donations WHERE donorID = ? OR eventID = ?', 'ii', $req['donor'], $req['campaign']);
        $run('DELETE FROM dbevents WHERE id = ?', 'i', $req['campaign']);
        foreach ($req['email_donors'] ?? [] as $id) {   // UF2-132 donors; their contacts cascade
            $run('DELETE FROM donors WHERE id = ?', 'i', $id);
        }
        foreach ($req['status_donors'] ?? [] as $id) {   // UF2-136 donors: their gift, then the donor
            $run('DELETE FROM donations WHERE donorID = ?', 'i', $id);
            $run('DELETE FROM donors WHERE id = ?', 'i', $id);
        }
        $run('DELETE FROM donors WHERE id = ?', 'i', $req['donor']);
        break;
    case 'active_ids':
        $out = array_map(function ($r) { return (int) $r['id']; },
                         $run('SELECT id FROM donor_summary WHERE is_anonymous = 0 AND is_active = 1'));
        break;
    case 'today':
        $out = date('Y-m-d');
        break;
    case 'anonymous_name':
        $out = $run('SELECT display_name FROM donor_summary WHERE id = 999')[0]['display_name'] ?? null;
        break;
}
$con->commit();
$con->close();
echo json_encode($out, JSON_THROW_ON_ERROR);
"""


def fixture(action, **payload):
    """Runs one FIXTURES_PHP action in a transaction and returns its JSON result."""
    result = subprocess.run(["php", "-r", FIXTURES_PHP], input=json.dumps({"action": action, **payload}),
                            text=True, check=True, cwd=ROOT, capture_output=True)
    return json.loads(result.stdout)


def parse_csv(body):
    """Checks the BOM and returns the CSV rows."""
    assert body.startswith("﻿"), body[:40]
    return list(csv.reader(io.StringIO(body[1:], newline="")))


class CashExportTests(unittest.TestCase):
    """UF2-133 cash, UF2-134 non-cash, UF2-138 volunteer hours and UF2-132 donor emails exports, against gifts and
    volunteer hours entries dated in 1901 and donors the suite makes."""

    @classmethod
    def setUpClass(cls):
        """Removes leftovers, checks the dates are empty, then creates the users and gifts."""
        state = fixture("clean", prefix=PREFIX)
        if state["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-exports")
        if state["left_hours"] != 0:
            raise AssertionError("volunteer hours dated in 1901 or on 1900-01-01..02 exist that this suite did not make; "
                                 "the suite needs those dates empty")
        if state["left_noncash"] != 0:
            raise AssertionError("non-cash gifts received in 1901 or on 1900-01-01..02 exist that this suite did not "
                                 "make; the suite needs those dates empty")
        if state["left"] != 0:
            raise AssertionError("cash donations dated in 1901 or on 1900-01-01..02 exist that this suite did not make; "
                                 "the suite needs those dates empty")
        cls.users = {role: {"id": f"export_test_{role}_{secrets.token_hex(4)}", "name": f"Export test {role}",
                            "first": "Rita", "last": "Recorder" if role == "manager" else role.capitalize(),
                            "password": "Exp9!" + secrets.token_hex(8), "role": role}
                     for role in ("manager", "staff", "viewer", "noexport", "nodonors")}
        cls.users["noexport"]["role"] = "staff"
        cls.users["nodonors"]["role"] = "staff"
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        fixture("permission", user_id=cls.users["noexport"]["id"], area="donations", can_view=0, can_edit=0, can_delete=0)
        fixture("permission", user_id=cls.users["nodonors"]["id"], area="donors", can_view=0, can_edit=0, can_delete=0)
        cls.marker = PREFIX + secrets.token_hex(4)
        cls.ids = fixture("setup", marker=cls.marker, recorder=cls.users["manager"]["id"], multiline=MULTILINE_NOTE)
        cls.addClassCleanup(fixture, "teardown", donor=cls.ids["donor"], campaign=cls.ids["campaign"],
                            email_donors=list(cls.ids["email_donors"].values()),
                            status_donors=list(cls.ids["status_donors"].values()))

    def login(self, role):
        """Signs in as the suite user for role and returns the browser."""
        browser = Browser()
        user = self.users[role]
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": user["id"],
                                                     "password": user["password"]})
        self.assertEqual(status, 303, role)
        return browser

    def export(self, browser, fields):
        """POST to exportData.php with a fresh token; fields is a dict or a list of pairs."""
        pairs = list(fields.items()) if isinstance(fields, dict) else list(fields)
        token = browser.token("dataImports.php")
        return browser.request("exportData.php", [("csrf_token", token)] + pairs)

    def cash(self, browser, start="1901-03-01", end="1901-03-31"):
        """Requests the cash export for a date range."""
        return self.export(browser, {"export": "cash", "from": start, "to": end})

    def test_cash_export_file(self):
        """Headers, columns, range ends, order, labels and cell escaping."""
        status, headers, body = self.cash(self.login("manager"))
        self.assertEqual(status, 200, body[:300])
        self.assertEqual(headers["Content-Type"], "text/csv; charset=utf-8")
        self.assertEqual(headers["Content-Disposition"],
                         'attachment; filename="fish-cash-donations-1901-03-01-to-1901-03-31.csv"')
        self.assertIn("no-store", headers["Cache-Control"])
        self.assertEqual(headers["X-Content-Type-Options"], "nosniff")
        assert_no_php_errors_in(self, body)
        rows = parse_csv(body)
        self.assertEqual(rows[0], HEADER)
        by_id = {int(r[0]): dict(zip(HEADER, r)) for r in rows[1:]}
        ids = self.ids
        # Criterion 2: both ends inclusive, the day before and after excluded; ordered by date then id.
        expected = [ids[k] for k in ("first", "anon", "mid", "dash", "tab", "zero", "last")]
        self.assertEqual([int(r[0]) for r in rows[1:]], expected)
        self.assertNotIn(ids["before"], by_id)
        self.assertNotIn(ids["after"], by_id)

        first = by_id[ids["first"]]
        self.assertEqual(first, {
            "Gift ID": str(ids["first"]), "Date": "1901-03-01", "Donor ID": str(ids["donor"]),
            "Donor": "'+Export " + self.marker, "Amount": "100.00", "Fee": "2.50", "Net": "97.50",
            "Payment method": "Check", "Check number": "1001", "Platform": "In person", "Deposit date": "1901-03-02",
            "Campaign": "'@" + self.marker, "In honor of": "Pat Doe", "Receipt sent": "1901-03-05",
            "Recorded by": "Rita R.", "Notes": "'=SUM(1)"})
        mid = by_id[ids["mid"]]
        self.assertEqual((mid["Payment method"], mid["Platform"], mid["In honor of"], mid["Notes"]),
                         ("Card", "Benevity", "", MULTILINE_NOTE))
        dash = by_id[ids["dash"]]
        self.assertEqual((dash["Payment method"], dash["Platform"], dash["Notes"], dash["Fee"], dash["Recorded by"]),
                         ("", "", "'-x", "", ""))
        self.assertEqual(by_id[ids["tab"]]["Notes"], "'\tTab")
        zero = by_id[ids["zero"]]
        self.assertEqual((zero["Amount"], zero["Fee"], zero["Net"]), ("0.00", "10.00", "-10.00"))
        anon = by_id[ids["anon"]]
        self.assertEqual((anon["Donor ID"], anon["Donor"]), ("999", "Anonymous"))

    def test_empty_range_is_header_only(self):
        """A range with no gifts gives the BOM and header only."""
        status, _, body = self.cash(self.login("manager"), "1900-01-01", "1900-01-02")
        self.assertEqual(status, 200)
        self.assertEqual(body, "﻿" + ",".join(f'"{h}"' if " " in h else h for h in HEADER) + "\r\n")

    def test_bad_dates_redirect_without_a_file(self):
        """Bad, missing, reversed or array dates redirect with the dates error."""
        manager = self.login("manager")
        cases = [
            {"export": "cash", "from": "1901-03-31", "to": "1901-03-01"},
            {"export": "cash", "to": "1901-03-31"},
            {"export": "cash", "from": "", "to": ""},
            {"export": "cash", "from": "2026-02-30", "to": "2026-03-01"},
            {"export": "cash", "from": "abc", "to": "1901-03-31"},
            [("export", "cash"), ("from[]", "1901-03-01"), ("to", "1901-03-31")],
        ]
        for fields in cases:
            with self.subTest(fields=fields):
                status, headers, body = self.export(manager, fields)
                self.assertEqual(status, 303, body[:300])
                self.assertEqual(headers["Location"], "dataImports.php?export_error=dates#export")
                self.assertNotIn("text/csv", headers.get("Content-Type", ""))

    def test_unknown_exports_are_not_available(self):
        """Exports that are not built redirect as unavailable, whatever the permission."""
        for role in ("manager", "noexport"):
            browser = self.login(role)
            for name in ("donor-totals", "junk", ""):
                with self.subTest(role=role, export=name):
                    status, headers, _ = self.export(browser, {"export": name})
                    self.assertEqual(status, 303)
                    self.assertEqual(headers["Location"], "dataImports.php?export_error=unavailable#export")

    def test_permissions(self):
        """Staff and viewer can export, no donations view gets 403, no donors view blanks Donor ID."""
        for role in ("staff", "viewer"):
            with self.subTest(role=role):
                status, headers, body = self.cash(self.login(role))
                self.assertEqual(status, 200, body[:300])
                self.assertIn(str(self.ids["first"]), body)
        status, headers, body = self.cash(self.login("noexport"))
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))
        # Without donors view, the Donor ID column is blank (the donations list shows no ids then either).
        status, _, body = self.cash(self.login("nodonors"))
        self.assertEqual(status, 200)
        rows = parse_csv(body)
        self.assertEqual({r[2] for r in rows[1:]}, {""})

    def test_method_and_token(self):
        """GET gets 405, a bad CSRF token gets 403."""
        manager = self.login("manager")
        status, headers, body = manager.request("exportData.php")
        self.assertEqual((status, body), (405, ""))
        self.assertEqual(headers["Allow"], "POST")
        status, headers, body = manager.request("exportData.php", {"export": "cash", "from": "1901-03-01",
                                                                    "to": "1901-03-31", "csrf_token": "0" * 64})
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)

    def test_page_buttons_and_messages(self):
        """Button states and error messages on dataImports.php."""
        _, _, body = Browser.request(self.login("staff"), "dataImports.php")
        assert_no_php_errors_in(self, body)
        self.assertEqual(body.count('action="exportData.php"'), 5)
        self.assertEqual(body.count(">Coming soon</button>"), 3)
        self.assertNotIn(">No access</button>", body)
        self.assertIn('id="export"', body)
        _, _, body = self.login("noexport").request("dataImports.php")
        self.assertEqual(body.count(">No access</button>"), 2)
        self.assertEqual(body.count(">Export CSV</button>"), 3)
        _, _, body = self.login("nodonors").request("dataImports.php")
        self.assertEqual(body.count(">No access</button>"), 3)
        self.assertEqual(body.count(">Export CSV</button>"), 2)
        manager = self.login("manager")
        for code, text in (("dates", "Pick a From date on or before the To date."),
                           ("unavailable", "That export is not available yet."),
                           ("failed", "The export could not be made. Try again.")):
            with self.subTest(code=code):
                _, _, body = manager.request("dataImports.php?export_error=" + code)
                self.assertIn(text, body)
        _, _, body = manager.request("dataImports.php?export_error=%3Cscript%3E")
        self.assertNotIn('class="form-errors"', body)
        self.assertNotIn("<script>", body)

    def noncash(self, browser, start="1901-03-01", end="1901-03-31"):
        """Requests the non-cash export for a date range."""
        return self.export(browser, {"export": "noncash", "from": start, "to": end})

    def test_noncash_export_file(self):
        """UF2-134: headers, columns, range ends, order, labels, blanks, decimals, given out and escaping."""
        status, headers, body = self.noncash(self.login("manager"))
        self.assertEqual(status, 200, body[:300])
        self.assertEqual(headers["Content-Type"], "text/csv; charset=utf-8")
        self.assertEqual(headers["Content-Disposition"],
                         'attachment; filename="fish-noncash-donations-1901-03-01-to-1901-03-31.csv"')
        self.assertIn("no-store", headers["Cache-Control"])
        assert_no_php_errors_in(self, body)
        rows = parse_csv(body)
        self.assertEqual(rows[0], NC_HEADER)
        nc = self.ids["noncash"]
        # Both ends included, the day before and after left out, ordered by date then id.
        self.assertEqual([int(r[0]) for r in rows[1:]], [nc[k] for k in ("first", "anon", "last")])
        by_id = {int(r[0]): dict(zip(NC_HEADER, r)) for r in rows[1:]}
        self.assertEqual(by_id[nc["first"]], {
            "Gift ID": str(nc["first"]), "Date received": "1901-03-01", "Donor ID": str(self.ids["donor"]),
            "Donor": "'+Export " + self.marker, "Item description": "'-Canned beans", "Category": "Clothing",
            "Quantity (items)": "24.00", "Weight (lbs)": "18.50", "Estimated value": "60.00", "Condition": "Gently used",
            "Given out so far (items)": "5.00", "Intended use": "'+Pantry", "Campaign": "'@" + self.marker,
            "Receipt sent": "1901-03-05", "Recorded by": "Rita R.", "Notes": "'=SUM(1)"})
        anon = by_id[nc["anon"]]
        self.assertEqual((anon["Donor ID"], anon["Donor"], anon["Category"], anon["Given out so far (items)"]),
                         ("999", "Anonymous", "Canned food", "0.00"))
        last = by_id[nc["last"]]
        self.assertEqual((last["Weight (lbs)"], last["Condition"], last["Estimated value"], last["Quantity (items)"],
                          last["Item description"], last["Receipt sent"], last["Recorded by"]),
                         ("", "", "", "10.00", "Canned beans", "", ""))

    def test_noncash_empty_range_and_bad_dates(self):
        """An empty range gives the header only; bad dates redirect without a file."""
        manager = self.login("manager")
        status, _, body = self.noncash(manager, "1900-01-01", "1900-01-02")
        self.assertEqual(status, 200)
        self.assertEqual(body, "\ufeff" + ",".join(f'"{h}"' if " " in h else h for h in NC_HEADER) + "\r\n")
        for start, end in (("1901-03-31", "1901-03-01"), ("", ""), ("2026-02-30", "2026-03-01")):
            with self.subTest(start=start, end=end):
                status, headers, body = self.noncash(manager, start, end)
                self.assertEqual(status, 303, body[:300])
                self.assertEqual(headers["Location"], "dataImports.php?export_error=dates#export")
                self.assertNotIn("text/csv", headers.get("Content-Type", ""))

    def test_noncash_permissions(self):
        """Staff and viewer can export, no donations view gets 403, no donors view blanks Donor ID."""
        for role in ("staff", "viewer"):
            with self.subTest(role=role):
                status, _, body = self.noncash(self.login(role))
                self.assertEqual(status, 200, body[:300])
                self.assertIn(str(self.ids["noncash"]["first"]), body)
        status, headers, body = self.noncash(self.login("noexport"))
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("Canned beans", body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))
        status, _, body = self.noncash(self.login("nodonors"))
        self.assertEqual(status, 200)
        self.assertEqual({r[2] for r in parse_csv(body)[1:]}, {""})

    def hours(self, browser):
        """Requests the volunteer hours export."""
        return self.export(browser, {"export": "hours"})

    def test_hours_export_file(self):
        """UF2-138: headers, filename date, order, values, blanks, Yes/No, times, escaping and Anonymous."""
        before = fixture("today")
        status, headers, body = self.hours(self.login("manager"))
        after = fixture("today")
        self.assertEqual(status, 200, body[:300])
        self.assertEqual(headers["Content-Type"], "text/csv; charset=utf-8")
        self.assertIn(headers["Content-Disposition"],
                      {f'attachment; filename="fish-volunteer-hours-{d}.csv"' for d in (before, after)})
        self.assertIn("no-store", headers["Cache-Control"])
        self.assertEqual(headers["X-Content-Type-Options"], "nosniff")
        assert_no_php_errors_in(self, body)
        rows = parse_csv(body)
        self.assertEqual(rows[0], HOURS_HEADER)
        hrs = self.ids["hours"]
        suite = [int(r[0]) for r in rows[1:] if int(r[0]) in set(hrs.values())]
        self.assertEqual(suite, [hrs[k] for k in ("first", "second", "anon", "last")])
        self.assertEqual([r for r in rows[1:] if r[1].startswith("1901-") and int(r[0]) not in set(hrs.values())], [])
        by_id = {int(r[0]): dict(zip(HOURS_HEADER, r)) for r in rows[1:]}
        self.assertEqual(by_id[hrs["first"]], {
            "Entry ID": str(hrs["first"]), "Date": "1901-03-01", "Volunteer ID": str(self.ids["donor"]),
            "Volunteer": "'+Export " + self.marker, "Hours": "4.50", "Start time": "09:00", "End time": "13:30",
            "Job": "'=Driver", "Location": "'+Annex", "Campaign": "'@" + self.marker, "Verified": "Yes",
            "Counts for letter": "Yes", "Recorded by": "Rita R.", "Notes": "'@note"})
        anon = by_id[hrs["anon"]]
        self.assertEqual((anon["Volunteer ID"], anon["Volunteer"]), ("999", "Anonymous"))
        last = by_id[hrs["last"]]
        self.assertEqual(last, {
            "Entry ID": str(hrs["last"]), "Date": "1901-06-15", "Volunteer ID": str(self.ids["donor"]),
            "Volunteer": "'+Export " + self.marker, "Hours": "2.00", "Start time": "", "End time": "", "Job": "",
            "Location": "", "Campaign": "", "Verified": "No", "Counts for letter": "No", "Recorded by": "",
            "Notes": ""})

    def test_hours_permissions(self):
        """Donors view is the rule: noexport (no donations view) and viewer get the file, nodonors gets 403."""
        for role in ("noexport", "viewer", "staff"):
            with self.subTest(role=role):
                status, _, body = self.hours(self.login(role))
                self.assertEqual(status, 200, body[:300])
                self.assertIn(str(self.ids["hours"]["first"]), {r[0] for r in parse_csv(body)[1:]})
        status, headers, body = self.hours(self.login("nodonors"))
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("Driver", body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))

    def test_hours_method_and_token(self):
        """GET gets 405 and a bad CSRF token gets 403 for the hours export too."""
        manager = self.login("manager")
        status, headers, body = manager.request("exportData.php")
        self.assertEqual((status, body), (405, ""))
        status, headers, body = manager.request("exportData.php", {"export": "hours", "csrf_token": "0" * 64})
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))

    def emails(self, browser):
        """Requests the donor emails export (no filter; a posted date is ignored)."""
        return self.export(browser, {"export": "emails", "from": "junk"})

    def test_emails_export_file(self):
        """UF2-132: headers, filename date, who is left out, order, trimming, Yes/No and escaping."""
        before = fixture("today")
        status, headers, body = self.emails(self.login("manager"))
        after = fixture("today")
        self.assertEqual(status, 200, body[:300])
        self.assertEqual(headers["Content-Type"], "text/csv; charset=utf-8")
        self.assertIn(headers["Content-Disposition"],
                      {f'attachment; filename="fish-donor-emails-{d}.csv"' for d in (before, after)})
        self.assertIn("no-store", headers["Cache-Control"])
        self.assertEqual(headers["X-Content-Type-Options"], "nosniff")
        assert_no_php_errors_in(self, body)
        rows = parse_csv(body)
        self.assertEqual(rows[0], EMAILS_HEADER)
        self.assertTrue(all(r[4] != "" for r in rows[1:]), "a row with a blank email")
        self.assertNotIn("999", {r[0] for r in rows[1:]})
        m, donors = self.marker, self.ids["email_donors"]
        suite_ids = [str(self.ids["donor"])] + [str(i) for i in donors.values()]
        mine = [r for r in rows[1:] if r[0] in suite_ids]
        self.assertEqual(mine, [
            [str(self.ids["donor"]), "'+Export " + m, "", "", f"'=a.{m}@example.test", "Yes"],
            [str(donors["org"]), f"'@Org {m}", f"Main {m}", "", f"main.{m}@example.test", "No"],
            [str(donors["org"]), f"'@Org {m}", f"'-Lee {m}", "Director", f"lee.{m}@example.test", "No"],
            [str(donors["org"]), f"'@Org {m}", "", "", f"info.{m}@example.test", "No"],
            [str(donors["fdn"]), f"Fdn {m}", f"Fran {m}", "", f"fran.{m}@example.test", "No"],
        ])
        for text in (f"dnc.{m}", f"dnc.contact.{m}", f"gone.{m}", f"gone.contact.{m}", f"hidden.{m}",
                     f"Blank {m}", f"Nbsp {m}", f"Solo {m}"):
            with self.subTest(left_out=text):
                self.assertNotIn(text, body)

    def test_emails_permissions(self):
        """Donors view is the rule: noexport (no donations view) and viewer get the file, nodonors gets 403;
        a bad CSRF token gets 403."""
        for role in ("noexport", "viewer", "staff"):
            with self.subTest(role=role):
                status, _, body = self.emails(self.login(role))
                self.assertEqual(status, 200, body[:300])
                self.assertIn(f"lee.{self.marker}@example.test", body)
        status, headers, body = self.emails(self.login("nodonors"))
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))
        manager = self.login("manager")
        status, headers, body = manager.request("exportData.php", {"export": "emails", "csrf_token": "0" * 64})
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))


    def donor_list(self, browser):
        """Requests the donor list export (UF2-136); posted dates are ignored."""
        return self.export(browser, {"export": "donor-list", "from": "1901-03-01", "to": "1901-03-31", "year": "1901"})

    def test_donor_list_export_file(self):
        """UF2-136: headers, filename date, every active donor once, order, status, main contact, labels, escaping."""
        before = fixture("today")
        status, headers, body = self.donor_list(self.login("manager"))
        after = fixture("today")
        self.assertEqual(status, 200, body[:300])
        self.assertEqual(headers["Content-Type"], "text/csv; charset=utf-8")
        self.assertIn(headers["Content-Disposition"],
                      {f'attachment; filename="fish-donor-list-{d}.csv"' for d in (before, after)})
        self.assertIn("no-store", headers["Cache-Control"])
        assert_no_php_errors_in(self, body)
        rows = parse_csv(body)
        self.assertEqual(rows[0], DONOR_LIST_HEADER)
        self.assertTrue(all(len(r) == len(DONOR_LIST_HEADER) for r in rows))
        ids = [int(r[0]) for r in rows[1:]]
        self.assertEqual(len(ids), len(set(ids)), "a donor appears twice")
        self.assertEqual(set(ids), set(fixture("active_ids")))   # every active donor, nothing else
        self.assertNotIn(999, ids)
        m, donors, st = self.marker, self.ids["email_donors"], self.ids["status_donors"]
        self.assertNotIn(donors["gone"], ids)
        # Fixed expected order (by display name; all start with a symbol or a capital so case cannot matter).
        order = [self.ids["donor"], donors["org"], st["active"], donors["dnc"], donors["fdn"], st["lybunt"],
                 donors["solo"], st["sybunt"]]
        self.assertEqual([i for i in ids if i in set(order)], order)
        by_id = {int(r[0]): r for r in rows[1:]}
        gifts = self.ids["status_gifts"]

        def row(donor_id, name, kind, *, email="", phone="", street="", city="", state="", zip_="", contact=("", "", ""),
                pref="", dnc="No", updates="No", receipts="No", status="Inactive", last="", since="1900-06-01"):
            return [str(donor_id), name, kind, email, phone, street, city, state, zip_, *contact, pref, dnc, updates,
                    receipts, status, last, since]

        expected = {
            self.ids["donor"]: row(self.ids["donor"], f"'+Export {m}", "Individual", email=f"  =a.{m}@example.test  ",
                                   city="Testville", state="VA", updates="Yes", last="1901-04-01"),
            st["active"]: row(st["active"], f"Active {m}", "Individual", email=f"active.{m}@example.test",
                              phone="540-555-0111", street="'=1 Main St", city="Warrenton", state="VA", zip_="20186",
                              pref="Mail", updates="Yes", receipts="Yes", status="Active", last=gifts["active"]),
            st["lybunt"]: row(st["lybunt"], f"Lybunt {m}", "Individual", status="LYBUNT", last=gifts["lybunt"]),
            st["sybunt"]: row(st["sybunt"], f"Sybunt {m}", "Individual", status="SYBUNT", last=gifts["sybunt"]),
            donors["org"]: row(donors["org"], f"'@Org {m}", "Organization", email="   ",
                               contact=(f"Main {m}", f"main.{m}@example.test", "'+1 540 555 0100")),
            donors["fdn"]: row(donors["fdn"], f"Fdn {m}", "Foundation",
                               contact=(f"Fran {m}", f"fran.{m}@example.test", "540-555-0102")),
            donors["solo"]: row(donors["solo"], f"Solo {m}", "Individual"),   # leftover main contact not shown
            donors["dnc"]: row(donors["dnc"], f"Dnc {m}", "Organization", email=f"dnc.{m}@example.test", dnc="Yes"),
        }
        for donor_id, want in expected.items():
            with self.subTest(donor=donor_id):
                self.assertEqual(by_id[donor_id], want)

    def test_donor_list_permissions(self):
        """Donors view is the rule: noexport (no donations view) and viewer get the file, nodonors gets 403;
        a bad CSRF token gets 403."""
        for role in ("noexport", "viewer", "staff"):
            with self.subTest(role=role):
                status, _, body = self.donor_list(self.login(role))
                self.assertEqual(status, 200, body[:300])
                self.assertIn(f"active.{self.marker}@example.test", body)
        status, headers, body = self.donor_list(self.login("nodonors"))
        self.assertEqual(status, 403)
        self.assertEqual(body, "You do not have access to the donor list export.")
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))
        manager = self.login("manager")
        status, headers, body = manager.request("exportData.php", {"export": "donor-list", "csrf_token": "0" * 64})
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)
        self.assertNotIn("text/csv", headers.get("Content-Type", ""))


if __name__ == "__main__":
    unittest.main(verbosity=2)
