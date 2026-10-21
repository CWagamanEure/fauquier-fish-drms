"""Manage Users pages against the real tables (UF2-83..86, UF2-97). Run via `make test-users`
(needs `make seed-demo` first). Timing against the Jira limits is asserted only with USERS_TIMING_STRICT=1."""

import html
import json
import os
import re
import secrets
import subprocess
import sys
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from auth_integration import Browser, ROOT, assert_no_php_errors_in  # noqa: E402

DEMO_PASSWORD = "Fish!Demo2026"
DEMO = {"fish.admin": ("Dana Admin", "Administrator", "dana.admin@example.org", "active"),
        "fish.manager": ("Mia Manager", "Manager", "mia.manager@example.org", "active"),
        "fish.staff": ("Sam Staff", "Staff", "sam.staff@example.org", "active"),
        "fish.viewer": ("Vic Viewer", "Read only", "vic.viewer@example.org", "active"),
        "fish.former": ("Pat Former", "Staff", "pat.former@example.org", "inactive")}
AREAS = ("donors", "donations", "campaigns", "communications", "reports", "data_import", "users")
STRICT = os.environ.get("USERS_TIMING_STRICT") == "1"
LIMITS = {"addUser.php": 2.0, "editUser.php": 5.0, "userPassword.php": 5.0}

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
    case 'seed_check':
        $out = ['demo' => (int) $run("SELECT COUNT(*) AS n FROM dbpersons WHERE id = 'fish.admin'")[0]['n']];
        break;
    case 'users_create':
        foreach ($req['users'] as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $run('INSERT INTO dbpersons (id, name, password, first_name, last_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', 'ssssssss',
                 $u['id'], $u['name'], $hash, $u['first'], $u['last'], $u['email'], $u['role'], $u['status']);
        }
        break;
    case 'users_delete':
        foreach ($req['users'] as $id) { $run('DELETE FROM dbpersons WHERE id = ?', 's', $id); }
        break;
    case 'users_delete_prefix':
        $run('DELETE FROM dbpersons WHERE id LIKE ?', 's', $req['prefix'] . '%');
        break;
    case 'set_status':
        foreach ($req['users'] as $id) { $run('UPDATE dbpersons SET status = ? WHERE id = ?', 'ss', $req['status'], $id); }
        break;
    case 'permission':
        $run('INSERT INTO user_permissions (user_id, area, can_view, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)',
             'ssiii', $req['user_id'], $req['area'], $req['can_view'], $req['can_edit'], $req['can_delete']);
        break;
    case 'users_read':
        $out = $run('SELECT id, name, first_name, last_name, email, role, status, accessLevel, updated_at FROM dbpersons ORDER BY id');
        break;
    case 'user_read':
        $row = $run('SELECT id, name, first_name, last_name, email, role, status, accessLevel, password, updated_at FROM dbpersons WHERE id = ?', 's', $req['id'])[0] ?? null;
        $perms = [];
        foreach ($run('SELECT area, can_view, can_edit, can_delete FROM user_permissions WHERE user_id = ? ORDER BY area', 's', $req['id']) as $p) {
            $perms[$p['area']] = [(int) $p['can_view'], (int) $p['can_edit'], (int) $p['can_delete']];
        }
        $out = ['user' => $row, 'perms' => $perms];
        break;
    case 'presets':
        $out = [];
        foreach ($run('SELECT role, area, can_view, can_edit, can_delete FROM role_permission_presets') as $p) {
            $out[$p['role']][$p['area']] = [(int) $p['can_view'], (int) $p['can_edit'], (int) $p['can_delete']];
        }
        break;
    case 'donor_create':
        $m = $req['marker'];
        $run("INSERT INTO donors (donor_type, `first`, `last`, email, updated_by) VALUES ('individual', 'Suite', ?, ?, ?)", 'sss', $m, $m . '@example.test', $req['user']);
        $out = ['id' => (int) $con->insert_id];
        break;
    case 'donor_read':
        $out = $run('SELECT id, `last`, city, updated_by FROM donors WHERE id = ?', 'i', $req['id'])[0] ?? null;
        break;
    case 'donor_delete':
        $run('DELETE FROM donors WHERE id = ?', 'i', (int) $req['id']);
        break;
    case 'last_admin_check':
        // The pages cannot reach this branch except through a race or vmsroot: an admin acting on another admin
        // means two active admins. Call the model directly: both must refuse without writing.
        require 'database/dbUsers.php';
        $stored = user_get($req['id']);
        $form = ['first' => $stored['first_name'], 'last' => $stored['last_name'], 'email' => $stored['email'], 'role' => 'manager'];
        $out = ['deactivate' => user_set_status($stored, false), 'demote' => user_update($stored, $form, $stored['matrix'])];
        break;
    default:
        throw new RuntimeException('unknown fixture ' . $req['action']);
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
    """username -> (name, role label, permission chips, last login, status) for every row on manageUsers.php."""
    out = {}
    for tr in re.findall(r"<tr>\s*<td><span class=\"initials[^\"]*\">.*?</tr>", body, re.S):
        cells = re.findall(r"<td[^>]*>(.*?)</td>", tr, re.S)
        name = html.unescape(re.search(r"<strong>(.*?)</strong>", cells[0]).group(1))
        chips = [html.unescape(c) for c in re.findall(r'<span class="chip">(.*?)</span>', cells[3])]
        status = re.search(r'<span class="status (\w+)">', cells[5]).group(1)
        out[html.unescape(cells[1])] = (name, html.unescape(cells[2]), chips, html.unescape(cells[4]), status)
    return out


