"""Donation pages against the real tables. Run via `make test-donations` (needs `make seed-demo` first).
The suite creates and removes its own donors, campaigns, gifts and users; demo rows are never asserted on.
Timing against the Jira limits (UF2-54: 5 s, UF2-55: 2 s) is asserted only with DONATIONS_TIMING_STRICT=1."""

import datetime
import html
import json
import os
import re
import secrets
import subprocess
import sys
import time
import unittest
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

STRICT = os.environ.get("DONATIONS_TIMING_STRICT") == "1"
LIMITS = {"edit": 5.0, "profile": 2.0}
XSS_DONOR = "<img src=x onerror=alert(1)>"
XSS_CAMPAIGN = "</script><img src=x onerror=alert(1)>"

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
                  VALUES ('individual', ?, ?, 'Testville', 'VA', 'donation suite', '2020-01-15', ?)", 'ssi', $first, $last, $active);
            return (int) $con->insert_id;
        };
        $campaign = function ($name, $goal, $completed) use ($run, $con) {
            $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, description, completed) VALUES (?, ?, '2026-01-01', '2026-12-31', 'donation suite', ?)",
                 'sds', $name, $goal, $completed);
            return (int) $con->insert_id;
        };
        $out = ['donor' => $donor('Gift', $m, 1), 'xss' => $donor($req['xss'], $m . 'X', 1), 'inactive' => $donor('Gone', $m . 'I', 0)];
        $out['open'] = $campaign('Open ' . $m, 1000.00, '0');
        $out['open_null'] = $campaign('Nullstate ' . $m, 0, null);
        $out['done'] = $campaign('Done ' . $m, 500.00, '1');
        $out['done_yes'] = $campaign('Archived ' . $m, 500.00, 'yes');
        $out['xss_campaign'] = $campaign($req['xss_campaign'] . $m, 0, '0');
        // Legacy-shaped cash rows: no payment method, platform or date; one amount NULL, one 0.00.
        $run("INSERT INTO donations (donorID, amount, fee, reason, thanked) VALUES (?, NULL, 0.00, 'legacy null', 0)", 'i', $out['donor']);
        $out['legacy_null'] = (int) $con->insert_id;
        $run("INSERT INTO donations (donorID, amount, fee, reason, thanked) VALUES (?, 0.00, 10.00, 'legacy zero', 0)", 'i', $out['donor']);
        $out['legacy_zero'] = (int) $con->insert_id;
        // A cash gift on the finished campaign, for the edit-keeps-its-campaign case.
        $run("INSERT INTO donations (donorID, eventID, amount, donation_date, payment_method, platform, thanked) VALUES (?, ?, 40.00, '2026-02-02', 'cash', 'in_person', 0)",
             'ii', $out['donor'], $out['done']);
        $out['on_done'] = (int) $con->insert_id;
        // List donor: three gifts at known dates and values for the sort / range / search checks.
        $out['list'] = $donor('List', $m, 1);
        $day = function ($n) { return date('Y-m-d', strtotime("-$n days")); };
        $run("INSERT INTO donations (donorID, amount, donation_date, payment_method, platform, thanked) VALUES (?, 10.00, ?, 'cash', 'in_person', 0)", 'is', $out['list'], $day(5));
        $out['list_small'] = (int) $con->insert_id;
        $run("INSERT INTO donations (donorID, amount, donation_date, payment_method, platform, thanked) VALUES (?, 300.00, ?, 'check', 'in_person', 0)", 'is', $out['list'], $day(100));
        $out['list_big'] = (int) $con->insert_id;
        $run("INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, estimated_value, intended_use)
              VALUES (?, ?, ?, 'List items', 'other', 3, 50.00, 'Food pantry')", 'iis', $out['list'], $out['open'], $day(200));
        $out['list_items'] = (int) $con->insert_id;   // legacy shape: no weight_lbs
        // An items campaign, for the gifts tab and top-donors format (UF2-124).
        $out['items_campaign'] = $campaign('Items ' . $m, 0, '0');
        $run("UPDATE dbevents SET campaign_type = 'items' WHERE id = ?", 'i', $out['items_campaign']);
        break;
    case 'teardown':
        $donors = $req['donors'];
        $campaigns = $req['campaigns'];
        foreach ($donors as $id) {
            $run('DELETE FROM non_cash_donations WHERE donor_id = ?', 'i', $id);
            $run('DELETE FROM donations WHERE donorID = ?', 'i', $id);
        }
        foreach ($campaigns as $id) {
            $run('DELETE FROM donations WHERE eventID = ?', 'i', $id);
            $run('DELETE FROM dbevents WHERE id = ?', 'i', $id);
        }
        foreach ($donors as $id) { $run('DELETE FROM donors WHERE id = ?', 'i', $id); }
        break;
    case 'cash':
        $out = $run('SELECT donorID, eventID, amount, fee, reason, donation_date, `date`, payment_method, platform, check_number, is_signed,
                            deposit_date, in_honor_of, honoree_name, send_receipt, thanked, recorded_by FROM donations WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'noncash':
        $out = $run('SELECT donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, item_condition,
                            intended_use, notes, send_receipt, recorded_by FROM non_cash_donations WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'distribute':
        $run("INSERT INTO non_cash_distributions (non_cash_donation_id, distributed_on, quantity, program) VALUES (?, CURDATE(), ?, 'suite')",
             'id', $req['id'], $req['quantity']);
        break;
    case 'counts':
        $out = ['cash' => (int) $run('SELECT COUNT(*) AS n FROM donations')[0]['n'],
                'noncash' => (int) $run('SELECT COUNT(*) AS n FROM non_cash_donations')[0]['n']];
        break;
    case 'expected':
        // The same windows the pages use, computed with PHP's date (America/New_York, set by dbinfo.php).
        $first = date('Y-m-01'); $last = date('Y-m-t');
        $out = $run('SELECT (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE donation_date BETWEEN ? AND ?) AS cash_month,
                            (SELECT COUNT(*) FROM non_cash_donations WHERE date_received BETWEEN ? AND ?) AS noncash_month,
                            (SELECT COALESCE(SUM(estimated_value), 0) FROM non_cash_donations WHERE date_received BETWEEN ? AND ?) AS noncash_value,
                            (SELECT COUNT(*) FROM donations) + (SELECT COUNT(*) FROM non_cash_donations) AS recorded,
                            (SELECT COUNT(*) FROM donations WHERE donation_date >= ?) + (SELECT COUNT(*) FROM non_cash_donations WHERE date_received >= ?) AS last30',
                     'ssssssss', $first, $last, $first, $last, $first, $last, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-30 days')))[0];
        $out['today'] = date('Y-m-d');
        break;
    case 'campaign':
        $out = $run('SELECT (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE eventID = ?) AS raised,
                            (SELECT COUNT(*) FROM non_cash_donations WHERE campaign_id = ?) AS gifts,
                            (SELECT COALESCE(SUM(estimated_value), 0) FROM non_cash_donations WHERE campaign_id = ?) AS item_value',
                     'iii', $req['id'], $req['id'], $req['id'])[0];
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


def embedded(body, name):
    """The JSON value a page embeds as `const NAME = ...;`."""
    match = re.search(r"const " + name + r" = (.*?);\n", body)
    assert match, name
    return json.loads(match.group(1))


def field_value(body, label):
    """The read-only value shown under a label on viewDonation.php."""
    match = re.search(r"<label>" + re.escape(html.escape(label)) + r'</label><input value="(.*?)" readonly>', body)
    assert match, label
    return html.unescape(match.group(1))


class DonationPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-donations")
        prefix = "gift_test_" + secrets.token_hex(6)
        cls.users = {role: {"id": f"{prefix}_{role}", "name": f"Gift test {role}", "first": "Test", "last": role.capitalize(),
                            "password": "Gift9!" + secrets.token_hex(8), "role": role}
                     for role in ("viewer", "staff", "manager", "noview", "nodonors")}
        cls.users["noview"]["role"] = "staff"
        cls.users["nodonors"]["role"] = "staff"
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        # Edit without view must not read gifts; donors view removed hides donor figures.
        fixture("permission", user_id=cls.users["noview"]["id"], area="donations", can_view=0, can_edit=1, can_delete=0)
        fixture("permission", user_id=cls.users["nodonors"]["id"], area="donors", can_view=0, can_edit=0, can_delete=0)
        cls.marker = "Gs" + secrets.token_hex(5)
        cls.ids = fixture("setup", marker=cls.marker, xss=XSS_DONOR, xss_campaign=XSS_CAMPAIGN)
        cls.addClassCleanup(fixture, "teardown",
                            donors=[cls.ids[k] for k in ("donor", "xss", "inactive", "list")],
                            campaigns=[cls.ids[k] for k in ("open", "open_null", "done", "done_yes", "xss_campaign", "items_campaign")])
        cls.today = fixture("expected")["today"]
        cls.timings = []

    @classmethod
    def tearDownClass(cls):
        for label, seconds in cls.timings:
            print(f"timing {label}: {seconds:.3f}s (Jira limit {LIMITS[label]}s)")
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

    def post(self, browser, path, data, token_from=None):
        token = browser.token(token_from or "donations.php")
        status, headers, body = browser.request(path, {"csrf_token": token, **data})
        assert_no_php_errors_in(self, body)
        return status, headers, body

    def timed(self, label, seconds):
        self.timings.append((label, seconds))
        if STRICT:
            self.assertLess(seconds, LIMITS[label], f"{label} took {seconds:.2f}s")

    def days_ago(self, n):
        return (datetime.date.fromisoformat(self.today) - datetime.timedelta(days=n)).isoformat()

    def cash_form(self, **overrides):
        form = {"donor": str(self.ids["donor"]), "kind": "cash", "amount": "125.50", "fee": "3.25", "pay_type": "check",
                "platform": "in_person", "check_number": "1042", "signed": "yes", "deposit_date": self.today,
                "date_received": self.today, "campaign": str(self.ids["open"]), "in_honor": "on",
                "honoree_name": "Ruth " + self.marker, "notes": "Suite cash " + self.marker, "send_receipt": "on", "next": "done"}
        form.update(overrides)
        return {k: v for k, v in form.items() if v is not None}

    def noncash_form(self, **overrides):
        form = {"donor": str(self.ids["donor"]), "kind": "noncash", "item_description": "Suite cans " + self.marker,
                "category": "canned_food", "quantity": "24", "weight_lbs": "18.5", "estimated_value": "36.00",
                "condition": "new", "intended_use": "Food pantry", "date_received": self.today,
                "campaign": str(self.ids["open"]), "notes": "Suite items", "send_receipt": "on", "next": "done"}
        form.update(overrides)
        return {k: v for k, v in form.items() if v is not None}

    def saved_id(self, headers, kind):
        match = re.fullmatch(r"viewDonation\.php\?kind=" + kind + r"&id=(\d+)&saved=1", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        return int(match.group(1))

    # ---- access ----

    def test_anonymous_requests_redirect_and_posts_need_the_token(self):
        for path in ("donations.php", "addDonation.php", f"viewDonation.php?kind=cash&id={self.ids['legacy_null']}"):
            with self.subTest(path=path):
                status, headers, body = Browser().request(path)
                self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""))
        before = fixture("counts")
        manager = self.login("manager")
        self.assertEqual(manager.request("addDonation.php", self.cash_form())[0], 403)
        self.assertEqual(fixture("counts"), before)

    def test_permissions(self):
        before = fixture("counts")
        gift = f"kind=cash&id={self.ids['on_done']}"

        viewer = self.login("viewer")   # donations view only
        _, body = self.get(viewer, "donations.php?range=all&q=" + self.marker)
        self.assertIn('viewDonation.php?kind=cash', body)
        self.assertNotIn("+ New donation", body)
        self.assertNotIn('class="edit"', body)
        _, body = self.get(viewer, "viewDonation.php?" + gift)
        self.assertNotIn("Edit donation", body)
        _, body = self.get(viewer, "addDonation.php?" + gift)
        self.assertIn("cannot record or change donations", body)
        self.assertNotIn("40.00", body)
        _, body = self.get(viewer, f"donorProfile.php?id={self.ids['donor']}")
        self.assertNotIn("+ Add cash gift", body)
        _, body = self.get(viewer, "dashboard.php")
        self.assertNotIn("+Add donation", body)
        self.assertNotIn("+Add Donor", body)
        self.assertEqual(self.post(viewer, "addDonation.php", self.cash_form())[0], 403)

        noview = self.login("noview")   # donations edit without view
        _, body = self.get(noview, "donations.php")
        self.assertIn("cannot view donations", body)
        self.assertNotIn("<tbody>", body)
        _, body = self.get(noview, "viewDonation.php?" + gift)
        self.assertIn("cannot view donations", body)
        self.assertNotIn("40.00", body)
        _, body = self.get(noview, "addDonation.php?" + gift)
        self.assertIn("cannot record or change donations", body)
        self.assertNotIn("40.00", body)
        self.assertNotIn("CAMPAIGNS", body)
        self.assertEqual(self.post(noview, "addDonation.php?" + gift, self.cash_form())[0], 403)
        _, body = self.get(noview, f"donorProfile.php?id={self.ids['donor']}")
        self.assertIn("Your account cannot view donations.", body)
        self.assertNotIn('<span class="count">', body.split('<label for="tVol">')[0])
        self.assertNotIn("viewDonation.php", body)

        nodonors = self.login("nodonors")   # donations view and edit, donors view removed
        _, body = self.get(nodonors, "viewDonation.php?" + gift)
        self.assertIn("Gift " + self.marker, body)
        self.assertNotIn("View donor", body)
        self.assertNotIn("Lifetime cash", body)
        _, body = self.get(nodonors, "donations.php?range=all&q=" + self.marker)
        self.assertNotIn("donorProfile.php?id=", body)
        _, body = self.get(nodonors, "addDonation.php")
        self.assertIn("cannot record or change donations", body)

        staff = self.login("staff")
        self.assertEqual(staff.request("addDonation.php", self.cash_form())[0], 403)   # no form token
        self.assertEqual(fixture("counts"), before)

    # ---- add ----

    def test_add_cash_and_view_it(self):
        staff = self.login("staff")
        status, headers, _ = self.post(staff, "addDonation.php", self.cash_form())
        self.assertEqual(status, 303)
        gift_id = self.saved_id(headers, "cash")
        row = fixture("cash", id=gift_id)
        self.assertEqual(row, {"donorID": self.ids["donor"], "eventID": self.ids["open"], "amount": "125.50", "fee": "3.25",
                               "reason": "Suite cash " + self.marker, "donation_date": self.today,
                               "date": datetime.date.fromisoformat(self.today).strftime("%m/%d/%Y"),
                               "payment_method": "check", "platform": "in_person", "check_number": "1042", "is_signed": 1,
                               "deposit_date": self.today, "in_honor_of": 1, "honoree_name": "Ruth " + self.marker,
                               "send_receipt": 1, "thanked": 0, "recorded_by": self.users["staff"]["id"]})
        _, body = self.get(staff, headers["Location"])
        self.assertIn("Donation saved.", body)
        self.assertEqual(field_value(body, "Amount"), "$125.50")
        self.assertEqual(field_value(body, "Net amount"), "$122.25")
        self.assertEqual(field_value(body, "Check number"), "1042")
        self.assertEqual(field_value(body, "Platform"), "In person")
        self.assertEqual(field_value(body, "In honor of"), "Ruth " + self.marker)
        self.assertEqual(field_value(body, "Recorded by"), "Test S.")
        self.assertIn('href="donations.php" class="btn btn-outline small">Close</a>', body)
        self.assertIn(f'addDonation.php?kind=cash&amp;id={gift_id}', body)

        # Backdated and minimal: check-only fields are dropped for cash payments; empty optionals are NULL.
        status, headers, _ = self.post(staff, "addDonation.php", self.cash_form(
            amount="20", fee="", pay_type="cash", platform="mighty_cause", date_received=self.days_ago(75), campaign="",
            in_honor=None, honoree_name="ignored", notes="", send_receipt=None))
        self.assertEqual(status, 303)
        row = fixture("cash", id=self.saved_id(headers, "cash"))
        self.assertEqual((row["amount"], row["fee"], row["eventID"], row["check_number"], row["is_signed"], row["deposit_date"],
                          row["in_honor_of"], row["honoree_name"], row["reason"], row["send_receipt"], row["donation_date"]),
                         ("20.00", None, None, None, None, None, 0, None, None, 0, self.days_ago(75)))
        _, body = self.get(staff, headers["Location"])
        self.assertEqual(field_value(body, "Processing fee"), "Not recorded")

        _, body = self.get(staff, "donations.php?range=all&kind=cash&q=" + urllib.parse.quote("Gift " + self.marker))
        self.assertIn(f"viewDonation.php?kind=cash&amp;id={gift_id}", body)

    def test_add_noncash(self):
        staff = self.login("staff")
        status, headers, _ = self.post(staff, "addDonation.php", self.noncash_form())
        self.assertEqual(status, 303)
        gift_id = self.saved_id(headers, "noncash")
        self.assertEqual(fixture("noncash", id=gift_id), {
            "donor_id": self.ids["donor"], "campaign_id": self.ids["open"], "date_received": self.today,
            "item_description": "Suite cans " + self.marker, "category": "canned_food", "quantity": "24.00", "weight_lbs": "18.50",
            "estimated_value": "36.00", "item_condition": "new", "intended_use": "Food pantry", "notes": "Suite items",
            "send_receipt": 1, "recorded_by": self.users["staff"]["id"]})
        _, body = self.get(staff, headers["Location"])
        self.assertEqual(field_value(body, "Intended use / program"), "Food pantry")
        self.assertEqual(field_value(body, "Quantity"), "24 items · 18.5 lbs")

        status, headers, _ = self.post(staff, "addDonation.php", self.noncash_form(
            estimated_value="", condition="", campaign="", notes="", send_receipt=None, weight_lbs="7", quantity="2.5"))
        self.assertEqual(status, 303)
        row = fixture("noncash", id=self.saved_id(headers, "noncash"))
        self.assertEqual((row["estimated_value"], row["item_condition"], row["campaign_id"], row["notes"], row["send_receipt"], row["weight_lbs"], row["quantity"]),
                         (None, None, None, None, 0, "7.00", "2.50"))

    def test_validation_writes_nothing(self):
        staff = self.login("staff")
        before = fixture("counts")
        cases = [
            (self.cash_form(amount=""), "Amount is required."),
            (self.cash_form(amount="0"), "Amount must be at least 0.01."),
            (self.cash_form(amount="10.555"), "Amount must be a number with at most two decimal places."),
            (self.cash_form(amount="1e3"), "Amount must be a number with at most two decimal places."),
            (self.cash_form(fee="200"), "Fee cannot be more than the amount."),
            (self.cash_form(pay_type=""), "Choose a payment type."),
            (self.cash_form(platform="paypal"), "Choose a platform."),
            (self.cash_form(in_honor="on", honoree_name=""), "Enter who the gift is in honor of."),
            (self.cash_form(signed="maybe"), "Choose whether the check is signed."),
            (self.cash_form(date_received=(datetime.date.fromisoformat(self.today) + datetime.timedelta(days=1)).isoformat()), "Date received cannot be in the future."),
            (self.cash_form(date_received="2999-01-01"), "Enter a valid date received."),
            (self.cash_form(date_received="0026-10-21"), "Enter a valid date received."),
            (self.cash_form(date_received=""), "Date received is required."),
            (self.cash_form(campaign=str(self.ids["done"])), "Choose a campaign from the list."),
            (self.cash_form(campaign=str(self.ids["done_yes"])), "Choose a campaign from the list."),
            (self.cash_form(donor=str(self.ids["inactive"])), "Choose an active donor from the list."),
            (self.cash_form(donor="999"), "Choose an active donor from the list."),
            (self.cash_form(donor=""), "Choose a donor."),
            (self.cash_form(kind="gift"), "Choose cash or non-cash."),
            (self.noncash_form(intended_use=""), "Intended use / program is required."),
            (self.noncash_form(item_description=""), "Item description is required."),
            (self.noncash_form(category=""), "Choose a category."),
            (self.noncash_form(quantity="0.004"), "Quantity must be a number with at most two decimal places."),
            (self.noncash_form(quantity="0"), "Quantity must be at least 0.01."),
            (self.noncash_form(weight_lbs=None), "Pounds is required."),
            (self.noncash_form(weight_lbs=""), "Pounds is required."),
            (self.noncash_form(weight_lbs="0"), "Pounds must be at least 0.01."),
            (self.noncash_form(weight_lbs="-1"), "Pounds must be a number with at most two decimal places."),
            (self.noncash_form(weight_lbs="abc"), "Pounds must be a number with at most two decimal places."),
            (self.noncash_form(weight_lbs="1.234"), "Pounds must be a number with at most two decimal places."),
        ]
        for form, message in cases:
            with self.subTest(message=message, form={k: form[k] for k in form if k in ("amount", "fee", "date_received", "donor", "kind", "quantity")}):
                status, _, body = self.post(staff, "addDonation.php", form)
                self.assertEqual(status, 200)
                self.assertIn(html.escape(message, quote=False), body)
        # Arrays never reach the validators as values.
        token = staff.token("donations.php")
        status, _, body = staff.request("addDonation.php", [("csrf_token", token)] + list(self.cash_form(amount=None).items())
                                        + [("amount[]", "1"), ("donor[]", "1")])
        self.assertEqual(status, 200)
        self.assertIn("Amount is required.", body)
        # The re-rendered form keeps the values and the chosen donor.
        status, _, body = self.post(staff, "addDonation.php", self.cash_form(amount="", notes="kept " + self.marker))
        self.assertIn('value="kept ' + self.marker + '"', body)
        self.assertEqual(embedded(body, "CHOSEN_DONOR")["id"], self.ids["donor"])
        self.assertEqual(fixture("counts"), before)

    # ---- edit ----

    def test_edit_updates_one_row_and_keeps_legacy_values(self):
        manager = self.login("manager")
        staff = self.login("staff")
        status, headers, _ = self.post(staff, "addDonation.php", self.cash_form(date_received=self.days_ago(400)))
        gift_id = self.saved_id(headers, "cash")
        _, body = self.get(manager, f"addDonation.php?kind=cash&id={gift_id}")
        self.assertIn("Edit donation", body)
        self.assertIn('value="125.50"', body)
        self.assertNotIn('value="noncash"', body)
        self.assertEqual(embedded(body, "STORED"), {"campaign": self.ids["open"], "value": 125.5})

        start = time.monotonic()
        status, headers, _ = self.post(manager, f"addDonation.php?kind=cash&id={gift_id}", self.cash_form(
            amount="150", date_received=self.days_ago(400), notes="edited " + self.marker))
        self.assertEqual((status, headers["Location"]), (303, f"viewDonation.php?kind=cash&id={gift_id}&saved=1"))
        _, body = self.get(manager, headers["Location"])
        self.assertEqual(field_value(body, "Amount"), "$150.00")
        _, body = self.get(manager, "donations.php?range=all&q=" + urllib.parse.quote("Gift " + self.marker))
        self.timed("edit", time.monotonic() - start)   # UF2-54: save to the donations page showing it
        self.assertIn(f"viewDonation.php?kind=cash&amp;id={gift_id}", body)
        self.assertIn("$150.00", body)
        start = time.monotonic()
        _, body = self.get(manager, f"donorProfile.php?id={self.ids['donor']}")
        self.timed("profile", time.monotonic() - start)   # UF2-55: the edited gift on the donor profile
        self.assertIn("$150.00", body)
        row = fixture("cash", id=gift_id)
        self.assertEqual((row["amount"], row["reason"], row["recorded_by"]), ("150.00", "edited " + self.marker, self.users["staff"]["id"]))

        # Edit mode takes the kind from the URL: a posted kind=noncash still updates this cash row only.
        before = fixture("counts")
        status, headers, _ = self.post(manager, f"addDonation.php?kind=cash&id={gift_id}", {**self.cash_form(
            amount="151", date_received=self.days_ago(400)), "kind": "noncash", "item_description": "x", "intended_use": "x"})
        self.assertEqual((status, headers["Location"]), (303, f"viewDonation.php?kind=cash&id={gift_id}&saved=1"))
        self.assertEqual(fixture("cash", id=gift_id)["amount"], "151.00")
        self.assertEqual(fixture("counts"), before)

        # Edit mode ignores a posted kind and refuses to move the gift to the anonymous donor.
        status, _, body = self.post(manager, f"addDonation.php?kind=cash&id={gift_id}", {**self.noncash_form(), "kind": "noncash", "donor": "999"})
        self.assertEqual(status, 200)
        self.assertIn("Choose an active donor from the list.", body)
        self.assertEqual(fixture("cash", id=gift_id)["donorID"], self.ids["donor"])

        # Legacy rows: NULL amount / method / platform / date and 0.00 amount keep their values when only notes change.
        for key, amount, fee in (("legacy_null", "", "0.00"), ("legacy_zero", "0.00", "10.00")):
            with self.subTest(row=key):
                _, body = self.get(manager, f"viewDonation.php?kind=cash&id={self.ids[key]}")
                self.assertEqual(field_value(body, "Type"), "Not recorded")
                self.assertEqual(field_value(body, "Date received"), "Not recorded")
                status, headers, _ = self.post(manager, f"addDonation.php?kind=cash&id={self.ids[key]}", {
                    "donor": str(self.ids["donor"]), "amount": amount, "fee": fee, "pay_type": "", "platform": "",
                    "date_received": "", "campaign": "", "notes": "legacy edited", "next": "done"})
                self.assertEqual(status, 303, key)
                row = fixture("cash", id=self.ids[key])
                self.assertEqual((row["amount"], row["payment_method"], row["platform"], row["donation_date"], row["reason"]),
                                 (amount or None, None, None, None, "legacy edited"))

        # A finished campaign stays selectable on the gift that already uses it.
        _, body = self.get(manager, f"addDonation.php?kind=cash&id={self.ids['on_done']}")
        self.assertIn(str(self.ids["done"]), embedded(body, "CAMPAIGNS"))

        # Non-cash quantity cannot drop below what was already given out.
        status, headers, _ = self.post(staff, "addDonation.php", self.noncash_form(quantity="10"))
        nc_id = self.saved_id(headers, "noncash")
        fixture("distribute", id=nc_id, quantity=6)
        status, _, body = self.post(manager, f"addDonation.php?kind=noncash&id={nc_id}", self.noncash_form(quantity="5"))
        self.assertEqual(status, 200)
        self.assertIn("Quantity cannot be less than the 6 already given out.", body)
        self.assertEqual(fixture("noncash", id=nc_id)["quantity"], "10.00")

    # ---- pounds (UF2-124) ----

    def test_noncash_pounds(self):
        manager = self.login("manager")
        staff = self.login("staff")
        _, body = self.get(staff, "addDonation.php?kind=noncash")
        self.assertNotIn('name="unit"', body)
        self.assertIn('name="weight_lbs"', body)

        # A row recorded before UF2-124 has no weight: it shows items only.
        legacy = self.ids["list_items"]
        _, body = self.get(staff, "donations.php?range=all&q=" + urllib.parse.quote("List " + self.marker))
        self.assertIn("3 items &middot; est. $50.00", body)
        _, body = self.get(staff, f"viewDonation.php?kind=noncash&id={legacy}")
        self.assertEqual(field_value(body, "Quantity"), "3 items")
        # Editing it requires Pounds; with Pounds it saves.
        edit = self.noncash_form(donor=str(self.ids["list"]), item_description="List items", category="other", quantity="3",
                                 estimated_value="50.00", date_received=self.days_ago(200), weight_lbs=None)
        status, _, body = self.post(manager, f"addDonation.php?kind=noncash&id={legacy}", edit)
        self.assertEqual(status, 200)
        self.assertIn("Pounds is required.", body)
        self.assertIsNone(fixture("noncash", id=legacy)["weight_lbs"])
        status, headers, _ = self.post(manager, f"addDonation.php?kind=noncash&id={legacy}", {**edit, "weight_lbs": "6"})
        self.assertEqual((status, headers["Location"]), (303, f"viewDonation.php?kind=noncash&id={legacy}&saved=1"))
        self.assertEqual(fixture("noncash", id=legacy)["weight_lbs"], "6.00")

        # The donor profile and an items campaign show the same format.
        status, headers, _ = self.post(staff, "addDonation.php", self.noncash_form(campaign=str(self.ids["items_campaign"])))
        self.assertEqual(status, 303)
        _, body = self.get(staff, f"donorProfile.php?id={self.ids['donor']}")
        self.assertIn("<strong>24 items · 18.5 lbs</strong>", body)
        _, body = self.get(staff, f"campaign.php?id={self.ids['items_campaign']}&kind=noncash")
        self.assertIn("<td>24 items · 18.5 lbs · Suite cans " + self.marker + "</td>", body)
        top = body[body.index("<h3>Top donors</h3>"):]
        self.assertIn("<strong>24 items</strong>", top)

    # ---- from the donor profile (UF2-87, UF2-55) ----

    def test_profile_buttons_prefill_and_return(self):
        staff = self.login("staff")
        donor = self.ids["donor"]
        _, body = self.get(staff, f"donorProfile.php?id={donor}")
        self.assertIn(f'addDonation.php?donor={donor}&amp;kind=cash&amp;from=profile', body)
        self.assertIn(f'addDonation.php?donor={donor}&amp;kind=noncash&amp;from=profile', body)
        _, body = self.get(staff, f"addDonation.php?donor={donor}&kind=noncash&from=profile")
        chosen = embedded(body, "CHOSEN_DONOR")
        self.assertEqual((chosen["id"], chosen["name"]), (donor, "Gift " + self.marker))
        self.assertIn('value="noncash" checked', body)
        self.assertRegex(body, r'<fieldset class="step details" id="itemDetails">')
        self.assertRegex(body, r'<fieldset class="step details" id="cashDetails" hidden disabled>')
        self.assertIn('name="from" value="profile"', body)
        # Old links (donor_id) still preselect; anonymous and inactive donors do not.
        _, body = self.get(staff, f"addDonation.php?donor_id={donor}")
        self.assertEqual(embedded(body, "CHOSEN_DONOR")["id"], donor)
        for other in (999, self.ids["inactive"], "abc"):
            _, body = self.get(staff, f"addDonation.php?donor={other}")
            self.assertIsNone(embedded(body, "CHOSEN_DONOR"))

        start = time.monotonic()
        status, headers, _ = self.post(staff, "addDonation.php?from=profile", {**self.noncash_form(item_description="Profile " + self.marker), "from": "profile"})
        self.assertEqual((status, headers["Location"]), (303, f"donorProfile.php?id={donor}&gift=1"))
        _, body = self.get(staff, headers["Location"])
        self.timed("profile", time.monotonic() - start)
        self.assertIn("Donation saved.", body)
        self.assertIn("Profile " + self.marker, body)

        status, headers, _ = self.post(staff, "addDonation.php", {**self.cash_form(), "next": "another", "from": "profile"})
        self.assertEqual((status, headers["Location"]), (303, f"addDonation.php?donor={donor}&kind=cash&saved=1&from=profile"))

    # ---- campaigns (UF2-52) ----

    def test_campaign_card_data(self):
        staff = self.login("staff")
        _, body = self.get(staff, "addDonation.php")
        campaigns = embedded(body, "CAMPAIGNS")
        for key in ("open", "open_null", "xss_campaign"):
            self.assertIn(str(self.ids[key]), campaigns, key)
        for key in ("done", "done_yes"):
            self.assertNotIn(str(self.ids[key]), campaigns, key)
        self.post(staff, "addDonation.php", self.cash_form(amount="60", fee=""))
        self.post(staff, "addDonation.php", self.noncash_form(estimated_value="15"))
        _, body = self.get(staff, "addDonation.php")
        got = embedded(body, "CAMPAIGNS")[str(self.ids["open"])]
        want = fixture("campaign", id=self.ids["open"])
        self.assertEqual((got["raised"], got["gifts"], got["item_value"], got["goal"]),
                         (float(want["raised"]), int(want["gifts"]), float(want["item_value"]), 1000.0))
        self.assertGreater(got["gifts"], 0)
        self.assertGreater(got["raised"], 0)
        self.assertNotIn("Thanksgiving Food Drive", body)
        # The campaign name cannot end the script block.
        script = re.search(r"const CAMPAIGNS = (.*?);\n", body).group(1)
        self.assertNotIn("</script", script)
        self.assertEqual(embedded(body, "CAMPAIGNS")[str(self.ids["xss_campaign"])]["name"], XSS_CAMPAIGN + self.marker)

    # ---- list (UF2-42..45) ----

    def test_list_tiles_filters_and_junk(self):
        staff = self.login("staff")
        expected = fixture("expected")
        _, body = self.get(staff, "donations.php")
        self.assertIn(f"${float(expected['cash_month']):,.2f}", body)
        self.assertIn(f"{int(expected['noncash_month']):,} gift", body)
        self.assertIn(f"est. ${float(expected['noncash_value']):,.2f}", body)
        self.assertIn(f"{int(expected['recorded']):,}</p>", body)
        self.assertIn(f"of {int(expected['last30']):,} donations", body)
        self.assertNotIn("FAKE", body)

        gifts = lambda body: re.findall(r"viewDonation\.php\?kind=(\w+)&amp;id=(\d+)", body)
        small, big, items = ("cash", str(self.ids["list_small"])), ("cash", str(self.ids["list_big"])), ("noncash", str(self.ids["list_items"]))
        lq = urllib.parse.quote("List " + self.marker)
        for sort, order in (("newest", [small, big, items]), ("oldest", [items, big, small]), ("amount", [big, items, small])):
            with self.subTest(sort=sort):
                _, body = self.get(staff, f"donations.php?range=all&sort={sort}&q={lq}")
                self.assertEqual(gifts(body), order)
        jan1 = self.today[:4] + "-01-01"
        in_year = [g for g, days in ((small, 5), (big, 100), (items, 200)) if self.days_ago(days) >= jan1]
        for rng, want in (("30", [small]), ("90", [small]), ("year", in_year), ("all", [small, big, items])):
            with self.subTest(range=rng):
                _, body = self.get(staff, f"donations.php?range={rng}&q={lq}")
                self.assertEqual(gifts(body), want)
        _, body = self.get(staff, "donations.php?range=all&q=" + urllib.parse.quote("Open " + self.marker))
        self.assertIn(items, gifts(body))   # search matches the campaign name
        _, body = self.get(staff, f"donations.php?range=all&kind=cash&sort=amount&q={lq}")
        pager = re.findall(r'href="(\?[^"]*page=\d+[^"]*)"', body)
        self.assertTrue(pager)
        for link in pager:
            params = urllib.parse.parse_qs(html.unescape(link)[1:])
            self.assertEqual((params["kind"], params["range"], params["sort"], params["q"]), (["cash"], ["all"], ["amount"], ["List " + self.marker]))

        q = urllib.parse.quote("Gift " + self.marker)
        _, body = self.get(staff, f"donations.php?range=all&kind=cash&q={q}")
        self.assertNotIn("row-noncash", body)
        _, body = self.get(staff, f"donations.php?range=all&kind=noncash&q={q}")
        self.assertNotIn("row-cash", body)
        for junk in ("kind=x", "range[]=1", "page=-5", "page=999", "sort=drop", "q[]=a", "saved=1&page=2"):
            with self.subTest(junk=junk):
                _, body = self.get(staff, "donations.php?" + junk)
                self.assertNotIn("saved=1", body)
        for junk in ("kind=x&id=1", "kind=cash&id=abc", "kind=cash&id=999999999", "kind[]=cash&id=1"):
            with self.subTest(junk=junk):
                self.assertEqual(self.get(staff, "viewDonation.php?" + junk, status=303)[0]["Location"], "donations.php")
                self.assertEqual(self.get(staff, "addDonation.php?" + junk, status=303)[0]["Location"], "donations.php")

    def test_names_render_as_text(self):
        staff = self.login("staff")
        status, headers, _ = self.post(staff, "addDonation.php", self.cash_form(donor=str(self.ids["xss"]), campaign=str(self.ids["xss_campaign"])))
        gift_id = self.saved_id(headers, "cash")
        for path in (headers["Location"], "donations.php?range=all&q=" + self.marker, f"addDonation.php?kind=cash&id={gift_id}",
                     f"addDonation.php?donor={self.ids['xss']}", f"donorProfile.php?id={self.ids['xss']}"):
            with self.subTest(path=path):
                _, body = self.get(staff, path)
                self.assertNotIn("<img src=x", body)

    # ---- UF2-49: donor search and "Save & add donation" ----

    def test_donor_lookup_and_save_add(self):
        status, _, body = self.login("nodonors").request("donorLookup.php?q=" + self.marker)
        self.assertEqual((status, json.loads(body)["success"]), (403, False))
        staff = self.login("staff")
        lookup = lambda q: json.loads(staff.request("donorLookup.php?q=" + urllib.parse.quote(q))[2])
        self.assertEqual(lookup("a"), {"success": True, "donors": []})
        found = lookup(self.marker)["donors"]
        ids = [d["id"] for d in found]
        self.assertIn(self.ids["donor"], ids)
        self.assertNotIn(self.ids["inactive"], ids)
        self.assertEqual(set(found[0]), {"id", "name", "type", "town", "since", "cash", "items", "last_gift", "hours"})
        self.assertNotIn(999, [d["id"] for d in lookup("anonymous")["donors"]])

        token = staff.token("addDonor.php")
        status, headers, _ = staff.request("addDonor.php", {
            "csrf_token": token, "type": "Individual", "first": "Saveadd", "last": self.marker, "email": "",
            "phone": "", "preferred": "", "street": "", "city": "", "state": "", "zip": "", "action": "save_add"})
        self.assertEqual(status, 303)
        match = re.fullmatch(r"addDonation\.php\?donor=(\d+)", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        self.addCleanup(fixture, "teardown", donors=[int(match.group(1))], campaigns=[])
        _, body = self.get(staff, headers["Location"])
        self.assertEqual(embedded(body, "CHOSEN_DONOR")["name"], "Saveadd " + self.marker)
        # Without the donation-write permission the button is not offered and the save goes to the profile.
        noview = self.login("noview")
        _, body = self.get(noview, "addDonor.php")
        self.assertNotIn('value="save_add"', body)

    # ---- other pages touched by this change ----

    def test_dashboard_and_legacy_event_pages(self):
        manager = self.login("manager")
        _, body = self.get(manager, "dashboard.php")
        self.assertIn('href="addDonor.php"', body)
        self.assertNotIn("newDonor.php", body)
        self.assertIn('href="addDonation.php"', body)
        # Legacy CASA pages that read fetch_donations_for_event() still load.
        for path in (f"specificEvent.php?id={self.ids['open']}", "viewAllEvents.php", "date.php?date=2026-01-01"):
            with self.subTest(path=path):
                self.get(manager, path)


if __name__ == "__main__":
    unittest.main(verbosity=2)
