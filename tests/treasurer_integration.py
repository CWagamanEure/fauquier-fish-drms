"""Treasurer page (treasurer.php, UF2-163..167): cash donations and expenses in the log, the Money in / Money out /
Net / Entries cards, the fund and show filters and the year chart, against the real tables. Run via
`make test-treasurer` (needs `make seed-demo` first, like the other page suites).

The suite creates and removes its own donors, campaigns, donations, expenses and users. Explicit-range checks use
March 1902, which no other suite uses (exports uses 1901) and setUpClass checks is empty; checks on the default month and the year chart insert rows dated today
and assert on the change, or filter on the suite's own fund, so demo rows are never asserted on."""

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
RANGE = "from=1902-03-01&to=1902-03-31"

FIXTURES_PHP = r"""
require 'database/dbTreasurer.php';
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
$gift = function ($donor, $campaign, $amount, $date, $method, $check = null) use ($run, $con) {
    $run("INSERT INTO donations (donorID, eventID, amount, donation_date, payment_method, check_number, platform, thanked)
          VALUES (?, ?, ?, ?, ?, ?, 'in_person', 0)", 'iissss', $donor, $campaign, $amount, $date, $method, $check);
    return (int) $con->insert_id;
};
$expense = function ($payee, $campaign, $amount, $date, $category) use ($run, $con) {
    $run("INSERT INTO expenses (expense_date, payee, category, campaign_id, amount) VALUES (?, ?, ?, ?, ?)",
         'sssis', $date, $payee, $category, $campaign, $amount);
    return (int) $con->insert_id;
};
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
        $donor = function ($first, $last) use ($run, $con) {
            $run("INSERT INTO donors (donor_type, `first`, `last`, city, state, notes, donor_since, is_active)
                  VALUES ('individual', ?, ?, 'Testville', 'VA', 'treasurer suite', '1902-01-01', 1)", 'ss', $first, $last);
            return (int) $con->insert_id;
        };
        $campaign = function ($name, $fund) use ($run, $con) {
            $run("INSERT INTO dbevents (name, goalAmount, startDate, endDate, description, completed, fund)
                  VALUES (?, 1000, '1902-01-01', '1902-12-31', 'treasurer suite', '0', ?)", 'ss', $name, $fund);
            return (int) $con->insert_id;
        };
        $out = ['main' => $donor('Tres', $m), 'xss' => $donor($req['xss'], $m)];
        $out['funded'] = $campaign('Funded drive ' . $m, $req['fund']);
        $out['plain'] = $campaign('Plain drive ' . $m, null);   // no designation: General Fund
        // March 1902: three donations and two expenses count; the rest are outside the range or have no amount.
        $gift($out['main'], $out['funded'], '100.00', '1902-03-05', 'cash');
        $gift($out['xss'], null, '50.25', '1902-03-10', 'check', '77');
        $gift(999, $out['plain'], '5.00', '1902-03-15', 'online');
        $gift($out['main'], $out['funded'], null, '1902-03-20', 'cash');
        $gift($out['main'], $out['funded'], '999.00', '1902-04-01', 'cash');
        $expense($req['xss'], $out['funded'], '300.00', '1902-03-12', 'food_supplies');
        $expense('Power Co ' . $m, null, '20.00', '1902-03-20', 'utilities');
        $expense('Too early ' . $m, null, '7.00', '1902-02-28', 'other');
        break;
    case 'today_rows':
        $today = date('Y-m-d');
        $twoYearsAgo = sprintf('%04d-06-15', (int) date('Y') - 2);
        $out = ['gift' => $gift($req['donor'], $req['campaign'], '123.45', $today, 'card'),
                'expense' => $expense('Today payee', $req['campaign'], '67.89', $today, 'rent'),
                'old' => $expense('Old payee', $req['campaign'], '40.00', $twoYearsAgo, 'rent')];
        break;
    case 'clear_today':
        $run('DELETE FROM expenses WHERE id IN (?, ?)', 'ii', $req['expense'], $req['old']);
        $run('DELETE FROM donations WHERE id = ?', 'i', $req['gift']);
        break;
    case 'teardown':
        // Expenses, then donations, then campaigns, then donors: the donation foreign keys are RESTRICT.
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM expenses WHERE campaign_id = ?', 'i', $id); }
        $run("DELETE FROM expenses WHERE payee IN (?, ?, ?)", 'sss', $req['xss'], 'Power Co ' . $req['marker'], 'Too early ' . $req['marker']);
        foreach ($req['donors'] as $id) { $run('DELETE FROM donations WHERE donorID = ?', 'i', $id); }
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM donations WHERE eventID = ?', 'i', $id); }
        foreach ($req['campaigns'] as $id) { $run('DELETE FROM dbevents WHERE id = ?', 'i', $id); }
        foreach ($req['donors'] as $id) { $run('DELETE FROM donors WHERE id = ?', 'i', $id); }
        break;
    case 'years':
        $out = treasurer_years($req['span'], $req['fund']);
        break;
    case 'tiles':
        $out = donations_tiles();
        break;
    case 'window_check':
        $out = (int) $run("SELECT (SELECT COUNT(*) FROM donations WHERE donation_date BETWEEN '1902-01-01' AND '1902-12-31')
                                + (SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN '1902-01-01' AND '1902-12-31') AS n")[0]['n'];
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


def money(text):
    """'+$1,234.50' / '−$5.00' / '$0.00' -> signed float."""
    text = html.unescape(text).strip()
    sign = -1 if text.startswith(("−", "-")) else 1
    return sign * float(re.sub(r"[^\d.]", "", text))


def cards(body):
    found = dict(re.findall(r'<p class="label">([^<]+)</p><p class="num[^"]*">(.*?)</p>', body))
    return {"in": money(found["Money in"]), "out": -money(found["Money out"]), "net": money(found["Net"]),
            "entries": int(found["Entries"])}


def log_rows(body):
    block = re.search(r'<table class="tr-log">.*?<tbody>(.*?)</tbody>', body, re.S).group(1)
    rows = []
    for tr in re.findall(r"<tr>(.*?)</tr>", block, re.S):
        cells = [html.unescape(re.sub(r"<[^>]+>", "", c)).strip() for c in re.findall(r"<td[^>]*>(.*?)</td>", tr, re.S)]
        if len(cells) == 6:
            rows.append({"date": cells[0], "type": cells[1], "who": cells[2], "detail": cells[3], "fund": cells[4],
                         "amount": money(cells[5])})
    return rows


def fund_options(body):
    block = re.search(r'<select name="fund"[^>]*>(.*?)</select>', body, re.S).group(1)
    return [(html.unescape(v), bool(sel)) for v, sel in re.findall(r'<option value="([^"]*)"( selected)?>', block)]


class TreasurerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-treasurer")
        if fixture("window_check"):
            raise AssertionError("donations or expenses dated in 1902 exist that this suite did not make; "
                                 "remove them (a previous run was interrupted?) before make test-treasurer")
        prefix = "treas_test_" + secrets.token_hex(6)
        cls.users = {role: {"id": f"{prefix}_{role}", "name": f"Treasurer test {role}", "first": "Treas",
                            "last": role.capitalize(), "password": "Trs9!" + secrets.token_hex(8), "role": "staff",
                            "status": "active"}
                     for role in ("staff", "nodonations")}
        cls.users["viewer"] = dict(cls.users["staff"], id=f"{prefix}_viewer", role="viewer", last="Viewer")
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete", users=list(cls.users.values()))
        fixture("permission", user_id=cls.users["nodonations"]["id"], area="donations", can_view=0, can_edit=0, can_delete=0)
        cls.marker = "Tr" + secrets.token_hex(5)
        cls.fund = "Fund " + cls.marker
        cls.ids = fixture("setup", marker=cls.marker, xss=XSS, fund=cls.fund)
        cls.addClassCleanup(fixture, "teardown", marker=cls.marker, xss=XSS,
                            donors=[cls.ids["main"], cls.ids["xss"]], campaigns=[cls.ids["funded"], cls.ids["plain"]])

    def login(self, role="staff"):
        browser = Browser()
        user = self.users[role]
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": user["id"], "password": user["password"]})
        self.assertEqual(status, 303, role)
        return browser

    def page(self, browser, query=""):
        status, _, body = browser.request("treasurer.php" + ("?" + query if query else ""))
        self.assertEqual(status, 200, query)
        assert_no_php_errors_in(self, body)
        self.assertNotIn("Could not load treasurer figures", body)
        return body

    # ---- UF2-163: cash donations (and expenses) in the log ----

    def test_log_lists_range_rows_newest_first(self):
        body = self.page(self.login(), f"{RANGE}&fund={self.fund.replace(' ', '+')}")
        rows = log_rows(body)
        self.assertEqual([(r["date"], r["type"], r["amount"]) for r in rows],
                         [("Mar 12", "Expense", -300.00), ("Mar 5", "Donation", 100.00)])
        self.assertEqual(rows[1]["who"], "Tres " + self.marker)
        self.assertEqual(rows[1]["detail"], "Cash · Funded drive " + self.marker)
        self.assertEqual(rows[1]["fund"], self.fund)
        self.assertEqual(rows[0]["who"], XSS)   # payee, shown as text
        self.assertEqual(rows[0]["detail"], "Food supplies · Funded drive " + self.marker)

        rows = log_rows(self.page(self.login(), f"{RANGE}&fund=General+Fund"))
        self.assertIn({"date": "Mar 15", "type": "Donation", "who": "Anonymous", "detail": "Online · Plain drive " + self.marker,
                       "fund": "General Fund", "amount": 5.00}, rows)
        self.assertIn({"date": "Mar 10", "type": "Donation", "who": XSS + " " + self.marker, "detail": "Check #77",
                       "fund": "General Fund", "amount": 50.25}, rows)
        self.assertIn({"date": "Mar 20", "type": "Expense", "who": "Power Co " + self.marker, "detail": "Utilities",
                       "fund": "General Fund", "amount": -20.00}, rows)
        self.assertEqual(len(rows), 3)

    def test_out_of_range_and_null_amount_rows_are_left_out(self):
        body = self.page(self.login(), f"{RANGE}&fund={self.fund.replace(' ', '+')}")
        self.assertNotIn("999.00", body)
        self.assertNotIn("Too early", body)
        self.assertEqual(len(log_rows(body)), 2)   # the NULL-amount gift on Mar 20 is not a row
        body = self.page(self.login(), "from=1902-04-01&to=1902-04-01&fund=" + self.fund.replace(" ", "+"))
        self.assertEqual([r["amount"] for r in log_rows(body)], [999.00])

    def test_log_dates_carry_the_year_across_years(self):
        body = self.page(self.login(), "from=1902-03-01&to=1903-01-31&fund=" + self.fund.replace(" ", "+"))
        self.assertEqual([r["date"] for r in log_rows(body)], ["Apr 1, 1902", "Mar 12, 1902", "Mar 5, 1902"])

    # ---- UF2-164..167: the cards ----

    def test_cards_for_a_range(self):
        staff = self.login()
        c = cards(self.page(staff, RANGE))
        self.assertEqual(c, {"in": 155.25, "out": 320.00, "net": -164.75, "entries": 5})
        body = self.page(staff, RANGE)
        self.assertIn("&minus;$164.75", body)   # negative net shown with a minus sign
        self.assertEqual(len(log_rows(body)), c["entries"])   # Entries = rows in the log (show=all)
        c = cards(self.page(staff, f"{RANGE}&fund={self.fund.replace(' ', '+')}"))
        self.assertEqual(c, {"in": 100.00, "out": 300.00, "net": -200.00, "entries": 2})
        c = cards(self.page(staff, f"{RANGE}&fund=General+Fund"))
        self.assertEqual(c, {"in": 55.25, "out": 20.00, "net": 35.25, "entries": 3})

    def test_show_filter_limits_the_log_not_the_cards(self):
        staff = self.login()
        for show, kinds in (("donations", {"Donation"}), ("expenses", {"Expense"})):
            with self.subTest(show=show):
                body = self.page(staff, f"{RANGE}&show={show}")
                self.assertEqual({r["type"] for r in log_rows(body)}, kinds)
                self.assertEqual(cards(body), {"in": 155.25, "out": 320.00, "net": -164.75, "entries": 5})

    def test_unknown_fund_and_bad_dates_fall_back(self):
        staff = self.login()
        all_funds = cards(self.page(staff, RANGE))
        body = self.page(staff, f"{RANGE}&fund=No+such+fund+{self.marker}")
        self.assertEqual(cards(body), all_funds)
        self.assertEqual([v for v, sel in fund_options(body) if sel], [])   # "All funds" (no value) stays selected
        options = [v for v, _ in fund_options(body)]
        self.assertEqual(options.pop(0), "")   # All funds
        self.assertEqual(options[0], "General Fund")
        self.assertIn(self.fund, options)
        default = cards(self.page(staff))
        for bad in ("from=1902-02-30&to=1902-03-31", "from=1902-03-01&to=1902-13-01", "from=0000-00-00&to=1902-03-31"):
            with self.subTest(bad=bad):
                body = self.page(staff, bad)
                self.assertNotIn("Feb 30", body)
                self.assertNotIn("Mar 31, 1902", body)   # the default month, not a half-valid range
                self.assertEqual(cards(body), default)

    def test_default_month_and_year_chart_move_by_the_new_rows(self):
        staff = self.login()
        before = cards(self.page(staff))
        tiles_before = fixture("tiles")["cash_month"]
        rows = fixture("today_rows", donor=self.ids["main"], campaign=self.ids["funded"])
        self.addCleanup(fixture, "clear_today", **rows)
        after = cards(self.page(staff))
        self.assertAlmostEqual(after["in"] - before["in"], 123.45, places=2)
        self.assertAlmostEqual(after["out"] - before["out"], 67.89, places=2)
        self.assertAlmostEqual(after["net"] - before["net"], 123.45 - 67.89, places=2)
        self.assertEqual(after["entries"] - before["entries"], 2)
        self.assertAlmostEqual(fixture("tiles")["cash_month"] - tiles_before, 123.45, places=2)
        self.assertAlmostEqual(after["in"], fixture("tiles")["cash_month"], places=2)   # same figure as Donations

        years = fixture("years", span=3, fund=self.fund)
        this = years[-1]["year"]
        self.assertEqual([y["year"] for y in years], [this - 2, this - 1, this])
        self.assertEqual([(y["donations"], y["expenses"]) for y in years], [(0, 40.0), (0, 0), (123.45, 67.89)])
        self.assertEqual([y["year"] for y in fixture("years", span=5, fund=self.fund)], list(range(this - 4, this + 1)))
        body = self.page(staff, "fund=" + self.fund.replace(" ", "+") + "&years=5")
        bars = re.findall(r'<i class="([de])" style="height:([\d.]+)%"><b>([^<]*)</b>', body)
        self.assertEqual(len(bars), 10)
        self.assertEqual(bars[-2:], [("d", "100", "$123"), ("e", "55", "$68")])
        self.assertEqual(bars[5], ("e", "32.4", "$40"))
        self.assertIn(f"{this} (to date)", body)

    # ---- access ----

    def test_no_donations_view_shows_nothing(self):
        body = self.page(self.login("nodonations"), RANGE)
        self.assertIn("Your account cannot view donations.", body)
        for hidden in ("Money in", self.marker, self.fund, "General Fund", "$", 'class="tr-log"'):
            self.assertNotIn(hidden, body)

    def test_viewer_role_sees_the_figures(self):
        self.assertEqual(cards(self.page(self.login("viewer"), RANGE))["entries"], 5)

    def test_sign_in_required(self):
        status, headers, _ = Browser().request("treasurer.php")
        self.assertEqual((status, headers["Location"]), (303, "/login.php"))

    def test_names_are_escaped(self):
        body = self.page(self.login(), RANGE)
        self.assertNotIn(XSS, body)
        self.assertIn(html.escape(XSS, quote=True), body)


if __name__ == "__main__":
    unittest.main(verbosity=2)
