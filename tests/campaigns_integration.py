"""Campaign pages against the real tables, and the donation / hours forms that feed them.
Run via `make test-campaigns` (add the target next to test-hours). The suite creates and removes its own
users, donors, campaigns, gifts, hours and communications; demo rows are never asserted on."""

import datetime
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
FORM_EXPIRED = "form has expired"

# name: (role, {area: (view, edit, delete)} overriding the role preset)
USERS = {
    "viewer": ("viewer", {}),
    "staff": ("staff", {}),                                  # preset: campaigns view only
    "manager": ("manager", {}),
    "nocamp": ("staff", {"campaigns": (0, 0, 0)}),
    "editonly": ("staff", {"campaigns": (0, 1, 0)}),         # edit without view must not reach the form
    "granted": ("staff", {"campaigns": (1, 1, 0)}),          # a row overrides the preset
    "nogifts": ("manager", {"donations": (0, 0, 0)}),
    "nodonors": ("manager", {"donors": (0, 0, 0)}),
    "nocomms": ("manager", {"communications": (0, 0, 0)}),
}

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
        $day = function ($n) { return date('Y-m-d', strtotime("$n days")); };
        $campaign = function ($name, $type, $goal, $itemGoal, $unit, $volGoal, $start, $end, $completed) use ($run, $con) {
            $run("INSERT INTO dbevents (name, campaign_type, goalAmount, item_goal, item_unit, volunteer_goal, startDate, endDate,
                                        startTime, endTime, description, completed, location)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, '00:00', '23:59', 'campaign suite', ?, 'FISH pantry')",
                 'ssddsisss', $name, $type, $goal, $itemGoal, $unit, $volGoal, $start, $end, $completed);
            return (int) $con->insert_id;
        };
        $run("INSERT INTO donors (donor_type, `first`, `last`, city, state, notes, donor_since)
              VALUES ('individual', 'Camp', ?, 'Testville', 'VA', 'campaign suite', '2020-01-15')", 's', $m);
        $out = ['donor' => (int) $con->insert_id];
        $out['open']  = $campaign('Open camp ' . $m, 'both', 1000, 100, 'cans', 10, $day(-10), $day(20), '0');
        $out['ended'] = $campaign('Ended camp ' . $m, 'cash', 500, null, 'items', null, $day(-30), $day(-5), '0');
        $out['done']  = $campaign('Done camp ' . $m, 'items', null, 50, 'kits', null, $day(-60), $day(60), '1');
        $out['flow']  = $campaign('Flow camp ' . $m, 'cash', 500, null, 'items', null, $day(-1), $day(30), '0');
        $out['xss']   = $campaign($req['xss'] . $m, 'cash', null, null, 'items', null, $day(-1), $day(30), '0');
        // Activity on the "open" campaign: $100 + $20 anonymous cash, 5 items (4.5 lbs), one 2.25 h shift, one message.
        $run("INSERT INTO donations (donorID, eventID, amount, donation_date, payment_method, platform, thanked, reason)
              VALUES (?, ?, 100.00, ?, 'cash', 'in_person', 0, 'campaign suite')", 'iis', $out['donor'], $out['open'], $day(-3));
        $run("INSERT INTO donations (donorID, eventID, amount, donation_date, payment_method, platform, thanked, reason)
              VALUES (999, ?, 20.00, ?, 'cash', 'in_person', 0, 'campaign suite')", 'is', $out['open'], $day(-2));
        $run("INSERT INTO non_cash_donations (donor_id, campaign_id, date_received, item_description, category, quantity, weight_lbs, estimated_value, intended_use, send_receipt)
              VALUES (?, ?, ?, ?, 'canned_food', 5, 4.50, 10.00, 'Food pantry', 0)", 'iiss', $out['donor'], $out['open'], $day(-1), 'Camp cans ' . $m);
        $run("INSERT INTO volunteer_hours (donor_id, campaign_id, activity_date, hours, job) VALUES (?, ?, ?, 2.25, 'Camp sorting')",
             'iis', $out['donor'], $out['open'], $day(-2));
        $run("INSERT INTO communications (donor_id, campaign_id, occurred_at, comm_type, subject, status) VALUES (?, ?, NOW(), 'email', ?, 'sent')",
             'iis', $out['donor'], $out['open'], 'Camp mailing ' . $m);
        break;
    case 'teardown':
        foreach ($req['donors'] as $id) {
            foreach (['communications' => 'donor_id', 'volunteer_hours' => 'donor_id', 'non_cash_donations' => 'donor_id', 'donations' => 'donorID'] as $t => $c) {
                $run("DELETE FROM $t WHERE $c = ?", 'i', $id);
            }
        }
        foreach ($req['campaigns'] as $id) {
            $run('DELETE FROM donations WHERE eventID = ?', 'i', $id);
            $run('DELETE FROM non_cash_donations WHERE campaign_id = ?', 'i', $id);
            $run('DELETE FROM volunteer_hours WHERE campaign_id = ?', 'i', $id);
            $run('DELETE FROM communications WHERE campaign_id = ?', 'i', $id);
            $run('DELETE FROM dbevents WHERE id = ?', 'i', $id);
        }
        foreach ($req['donors'] as $id) { $run('DELETE FROM donors WHERE id = ?', 'i', $id); }
        break;
    case 'campaign':
        $out = $run('SELECT name, campaign_type, goalAmount, item_goal, item_unit, volunteer_goal, startDate, endDate, fund, location,
                            owner_id, show_on_dashboard, email_past_donors, ask_volunteers, completed, updated_by
                     FROM dbevents WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'counts':
        $out = ['campaigns' => (int) $run('SELECT COUNT(*) AS n FROM dbevents')[0]['n'],
                'donations' => (int) $run('SELECT COUNT(*) AS n FROM donations')[0]['n'],
                'noncash' => (int) $run('SELECT COUNT(*) AS n FROM non_cash_donations')[0]['n'],
                'hours' => (int) $run('SELECT COUNT(*) AS n FROM volunteer_hours')[0]['n']];
        break;
    case 'cash':
        $out = $run('SELECT donorID, eventID, amount FROM donations WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'noncash':
        $out = $run('SELECT donor_id, campaign_id, quantity FROM non_cash_donations WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'hours_for':
        $out = $run('SELECT campaign_id, hours FROM volunteer_hours WHERE donor_id = ? AND campaign_id = ?', 'ii', $req['donor'], $req['campaign']);
        break;
    case 'today':
        $out = ['today' => date('Y-m-d')];
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
    match = re.search(r"const " + name + r" = (.*?);\r?\n", body)
    assert match, name
    return json.loads(match.group(1))


def field_value(body, label):
    """The read-only value shown under a label on viewDonation.php."""
    match = re.search(r"<label>" + re.escape(html.escape(label)) + r'</label><input value="(.*?)" readonly>', body)
    assert match, label
    return html.unescape(match.group(1))


class CampaignPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        prefix = "camp_test_" + secrets.token_hex(5)
        cls.users = {name: {"id": f"{prefix}_{name}", "name": f"Camp test {name}", "first": "Test", "last": name.capitalize(),
                            "password": "Camp9!" + secrets.token_hex(8), "role": role}
                     for name, (role, _) in USERS.items()}
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        for name, (_, overrides) in USERS.items():
            for area, (view, edit, delete) in overrides.items():
                fixture("permission", user_id=cls.users[name]["id"], area=area, can_view=view, can_edit=edit, can_delete=delete)
        cls.marker = "Cp" + secrets.token_hex(5)
        cls.ids = fixture("setup", marker=cls.marker, xss=XSS)
        cls.addClassCleanup(fixture, "teardown", donors=[cls.ids["donor"]],
                            campaigns=[cls.ids[k] for k in ("open", "ended", "done", "flow", "xss")])
        cls.today = fixture("today")["today"]
        cls.created = []   # campaigns made through addCampaign.php

    @classmethod
    def tearDownClass(cls):
        fixture("teardown", donors=[], campaigns=cls.created)
        super().tearDownClass()

    # ---- helpers ----

    def login(self, name):
        browser = Browser()
        user = self.users[name]
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": user["id"], "password": user["password"]})
        self.assertEqual(status, 303, name)
        return browser

    def get(self, browser, path, status=200):
        code, headers, body = browser.request(path)
        self.assertEqual(code, status, path)
        assert_no_php_errors_in(self, body)
        return headers, body

    def post(self, browser, path, data):
        token = browser.token("dashboard.php")
        status, headers, body = browser.request(path, {"csrf_token": token, **data})
        assert_no_php_errors_in(self, body)
        return status, headers, body

    def day(self, n):
        return (datetime.date.fromisoformat(self.today) + datetime.timedelta(days=n)).isoformat()

    def page(self, key, **query):
        extra = "".join(f"&{k}={v}" for k, v in query.items())
        return f"campaign.php?id={self.ids[key]}{extra}"

    def campaign_form(self, **overrides):
        form = {"name": "Suite campaign " + self.marker, "description": "suite description", "owner_id": "", "type": "both",
                "cash_goal": "2,500", "item_goal": "50", "item_unit": "cans", "volunteer_goal": "12",
                "start_date": self.today, "end_date": self.day(30), "fund": "General Fund", "location": "FISH pantry",
                "show_on_dashboard": "on"}
        form.update(overrides)
        return {k: v for k, v in form.items() if v is not None}

    def make_campaign(self, browser, **overrides):
        status, headers, body = self.post(browser, "addCampaign.php", self.campaign_form(**overrides))
        self.assertEqual(status, 303, body[:2000])
        match = re.fullmatch(r"campaign\.php\?id=(\d+)&saved=1", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        self.created.append(int(match.group(1)))
        return int(match.group(1))

    # ---- access ----

    def test_anonymous_requests_redirect_and_posts_need_the_token(self):
        for path in ("campaigns.php", self.page("open"), "addCampaign.php", f"addCampaign.php?id={self.ids['open']}"):
            with self.subTest(path=path):
                status, headers, body = Browser().request(path)
                self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""))
        before = fixture("counts")
        manager = self.login("manager")
        status, _, body = manager.request("addCampaign.php", self.campaign_form())
        self.assertEqual(status, 403)
        self.assertIn(FORM_EXPIRED, body)
        self.assertEqual(fixture("counts"), before)

    def test_without_campaigns_view_nothing_is_shown_or_written(self):
        before = fixture("counts")
        for name in ("nocamp", "editonly"):
            browser = self.login(name)
            for path in ("campaigns.php", self.page("open")):
                with self.subTest(user=name, path=path):
                    _, body = self.get(browser, path, 403)
                    self.assertIn("Your account cannot view campaigns.", body)
                    self.assertNotIn("Open camp " + self.marker, body)
            # Edit without view must not reach the form either: it shows the stored campaign.
            for path in ("addCampaign.php", f"addCampaign.php?id={self.ids['open']}"):
                with self.subTest(user=name, path=path):
                    _, body = self.get(browser, path, 403)
                    self.assertIn("Your account cannot add or change campaigns.", body)
                    self.assertNotIn("Open camp " + self.marker, body)
                    status, _, _ = self.post(browser, path, self.campaign_form())
                    self.assertEqual(status, 403)
        self.assertEqual(fixture("counts"), before)

    def test_view_only_roles_cannot_write(self):
        before = fixture("counts")
        open_path = f"addCampaign.php?id={self.ids['open']}"
        stored = fixture("campaign", id=self.ids["open"])
        for name in ("viewer", "staff"):
            browser = self.login(name)
            self.get(browser, "campaigns.php")
            self.get(browser, self.page("open"))
            for path in ("addCampaign.php", open_path):
                with self.subTest(user=name, path=path):
                    self.get(browser, path, 403)
                    self.assertEqual(self.post(browser, path, self.campaign_form(name="Hacked"))[0], 403)
        self.assertEqual(fixture("campaign", id=self.ids["open"]), stored)
        self.assertEqual(fixture("counts"), before)

    def test_a_user_row_can_grant_campaign_edit(self):
        granted = self.login("granted")
        _, body = self.get(granted, "addCampaign.php")
        self.assertIn('id="campaignForm"', body)
        cid = self.make_campaign(granted, name="Granted " + self.marker)
        self.assertEqual(fixture("campaign", id=cid)["updated_by"], self.users["granted"]["id"])

    def test_buttons_follow_permissions(self):
        edit = f'addCampaign.php?id={self.ids["open"]}'
        give = f'addDonation.php?campaign_id={self.ids["open"]}'
        expected = {  # name: (Edit shown, + Donation shown, + New campaign shown)
            "manager": (True, True, True), "granted": (True, True, True),
            "staff": (False, True, False), "viewer": (False, False, False),
            "nogifts": (True, False, True),      # no donations view: the gift form would refuse
            "nodonors": (True, False, True),     # donations edit without donors view cannot pick a donor
        }
        for name, (can_edit, can_give, can_add) in expected.items():
            with self.subTest(user=name):
                browser = self.login(name)
                _, body = self.get(browser, self.page("open"))
                self.assertEqual(edit in body, can_edit)
                self.assertEqual(give in body, can_give)
                _, listing = self.get(browser, "campaigns.php")
                self.assertEqual("+ New campaign" in listing, can_add)

    # ---- the pages read the right data ----

    def test_detail_page_figures(self):
        manager = self.login("manager")
        donor = self.ids["donor"]
        _, body = self.get(manager, self.page("open"))
        self.assertIn("Open camp " + self.marker, body)
        self.assertIn('<span class="status active">Active</span>', body)
        self.assertIn('<span class="ctype both">Cash + items</span>', body)
        self.assertIn("Drop-off: FISH pantry", body)
        self.assertIn('<p class="num">$120</p>', body)              # $100 + $20 anonymous
        self.assertIn("12% of $1,000 goal", body)
        self.assertIn('<p class="num">5 cans</p>', body)
        self.assertIn("5% of 100 goal", body)
        self.assertIn('<p class="num">1 / 10</p>', body)
        self.assertIn("10% filled", body)
        self.assertIn("1 first-time donor", body)                   # the anonymous donor is not counted
        self.assertIn('Donations <span class="count">3</span>', body)
        self.assertIn('Volunteers <span class="count">1</span>', body)
        self.assertIn('Communications <span class="count">1</span>', body)
        self.assertIn("<td>$100.00</td>", body)
        self.assertIn(f"<td>5 items · 4.5 lbs · Camp cans {self.marker}</td>", body)
        self.assertIn("<strong>Anonymous</strong>", body)           # shown, never linked
        self.assertIn(f'<a href="donorProfile.php?id={donor}">Camp {self.marker}</a>', body)
        self.assertNotIn("donorProfile.php?id=999", body)
        self.assertIn("<h3>Top donors</h3>", body)
        self.assertIn("<strong>$100.00</strong>", body)
        self.assertIn("Weekly progress (cash)", body)
        self.assertIn("This wk", body)
        self.assertIn("<td>2.25</td>", body)                        # hours keep their hundredths
        self.assertIn("2.25 hrs", body)
        self.assertIn("Camp sorting", body)
        self.assertIn("Camp mailing " + self.marker, body)

        _, body = self.get(manager, self.page("done"))
        self.assertIn('<span class="status completed">Completed</span>', body)
        _, body = self.get(manager, self.page("ended"))
        self.assertIn('<span class="status completed">Completed</span>', body)   # past its end date

    def test_gift_filter_and_paging(self):
        manager = self.login("manager")
        cash, item = "<td>$100.00</td>", f"Camp cans {self.marker}</td>"
        _, body = self.get(manager, self.page("open", kind="cash"))
        self.assertIn(cash, body)
        self.assertNotIn(item, body)
        _, body = self.get(manager, self.page("open", kind="noncash"))
        self.assertIn(item, body)
        self.assertNotIn(cash, body)
        for junk in ("kind[]=x&page[]=1", "kind=zzz", "page=999", "page=-3"):
            with self.subTest(junk=junk):
                _, body = self.get(manager, self.page("open") + "&" + junk)
                self.assertIn(cash, body)

    def test_tabs_follow_the_other_areas(self):
        donor = self.ids["donor"]
        name = f"<strong>Camp {self.marker}</strong>"
        _, body = self.get(self.login("nogifts"), self.page("open"))
        self.assertIn("Your account cannot view donations.", body)
        for hidden in ("<td>$100.00</td>", "Camp cans", "Top donors", "Weekly progress", 'Donations <span class="count">'):
            self.assertNotIn(hidden, body)
        self.assertIn('<p class="num">$120</p>', body)              # the campaign's own totals stay
        self.assertIn("<td>2.25</td>", body)                        # volunteers and communications are separate areas
        self.assertIn("Camp mailing " + self.marker, body)

        _, body = self.get(self.login("nodonors"), self.page("open"))
        self.assertNotIn("donorProfile.php?id=", body)
        self.assertIn(name, body)                                   # names stay as plain text
        self.assertIn("Your account cannot view donors or their volunteer hours.", body)
        self.assertNotIn("<td>2.25</td>", body)
        self.assertNotIn('Volunteers <span class="count">', body)
        self.assertIn("<td>$100.00</td>", body)

        _, body = self.get(self.login("nocomms"), self.page("open"))
        self.assertIn("Your account cannot view communications.", body)
        self.assertNotIn("Camp mailing", body)
        self.assertNotIn('Communications <span class="count">', body)
        self.assertIn("<td>$100.00</td>", body)

    def test_list_page(self):
        for name in ("manager", "viewer"):
            browser = self.login(name)
            _, body = self.get(browser, "campaigns.php")
            for key, status in (("open", "active"), ("ended", "completed"), ("done", "completed")):
                with self.subTest(user=name, campaign=key):
                    self.assertRegex(body, rf'<tr data-status="{status}"[^>]*>\s*<td><a href="campaign\.php\?id={self.ids[key]}">')
            self.assertRegex(body, rf'campaign\.php\?id={self.ids["open"]}">\s*<strong>Open camp {self.marker}</strong>')
            self.assertIn("12%", body)

    def test_junk_ids_go_back_to_the_list(self):
        manager = self.login("manager")
        for path in ("campaign.php?id[]=1", "campaign.php?id=abc", "campaign.php?id=0", "campaign.php?id=999999999",
                     "addCampaign.php?id[]=1", "addCampaign.php?id=abc", "addCampaign.php?id=999999999"):
            with self.subTest(path=path):
                headers, _ = self.get(manager, path, 303)
                self.assertEqual(headers["Location"], "campaigns.php")
        status, headers, _ = self.post(manager, "addCampaign.php?id=999999999", self.campaign_form())
        self.assertEqual((status, headers["Location"]), (303, "campaigns.php"))

    def test_names_render_as_text(self):
        manager = self.login("manager")
        for path in ("campaigns.php", self.page("xss"), f"addCampaign.php?id={self.ids['xss']}", "addDonation.php",
                     f"addHours.php?donor_id={self.ids['donor']}"):
            with self.subTest(path=path):
                _, body = self.get(manager, path)
                self.assertNotIn("<img src=x", body)
        _, body = self.get(manager, self.page("xss"))
        self.assertIn(html.escape(XSS), body)

    # ---- add and edit ----

    def test_add_form_defaults(self):
        manager = self.login("manager")
        _, body = self.get(manager, "addCampaign.php")
        self.assertIn(f'<option value="{self.users["manager"]["id"]}" selected>', body)   # the signed-in owner
        self.assertIn('value="cash" checked', body)
        self.assertIn('value="General Fund"', body)
        self.assertNotIn('name="completed"', body)                  # the box only exists when editing

    def test_create_campaign(self):
        manager = self.login("manager")
        owner = self.users["manager"]["id"]
        cid = self.make_campaign(manager, owner_id=owner, completed="on")   # a posted "completed" is ignored on create
        row = fixture("campaign", id=cid)
        self.assertEqual(row, {"name": "Suite campaign " + self.marker, "campaign_type": "both", "goalAmount": "2500.00",
                               "item_goal": "50.00", "item_unit": "cans", "volunteer_goal": 12, "startDate": self.today,
                               "endDate": self.day(30), "fund": "General Fund", "location": "FISH pantry", "owner_id": owner,
                               "show_on_dashboard": 1, "email_past_donors": 0, "ask_volunteers": 0, "completed": "0",
                               "updated_by": owner})
        _, body = self.get(manager, f"campaign.php?id={cid}&saved=1")
        self.assertIn("Campaign saved.", body)
        self.assertIn('<span class="status active">Active</span>', body)
        self.assertIn("Owner: Test Manager", body)
        _, body = self.get(manager, "campaigns.php")
        self.assertIn("<td>Test M.</td>", body)

        # Goals that do not apply to the chosen type are cleared, even though the browser posts them.
        cid = self.make_campaign(manager, type="volunteers", cash_goal="500", item_goal="20", item_unit="kits")
        row = fixture("campaign", id=cid)
        self.assertEqual((row["campaign_type"], row["goalAmount"], row["item_goal"], row["item_unit"], row["volunteer_goal"]),
                         ("volunteers", None, None, "items", 12))

    def test_create_rejects_bad_input_and_keeps_the_form(self):
        manager = self.login("manager")
        cases = [
            ({"name": ""}, "Campaign name is required."),
            ({"name": "x" * 256}, "Name is too long (at most 255 characters)."),
            ({"type": "bogus"}, "Choose what the campaign is collecting."),
            ({"owner_id": "nobody_" + self.marker}, "Choose an owner from the list."),
            ({"cash_goal": "abc"}, "Cash goal must be a number greater than zero."),
            ({"cash_goal": "0"}, "Cash goal must be a number greater than zero."),
            ({"item_goal": "-5"}, "Item goal must be a number greater than zero."),
            ({"volunteer_goal": "0"}, "Volunteer shifts must be a whole number of at least 1."),
            ({"volunteer_goal": "1.5"}, "Volunteer shifts must be a whole number of at least 1."),
            ({"start_date": ""}, "Start date is required."),
            ({"end_date": "2026-02-30"}, "End date is required."),
            ({"start_date": self.day(5), "end_date": self.day(1)}, "End date must be on or after the start date."),
        ]
        for overrides, message in cases:
            with self.subTest(message=message, overrides={k: v[:20] for k, v in overrides.items()}):
                before = fixture("counts")
                status, _, body = self.post(manager, "addCampaign.php", self.campaign_form(**overrides))
                self.assertEqual(status, 200)
                self.assertIn(html.escape(message, quote=False), body)
                self.assertIn('class="form-errors"', body)
                if "name" not in overrides:
                    self.assertIn('value="Suite campaign ' + self.marker + '"', body)
                self.assertEqual(fixture("counts"), before)

    def test_edit_campaign(self):
        manager = self.login("manager")
        owner = self.users["manager"]["id"]
        cid = self.make_campaign(manager, owner_id=owner)
        path = f"addCampaign.php?id={cid}"
        _, body = self.get(manager, path)
        self.assertIn('value="Suite campaign ' + self.marker + '"', body)
        self.assertIn('value="2500"', body)
        self.assertIn('<option selected>cans</option>', body)
        self.assertIn('value="both" checked', body)
        self.assertIn('name="completed"', body)
        self.assertNotIn('name="completed" checked', body)

        status, headers, _ = self.post(manager, path, self.campaign_form(
            owner_id=owner, name="Renamed " + self.marker, volunteer_goal="20", completed="on"))
        self.assertEqual((status, headers["Location"]), (303, f"campaign.php?id={cid}&saved=1"))
        row = fixture("campaign", id=cid)
        self.assertEqual((row["name"], row["volunteer_goal"], row["completed"], row["owner_id"], row["goalAmount"]),
                         ("Renamed " + self.marker, 20, "1", owner, "2500.00"))
        _, body = self.get(manager, f"campaign.php?id={cid}")
        self.assertIn('<span class="status completed">Completed</span>', body)
        _, body = self.get(manager, path)
        self.assertIn('name="completed" checked', body)

        # Unticking reopens a campaign that is still inside its dates.
        self.assertEqual(self.post(manager, path, self.campaign_form(owner_id=owner))[0], 303)
        self.assertEqual(fixture("campaign", id=cid)["completed"], "0")
        _, body = self.get(manager, f"campaign.php?id={cid}")
        self.assertIn('<span class="status active">Active</span>', body)

        # A failed save writes nothing.
        before = fixture("campaign", id=cid)
        status, _, body = self.post(manager, path, self.campaign_form(name=""))
        self.assertEqual(status, 200)
        self.assertIn("Campaign name is required.", body)
        self.assertEqual(fixture("campaign", id=cid), before)

    def test_edit_form_for_completed_campaigns(self):
        manager = self.login("manager")
        _, body = self.get(manager, f"addCampaign.php?id={self.ids['done']}")
        self.assertIn('name="completed" checked', body)             # flag stored as '1'
        for key in ("ended", "open"):                               # 'ended' is Completed only by date; the flag is 0
            with self.subTest(campaign=key):
                _, body = self.get(manager, f"addCampaign.php?id={self.ids[key]}")
                self.assertIn('name="completed"', body)
                self.assertNotIn('name="completed" checked', body)

    # ---- from the other side: the donation and hours forms ----

    def test_donation_form_prefills_and_offers_the_right_campaigns(self):
        staff = self.login("staff")
        donor, open_id = self.ids["donor"], self.ids["open"]
        _, body = self.get(staff, f"addDonation.php?campaign_id={open_id}")
        self.assertIn(f'<option value="{open_id}" selected>Open camp {self.marker}</option>', body)
        self.assertIn(f'<option value="{self.ids["flow"]}">Flow camp {self.marker}</option>', body)
        self.assertNotIn(f'<option value="{self.ids["done"]}"', body)         # completed campaigns are not offered
        _, body = self.get(staff, f"addDonation.php?donor={donor}&kind=cash&campaign_id={open_id}")
        self.assertEqual(embedded(body, "CHOSEN_DONOR")["id"], donor)
        self.assertIn('value="cash" checked', body)
        self.assertIn(f'<option value="{open_id}" selected>', body)
        # A finished or junk campaign is not preselected and does not break the page.
        for junk in (self.ids["done"], "abc", "999999999"):
            with self.subTest(campaign_id=junk):
                _, body = self.get(staff, f"addDonation.php?campaign_id={junk}")
                self.assertNotIn(f'<option value="{junk}" selected>', body)
        # The card data matches what the campaign page shows.
        _, body = self.get(staff, "addDonation.php")
        card = embedded(body, "CAMPAIGNS")[str(open_id)]
        self.assertEqual((card["name"], card["goal"], card["raised"], card["gifts"], card["item_value"]),
                         ("Open camp " + self.marker, 1000.0, 120.0, 1, 10.0))
        self.assertIn(str(self.ids["xss"]), embedded(body, "CAMPAIGNS"))
        _, body = self.get(staff, f"addHours.php?donor_id={donor}")
        self.assertIn(f'<option value="{open_id}">Open camp {self.marker}</option>', body)
        self.assertNotIn(f'<option value="{self.ids["done"]}"', body)

    def test_gifts_and_hours_logged_with_a_campaign_show_on_its_page(self):
        staff = self.login("staff")
        manager = self.login("manager")
        donor, flow = self.ids["donor"], self.ids["flow"]
        flow_name = "Flow camp " + self.marker
        _, empty = self.get(manager, self.page("flow"))
        self.assertIn('<p class="num">$0</p>', empty)

        cash = {"donor": str(donor), "kind": "cash", "amount": "50", "fee": "", "pay_type": "cash", "platform": "in_person",
                "date_received": self.today, "campaign": str(flow), "notes": "flow cash", "next": "done"}
        status, headers, _ = self.post(staff, "addDonation.php", cash)
        self.assertEqual(status, 303)
        match = re.fullmatch(r"viewDonation\.php\?kind=cash&id=(\d+)&saved=1", headers["Location"])
        self.assertIsNotNone(match, headers["Location"])
        self.assertEqual(fixture("cash", id=int(match.group(1)))["eventID"], flow)
        _, body = self.get(staff, headers["Location"])
        self.assertEqual(field_value(body, "Campaign"), flow_name)
        self.assertIn(flow_name, body)
        self.assertIn("$50.00 of $500.00 raised", body)

        noncash = {"donor": str(donor), "kind": "noncash", "item_description": "Flow cans " + self.marker, "category": "canned_food",
                   "quantity": "10", "weight_lbs": "8", "estimated_value": "20", "condition": "", "intended_use": "Food pantry",
                   "date_received": self.today, "campaign": str(flow), "next": "done"}
        status, headers, _ = self.post(staff, "addDonation.php", noncash)
        self.assertEqual(status, 303)
        self.assertEqual(fixture("noncash", id=int(re.search(r"id=(\d+)", headers["Location"]).group(1)))["campaign_id"], flow)

        status, headers, _ = self.post(staff, f"addHours.php?donor_id={donor}", {
            "date": self.today, "start_time": "09:00", "end_time": "13:30", "hours": "", "job": "Pantry sorting",
            "campaign_id": str(flow), "location": "Main hall", "notes": "flow shift", "action": "save"})
        self.assertEqual((status, headers["Location"]), (303, f"donorProfile.php?id={donor}&tab=vol&hours=1"))
        self.assertEqual([r["hours"] for r in fixture("hours_for", donor=donor, campaign=flow)], ["4.50"])

        _, body = self.get(manager, self.page("flow"))
        self.assertIn('<p class="num">$50</p>', body)
        self.assertIn("10% of $500 goal", body)
        self.assertIn('Donations <span class="count">2</span>', body)
        self.assertIn("<td>$50.00</td>", body)
        self.assertIn(f"<td>10 items · 8 lbs · Flow cans {self.marker}</td>", body)
        self.assertIn('<p class="num">1</p>', body)
        self.assertIn("4.5 hours so far", body)
        self.assertIn("<td>4.5</td>", body)
        _, body = self.get(staff, "donations.php?range=all&q=" + flow_name.replace(" ", "+"))
        self.assertIn(flow_name, body)
        _, body = self.get(staff, f"donorProfile.php?id={donor}&tab=vol")
        self.assertIn(flow_name, body)
        _, body = self.get(staff, "addDonation.php")
        card = embedded(body, "CAMPAIGNS")[str(flow)]
        self.assertEqual((card["raised"], card["gifts"], card["item_value"]), (50.0, 1, 20.0))


if __name__ == "__main__":
    unittest.main(verbosity=2)