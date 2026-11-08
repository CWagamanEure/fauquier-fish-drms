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
switch ($req['action']) {
    case 'clean':
        // Leftovers of an earlier run that stopped before its cleanup: rows tied to the suite's fixed prefix.
        $p = $req['prefix'] . '%';
        $run("DELETE d FROM donations d LEFT JOIN donors o ON o.id = d.donorID LEFT JOIN dbevents e ON e.id = d.eventID
              WHERE $outside AND (o.`last` LIKE ? OR e.name LIKE ?)", 'ss', $p, '@' . $p);
        $run("DELETE FROM dbevents WHERE name LIKE ?", 's', '@' . $p);
        $run("DELETE FROM donors WHERE `last` LIKE ?", 's', $p);
        $run("DELETE FROM user_permissions WHERE user_id LIKE 'export\\_test\\_%'");
        $run("DELETE FROM dbpersons WHERE id LIKE 'export\\_test\\_%'");
        $out = ['left' => (int) $run("SELECT COUNT(*) AS n FROM donations d WHERE $outside")[0]['n'],
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
        break;
    case 'teardown':
        $run('DELETE FROM donations WHERE donorID = ? OR eventID = ?', 'ii', $req['donor'], $req['campaign']);
        $run('DELETE FROM dbevents WHERE id = ?', 'i', $req['campaign']);
        $run('DELETE FROM donors WHERE id = ?', 'i', $req['donor']);
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
    result = subprocess.run(["php", "-r", FIXTURES_PHP], input=json.dumps({"action": action, **payload}),
                            text=True, check=True, cwd=ROOT, capture_output=True)
    return json.loads(result.stdout)


def parse_csv(body):
    assert body.startswith("﻿"), body[:40]
    return list(csv.reader(io.StringIO(body[1:], newline="")))


class CashExportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        state = fixture("clean", prefix=PREFIX)
        if state["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-exports")
        if state["left"] != 0:
            raise AssertionError("donations dated in 1901 or on 1900-01-01..02 exist that this suite did not make; "
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
        cls.addClassCleanup(fixture, "teardown", donor=cls.ids["donor"], campaign=cls.ids["campaign"])

    def login(self, role):
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
        return self.export(browser, {"export": "cash", "from": start, "to": end})

    def test_cash_export_file(self):
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
        status, _, body = self.cash(self.login("manager"), "1900-01-01", "1900-01-02")
        self.assertEqual(status, 200)
        self.assertEqual(body, "﻿" + ",".join(f'"{h}"' if " " in h else h for h in HEADER) + "\r\n")

    def test_bad_dates_redirect_without_a_file(self):
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
        for role in ("manager", "noexport"):
            browser = self.login(role)
            for name in ("emails", "noncash", "junk", ""):
                with self.subTest(role=role, export=name):
                    status, headers, _ = self.export(browser, {"export": name})
                    self.assertEqual(status, 303)
                    self.assertEqual(headers["Location"], "dataImports.php?export_error=unavailable#export")

    def test_permissions(self):
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
        manager = self.login("manager")
        status, headers, body = manager.request("exportData.php")
        self.assertEqual((status, body), (405, ""))
        self.assertEqual(headers["Allow"], "POST")
        status, headers, body = manager.request("exportData.php", {"export": "cash", "from": "1901-03-01",
                                                                    "to": "1901-03-31", "csrf_token": "0" * 64})
        self.assertEqual(status, 403)
        self.assertNotIn(self.marker, body)

    def test_page_buttons_and_messages(self):
        _, _, body = Browser.request(self.login("staff"), "dataImports.php")
        assert_no_php_errors_in(self, body)
        self.assertEqual(body.count('action="exportData.php"'), 1)
        self.assertEqual(body.count(">Coming soon</button>"), 7)
        self.assertNotIn(">No access</button>", body)
        self.assertIn('id="export"', body)
        _, _, body = self.login("noexport").request("dataImports.php")
        self.assertIn(">No access</button>", body)
        self.assertEqual(body.count(">Export CSV</button>"), 0)
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


if __name__ == "__main__":
    unittest.main(verbosity=2)