def checked(body):
    """Set of 'area/action' boxes ticked in the permissions table."""
    return {f"{a}/{b}" for a, b, attrs in re.findall(r'name="perm\[(\w+)\]\[(\w+)\]"([^>]*)>', body) if "checked" in attrs}


def perm_fields(matrix):
    """matrix[area] = (view, edit, delete) -> posted perm[...] fields."""
    fields = {}
    for area, cells in matrix.items():
        for action, on in zip(("view", "edit", "delete"), cells):
            if on:
                fields[f"perm[{area}][{action}]"] = "on"
    return fields


class UsersPagesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if fixture("seed_check")["demo"] == 0:
            raise AssertionError("run make seed-demo before make test-users")
        cls.prefix = "users_test_" + secrets.token_hex(4) + "_"
        cls.password = "Users9!" + secrets.token_hex(8)
        cls.presets = fixture("presets")
        cls.users = {}
        for role in ("admin", "manager", "staff", "viewer"):
            cls.users[role] = {"id": cls.prefix + role, "name": f"Suite {role.title()}", "first": "Suite", "last": role.title(),
                               "email": f"{cls.prefix}{role}@example.test", "role": role, "status": "active", "password": cls.password}
        fixture("users_create", users=list(cls.users.values()))
        cls.addClassCleanup(fixture, "users_delete_prefix", prefix=cls.prefix)
        cls.timings = []

    @classmethod
    def tearDownClass(cls):
        for page in LIMITS:
            times = [t for p, t in cls.timings if p == page]
            if times:
                print(f"timing {page}: {len(times)} writes, slowest {max(times):.3f}s (Jira limit {LIMITS[page]:.0f}s)", file=sys.stderr)
        super().tearDownClass()

    def login(self, username, password=None):
        browser = Browser()
        status, _, _ = browser.request("login.php", {"csrf_token": browser.token(), "username": username,
                                                     "password": password or (DEMO_PASSWORD if username in DEMO else self.password)})
        self.assertEqual(status, 303, username)
        return browser

    def get(self, browser, path, status=200):
        code, headers, body = browser.request(path)
        self.assertEqual(code, status, path)
        assert_no_php_errors_in(self, body)
        return headers, body

    def post(self, browser, path, data, token_from=None):
        """POST with a fresh token; records the elapsed time for the write pages."""
        token = browser.token(token_from or path)
        start = time.monotonic()
        status, headers, body = browser.request(path, {"csrf_token": token, **data})
        seconds = time.monotonic() - start
        page = path.split("?")[0]
        if page in LIMITS:
            self.timings.append((page, seconds))
            if STRICT:
                self.assertLess(seconds, LIMITS[page], f"{page} took {seconds:.2f}s")
        return status, headers, body

    def user_form(self, user, **overrides):
        form = {"first": user["first"], "last": user["last"], "email": user["email"], "role": user["role"],
                "preset_role": user["role"], "action": "save"}
        form.update(perm_fields(self.presets[user["role"]]))
        form.update(overrides)
        return form

    def new_user_form(self, username, role="viewer", **overrides):
        form = {"first": "New", "last": username[-6:], "username": username, "email": f"{username}@example.test",
                "role": role, "preset_role": role, "new_password": self.password, "confirm_password": self.password}
        form.update(perm_fields(self.presets[role]))
        form.update(overrides)
        return form

    # ---- list ----

    def test_list_reads_the_database(self):
        admin = self.login("fish.admin")
        _, body = self.get(admin, "manageUsers.php")
        rows = rows_in(body)
        stored = {u["id"]: u for u in fixture("users_read")}
        self.assertEqual(set(rows), set(stored))
        for username, (name, role, chips, last_login, status) in rows.items():
            s = stored[username]
            expected_name = (f"{s['first_name'] or ''} {s['last_name'] or ''}").strip() or s["name"]
            self.assertEqual((name, status), (expected_name, "active" if s["status"] == "active" else "off"), username)
            self.assertEqual(last_login, "—", username)   # no sign-in times are recorded yet
        for username, (name, role, email, status) in DEMO.items():
            self.assertEqual(rows[username][:2] + rows[username][4:], (name, role, "active" if status == "active" else "off"))
        _, edit_body = self.get(admin, "editUser.php?id=fish.admin")
        self.assertIn('value="dana.admin@example.org"', edit_body)
        self.assertEqual(rows["fish.viewer"][2], ["Donors & profiles (view)", "Donations (cash & non-cash) (view)", "Campaigns (view)", "Communications (view)", "Reports (view)"])
        self.assertEqual(rows["fish.staff"][2][:2], ["Donors & profiles", "Donations (cash & non-cash)"])
        self.assertIn(f"Showing 1&ndash;{len(stored)} of {len(stored)} users", body)
        self.assertIn("+ Add user", body)
        self.assertIn('href="manageUsers.php"', body)   # sidebar link

    def test_access_by_role(self):
        for username in ("fish.staff", "fish.viewer"):
            browser = self.login(username)
            _, body = self.get(browser, "manageUsers.php", 403)
            self.assertIn("does not include Manage Users", body)
            self.assertNotIn('href="manageUsers.php"', body)
            self.get(browser, "addUser.php", 403)
            self.get(browser, "editUser.php?id=fish.viewer", 403)
            self.assertEqual(browser.request("editUser.php?id=fish.viewer", {"csrf_token": browser.token("dashboard.php"), "action": "deactivate"})[0], 403)
        manager = self.login("fish.manager")
        _, body = self.get(manager, "manageUsers.php")
        self.assertNotIn("+ Add user", body)
        _, body = self.get(manager, "editUser.php?id=fish.staff")
        self.assertIn("can view users but not change them", body)
        self.assertNotIn("Save changes", body)
        self.assertNotIn('value="deactivate"', body)
        _, body = self.get(manager, "userPassword.php?id=fish.staff")
        self.assertNotIn("Update password", body)
        before = fixture("user_read", id="fish.staff")
        self.assertEqual(manager.request("editUser.php?id=fish.staff", {"csrf_token": manager.token("manageUsers.php"), **self.user_form(self.users["staff"], first="Hacked")})[0], 403)
        self.assertEqual(manager.request("editUser.php?id=fish.staff", {"csrf_token": manager.token("manageUsers.php"), "action": "deactivate"})[0], 403)
        self.assertEqual(manager.request("addUser.php", {"csrf_token": manager.token("manageUsers.php"), **self.new_user_form(self.prefix + "mgr")})[0], 403)
        self.assertEqual(manager.request("userPassword.php?id=fish.staff", {"csrf_token": manager.token("manageUsers.php"), "new_password": "Changed!99", "confirm_password": "Changed!99"})[0], 403)
        self.assertEqual(fixture("user_read", id="fish.staff"), before)
        self.assertIsNone(fixture("user_read", id=self.prefix + "mgr")["user"])
        _, body = self.get(manager, "editUser.php?id=nobody_" + self.prefix, 404)
        self.assertIn("User not found", body)

    def test_anonymous_redirects_and_posts_need_the_token(self):
        for path in ("manageUsers.php", "addUser.php", "editUser.php?id=fish.staff", "userPassword.php?id=fish.staff"):
            status, headers, body = Browser().request(path)
            self.assertEqual((status, headers["Location"], body), (303, "/login.php", ""), path)
        admin = self.login("fish.admin")
        before = fixture("user_read", id=self.users["staff"]["id"])
        target = self.users["staff"]["id"]
        for path, data in (("addUser.php", self.new_user_form(self.prefix + "csrf")),
                           (f"editUser.php?id={target}", self.user_form(self.users["staff"], first="Csrf")),
                           (f"userPassword.php?id={target}", {"new_password": "Changed!99", "confirm_password": "Changed!99"})):
            status, _, body = admin.request(path, data)
            self.assertEqual(status, 403, path)
            self.assertIn("form has expired", body)
        self.assertEqual(fixture("user_read", id=target), before)
        self.assertIsNone(fixture("user_read", id=self.prefix + "csrf")["user"])

    # ---- add ----

    def test_admin_adds_a_user_who_can_log_in(self):
        admin = self.login("fish.admin")
        username = self.prefix + "new"
        _, body = self.get(admin, "addUser.php")
        self.assertEqual(checked(body), {f"{a}/{x}" for a, c in self.presets["staff"].items() for x, on in zip(("view", "edit", "delete"), c) if on})
        status, headers, _ = self.post(admin, "addUser.php", self.new_user_form(username))
        self.assertEqual((status, headers["Location"]), (303, f"manageUsers.php?user={username}&did=created"))
        _, body = self.get(admin, headers["Location"])
        self.assertIn(f"User New {username[-6:]} created.", body)
        row = fixture("user_read", id=username)
        self.assertEqual((row["user"]["role"], row["user"]["status"], row["user"]["accessLevel"], row["user"]["name"]),
                         ("viewer", "active", 1, f"New {username[-6:]}"))
        self.assertEqual(row["perms"], self.presets["viewer"])
        newcomer = self.login(username)
        self.get(newcomer, "dashboard.php")

        # The role changed after the boxes were drawn (no JavaScript): the new role's presets apply.
        stale = self.prefix + "stale"
        status, _, _ = self.post(admin, "addUser.php", self.new_user_form(stale, role="manager", preset_role="viewer", **perm_fields(self.presets["viewer"])))
        self.assertEqual(status, 303)
        self.assertEqual(fixture("user_read", id=stale)["perms"], self.presets["manager"])

        # Custom boxes are kept when preset_role matches; data_import delete can never be granted.
        custom = self.prefix + "custom"
        form = self.new_user_form(custom, role="viewer")
        form.update({"perm[donors][edit]": "on", "perm[data_import][view]": "on", "perm[data_import][delete]": "on", "perm[bogus][view]": "on"})
        self.assertEqual(self.post(admin, "addUser.php", form)[0], 303)
        perms = fixture("user_read", id=custom)["perms"]
        self.assertEqual((perms["donors"], perms["data_import"]), ([1, 1, 0], [1, 0, 0]))
        self.assertNotIn("bogus", perms)

    def test_add_rejects_duplicates_and_bad_input(self):
        admin = self.login("fish.admin")
        count = len(fixture("users_read"))
        cases = {"username other case": self.new_user_form("FISH.Staff"),
                 "email in use": self.new_user_form(self.prefix + "dupe", email="SAM.STAFF@example.org"),
                 "bad username": self.new_user_form("bad name!"),
                 "short password": self.new_user_form(self.prefix + "short", new_password="short", confirm_password="short"),
                 "mismatch": self.new_user_form(self.prefix + "mis", confirm_password=self.password + "x"),
                 "nul byte": self.new_user_form(self.prefix + "nul", new_password="ab\0cdefghij", confirm_password="ab\0cdefghij"),
                 "admin role": None}
        for label, form in cases.items():
            with self.subTest(label):
                if form is None:
                    form = self.new_user_form(self.prefix + "root", role="admin")
                    browser = self.login("fish.manager")
                    fixture("permission", user_id="fish.manager", area="users", can_view=1, can_edit=1, can_delete=0)
                    browser = self.login("fish.manager")
                    status, _, body = self.post(browser, "addUser.php", form)
                    fixture("permission", user_id="fish.manager", area="users", can_view=1, can_edit=0, can_delete=0)
                    self.assertEqual(status, 200)
                    self.assertIn("Only an administrator can assign", body)
                else:
                    status, _, body = self.post(admin, "addUser.php", form)
                    self.assertEqual(status, 200)
                    self.assertIn('class="form-errors"', body)
                    assert_no_php_errors_in(self, body)
                    self.assertNotIn(self.password, body)
        self.assertEqual(len(fixture("users_read")), count)
        self.assertEqual(fixture("user_read", id="FISH.Staff")["user"]["name"], "Sam Staff")

    # ---- edit ----

    def test_edit_names_email_permissions_and_role(self):
        admin = self.login("fish.admin")
        viewer = self.users["viewer"]
        path = f"editUser.php?id={viewer['id']}"
        marker = "Suite" + secrets.token_hex(3)
        donor_id = fixture("donor_create", marker=marker, user=self.users["staff"]["id"])["id"]
        self.addCleanup(fixture, "donor_delete", id=donor_id)

        # Names and email rebuild `name`; the users row stays as stored.
        status, headers, _ = self.post(admin, path, self.user_form(viewer, first="Vera", last="Updated", email=f"{viewer['id']}.new@example.test"))
        self.assertEqual((status, headers["Location"]), (303, f"manageUsers.php?user={viewer['id']}&did=updated"))
        row = fixture("user_read", id=viewer["id"])
        self.assertEqual((row["user"]["first_name"], row["user"]["last_name"], row["user"]["name"], row["user"]["email"]),
                         ("Vera", "Updated", "Vera Updated", f"{viewer['id']}.new@example.test"))
        self.assertEqual(row["perms"], self.presets["viewer"])
        _, body = self.get(admin, headers["Location"])
        self.assertIn("User Vera Updated saved.", body)

        # Ticking donors edit lets the viewer save a donor; unticking revokes it on the next request.
        viewer_browser = self.login(viewer["id"])
        donor_edit = f"updateDonor.php?id={donor_id}"
        donor_form = {"type": "Individual", "first": "Suite", "middle": "", "last": marker, "email": f"{marker}@example.test",
                      "phone": "", "preferred": "Text", "street": "", "city": "Viewerville", "state": "VA", "zip": "", "action": "save"}
        self.assertEqual(viewer_browser.request(donor_edit, {"csrf_token": viewer_browser.token(donor_edit), **donor_form})[0], 403)
        form = self.user_form(viewer, first="Vera", last="Updated", email=f"{viewer['id']}.new@example.test")
        form["perm[donors][edit]"] = "on"
        self.assertEqual(self.post(admin, path, form)[0], 303)
        self.assertEqual(fixture("user_read", id=viewer["id"])["perms"]["donors"], [1, 1, 0])
        status, _, _ = viewer_browser.request(donor_edit, {"csrf_token": viewer_browser.token(donor_edit), **donor_form})
        self.assertEqual(status, 303)
        self.assertEqual(fixture("donor_read", id=donor_id)["city"], "Viewerville")
        del form["perm[donors][edit]"]
        self.assertEqual(self.post(admin, path, form)[0], 303)
        self.assertEqual(viewer_browser.request(donor_edit, {"csrf_token": viewer_browser.token(donor_edit), **donor_form})[0], 403)

        # A role change resets the rows to the new role's presets whatever boxes were posted.
        form = self.user_form(viewer, first="Vera", last="Updated", email=f"{viewer['id']}.new@example.test", role="staff")
        form["perm[donors][delete]"] = "on"
        self.assertEqual(self.post(admin, path, form)[0], 303)
        row = fixture("user_read", id=viewer["id"])
        self.assertEqual((row["user"]["role"], row["user"]["accessLevel"], row["perms"]), ("staff", 1, self.presets["staff"]))
        _, body = self.get(admin, path)
        self.assertIn('<option value="staff" selected>', body)

        # Duplicate email (other case) and a missing name write nothing.
        before = fixture("user_read", id=viewer["id"])
        for bad in ({"email": "MIA.MANAGER@example.org"}, {"first": ""}):
            status, _, body = self.post(admin, path, self.user_form({**viewer, "role": "staff"}, last="Updated", **bad))
            self.assertEqual(status, 200)
            self.assertIn('class="form-errors"', body)
            # The error page keeps the read-only username and re-renders the posted role and boxes.
            self.assertIn(f'value="{viewer["id"]}" readonly', body)
            self.assertIn('<option value="staff" selected>', body)
            self.assertTrue(re.search(r'name="perm\[donors\]\[edit\]"[^>]*\schecked', body), "posted box lost")
        self.assertEqual(fixture("user_read", id=viewer["id"]), before)
        # Same role, an extra ticked box and a blank name: the posted box survives the re-render.
        status, _, body = self.post(admin, path, {**self.user_form({**viewer, "role": "staff"}, first=""), "perm[reports][edit]": "on"})
        self.assertEqual(status, 200)
        self.assertTrue(re.search(r'name="perm\[reports\]\[edit\]"[^>]*\schecked', body), "posted box lost")
        # Changed role and a blank name: the table shows the new role's presets, which is what Save writes.
        area, action = next((a, x) for a in AREAS for i, x in enumerate(("view", "edit", "delete"))
                            if self.presets["manager"][a][i] and not self.presets["staff"][a][i])
        status, _, body = self.post(admin, path, self.user_form({**viewer, "role": "manager"}, first=""))
        self.assertEqual(status, 200)
        self.assertIn('<option value="manager" selected>', body)
        self.assertTrue(re.search(r'name="perm\[%s\]\[%s\]"[^>]*\schecked' % (area, action), body), "preset not shown")
        self.assertIn('name="preset_role" value="manager"', body)
        # Those preset boxes are drawn disabled: Save ignores them on a role change.
        self.assertIn("disabled", re.search(r'name="perm\[donors\]\[view\]"[^>]*>', body).group(0), "boxes not disabled")
        # An error page drawn for a promotion to admin disables the users row but does not mark it
        # data-locked (the stored account is not an admin), so the script re-enables it on revert.
        status, _, body = self.post(admin, path, self.user_form({**viewer, "role": "admin"}, first=""))
        self.assertEqual(status, 200)
        users_view = re.search(r'name="perm\[users\]\[view\]"[^>]*>', body).group(0)
        self.assertIn("disabled", users_view)
        self.assertNotIn("data-locked", users_view)
        self.assertEqual(fixture("user_read", id=viewer["id"]), before)
        # Give the account a custom row, then: from the error page the select is put back to the stored
        # role but the boxes were drawn for manager (JavaScript disables them, so none are posted). The
        # stored role's presets are written, replacing the custom row, never an all-zero matrix.
        self.assertEqual(self.post(admin, path, {**self.user_form({**viewer, "role": "staff"}), "perm[reports][edit]": "on"})[0], 303)
        self.assertEqual(fixture("user_read", id=viewer["id"])["perms"]["reports"][1], 1)
        form = self.user_form({**viewer, "role": "staff"}, first="Vera")
        form = {k: v for k, v in form.items() if not k.startswith("perm[")}
        form["preset_role"] = "manager"
        self.assertEqual(self.post(admin, path, form)[0], 303)
        self.assertEqual(fixture("user_read", id=viewer["id"])["perms"], self.presets["staff"])
        _, body = self.get(admin, path)
        self.assertIn('data-stored-role="staff"', body)
        self.assertTrue(re.search(r"<dt>Created</dt><dd>[A-Z][a-z]{2} \d{1,2}, \d{4}</dd>", body), "Created date missing")

        # The admin edits their own account: no 403, role and users row untouched, data_import delete stays 0.
        own = self.user_form(self.users["admin"], email=f"{self.users['admin']['id']}.own@example.test")
        own.update({"role": "viewer", "perm[users][view]": "", "perm[data_import][delete]": "on"})
        own.pop("perm[users][edit]", None)
        self_admin = self.login(self.users["admin"]["id"])
        self.assertEqual(self.post(self_admin, f"editUser.php?id={self.users['admin']['id']}", own)[0], 303)
        row = fixture("user_read", id=self.users["admin"]["id"])
        self.assertEqual((row["user"]["role"], row["user"]["email"], row["perms"]["users"], row["perms"]["data_import"]),
                         ("admin", f"{self.users['admin']['id']}.own@example.test", [1, 1, 1], [1, 1, 0]))
        _, body = self.get(self_admin, f"editUser.php?id={self.users['admin']['id']}")
        self.assertIn('<select id="role" name="role" disabled>', body)
        self.assertIn("You cannot deactivate your own account", body)
        self.assertNotIn('value="deactivate"', body)

    def test_deactivate_reactivate_and_password(self):
        admin = self.login("fish.admin")
        staff = self.users["staff"]
        path = f"editUser.php?id={staff['id']}"
        marker = "Suite" + secrets.token_hex(3)
        donor_id = fixture("donor_create", marker=marker, user=staff["id"])["id"]
        self.addCleanup(fixture, "donor_delete", id=donor_id)
        open_session = self.login(staff["id"])
        self.get(open_session, "dashboard.php")

        status, headers, _ = self.post(admin, path, {"action": "deactivate"})
        self.assertEqual((status, headers["Location"]), (303, f"manageUsers.php?user={staff['id']}&did=deactivated"))
        row = fixture("user_read", id=staff["id"])
        self.assertEqual((row["user"]["status"], row["user"]["accessLevel"], row["user"]["role"]), ("inactive", 0, "staff"))
        self.assertEqual(fixture("donor_read", id=donor_id)["updated_by"], staff["id"])
        status, headers, _ = open_session.request("dashboard.php")
        self.assertEqual((status, headers["Location"]), (303, "/login.php"))
        browser = Browser()
        self.assertEqual(browser.request("login.php", {"csrf_token": browser.token(), "username": staff["id"], "password": self.password})[0], 200)
        _, body = self.get(admin, "manageUsers.php")
        self.assertEqual(rows_in(body)[staff["id"]][4], "off")
        _, body = self.get(admin, path)
        self.assertIn('value="reactivate"', body)
        self.assertIn("This account is inactive", body)

        status, headers, _ = self.post(admin, path, {"action": "reactivate"})
        self.assertEqual((status, headers["Location"]), (303, f"manageUsers.php?user={staff['id']}&did=reactivated"))
        self.assertEqual(fixture("user_read", id=staff["id"])["user"]["status"], "active")
        self.login(staff["id"])

        new_password = "Changed!" + secrets.token_hex(6)
        pw_path = f"userPassword.php?id={staff['id']}"
        status, _, body = self.post(admin, pw_path, {"new_password": new_password, "confirm_password": new_password + "x"})
        self.assertEqual(status, 200)
        self.assertIn("Passwords do not match", body)
        self.assertNotIn(new_password, body)
        status, _, body = self.post(admin, pw_path, {"new_password": "Short!7", "confirm_password": "Short!7"})
        self.assertEqual(status, 200)
        self.assertIn("at least 8 characters", body)
        status, headers, _ = self.post(admin, pw_path, {"new_password": new_password, "confirm_password": new_password})
        self.assertEqual((status, headers["Location"]), (303, f"manageUsers.php?user={staff['id']}&did=password"))
        self.assertTrue(fixture("user_read", id=staff["id"])["user"]["password"].startswith("$2y$"))
        browser = Browser()
        self.assertEqual(browser.request("login.php", {"csrf_token": browser.token(), "username": staff["id"], "password": self.password})[0], 200)
        self.login(staff["id"], new_password)

    def test_admin_protections(self):
        admin = self.login("fish.admin")
        own = "editUser.php?id=fish.admin"
        peer = self.users["admin"]["id"]
        peer_path = f"editUser.php?id={peer}"
        manager = self.users["manager"]

        # Nobody deactivates their own account.
        self.assertEqual(self.post(admin, own, {"action": "deactivate"})[0], 403)
        self.assertEqual(fixture("user_read", id="fish.admin")["user"]["status"], "active")

        # A manager with the users edit permission still cannot touch an admin account.
        fixture("permission", user_id=manager["id"], area="users", can_view=1, can_edit=1, can_delete=0)
        self.addCleanup(fixture, "permission", user_id=manager["id"], area="users", can_view=1, can_edit=0, can_delete=0)
        mgr = self.login(manager["id"])
        before = fixture("user_read", id=peer)
        for path, data in ((peer_path, {"action": "deactivate"}),
                           (peer_path, self.user_form(self.users["admin"], first="Nope")),
                           (f"userPassword.php?id={peer}", {"new_password": "Changed!99", "confirm_password": "Changed!99"})):
            self.assertEqual(self.post(mgr, path, data, token_from="manageUsers.php")[0], 403, path)
        self.assertEqual(fixture("user_read", id=peer), before)
        _, body = self.get(mgr, peer_path)
        self.assertIn("Only an administrator can change an administrator account", body)
        self.assertNotIn("Save changes", body)
        # ...but it can add a non-admin and may not pick the admin role.
        _, body = self.get(mgr, "addUser.php")
        self.assertIn('<option value="admin" disabled>', body)

        # Last-admin rule. Only suite-created rows are touched: with the suite's admin inactive, fish.admin is
        # the last active admin (vmsroot never counts) unless this database has other admin accounts.
        others = [u["id"] for u in fixture("users_read") if u["role"] == "admin" and u["status"] == "active" and u["id"] not in ("fish.admin", "vmsroot")]
        if others != [peer]:
            self.skipTest(f"other active admin accounts exist on this database: {sorted(set(others) - {peer})}")
        self.addCleanup(fixture, "set_status", users=["fish.admin", peer], status="active")
        self.assertEqual(self.post(admin, peer_path, {"action": "deactivate"})[0], 303)
        before = fixture("user_read", id="fish.admin")
        # Through the pages this branch needs vmsroot or a race (another admin acting means two active admins),
        # so the model is called directly; both refuse and write nothing.
        self.assertEqual(fixture("last_admin_check", id="fish.admin"), {"deactivate": "last_admin", "demote": "last_admin"})
        self.assertEqual(fixture("user_read", id="fish.admin"), before)
        # With a second active admin both actions go through, in either direction.
        self.assertEqual(self.post(admin, peer_path, {"action": "reactivate"})[0], 303)
        acting = self.login(peer)
        self.assertEqual(self.post(acting, own, {"action": "deactivate"})[0], 303)
        self.assertEqual(fixture("user_read", id="fish.admin")["user"]["status"], "inactive")
        self.assertEqual(self.post(acting, peer_path, {"action": "deactivate"})[0], 403)   # own account
        self.assertEqual(self.post(acting, own, {"action": "reactivate"})[0], 303)
        admin = self.login("fish.admin")
        self.assertEqual(self.post(admin, peer_path, self.user_form(self.users["admin"], role="manager"))[0], 303)
        row = fixture("user_read", id=peer)
        self.assertEqual((row["user"]["role"], row["perms"]), ("manager", self.presets["manager"]))
        self.assertEqual(self.post(admin, peer_path, self.user_form(self.users["admin"], role="admin"))[0], 303)
        self.assertEqual(fixture("user_read", id=peer)["perms"], self.presets["admin"])

if __name__ == "__main__":
    unittest.main(verbosity=2)
