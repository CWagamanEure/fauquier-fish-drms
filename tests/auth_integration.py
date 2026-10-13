"""Run via `make test-auth` against the local Docker app and database."""

from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parent.parent
BASE_URL = "http://127.0.0.1"

# List the application entry points explicitly so accidentally removing a guard
# does not also remove its regression coverage. Authenticated writes are limited
# to temporary accounts owned by this suite.
PROTECTED_PAGES = """
dashboard.php donor.php donorProfile.php
ReportGeneration.php VolunteerRegister.php addDonationHandler.php addDonor.php
addEvent.php adminViewingEvents.php calendar.php cancelEvent.php changePassword.php
completeEvent.php date.php deleteEvent.php
deleteMultipleEvents.php deletePerson.php deleteResources.php deleteTimes.php
donationAddEdit.php donorSuccess.php download.php editDonationHandler.php editEvent.php
editProfile.php editTimes.php emailgen.php eventFailure.php
eventFailureBadDepartureTime.php eventList.php eventSearch.php eventSignUp.php
eventSuccess.php eventsOpenForSignUpReview.php export.php fromPendingRejectSignup.php
import.php index.php leaderboard.php milestonePoints.php processReport.php
requestFailed.php resetPassword.php resources.php searchindex.php selectVOTM.php
setTimes.php signupPending.php signupSuccess.php specificEvent.php template.php
uploadResources.php viewAllEvents.php viewProfile.php viewResources.php
viewSignUpList.php volunteerManagement.php volunteerReport.php
python/ImportHandler.php python/ExportHandler.php reportGeneration/formHandler.php
reportGeneration/reports/delHandler.php ai-email-gen/index.php
""".split()
PROTECTED_APIS = """
search_donors.php search_multi_criteria.php getVolunteers.php send_email.php
toggleLock.php ai-email-gen/send_email.php
""".split()

FIXTURES_PHP = r"""
require 'database/dbinfo.php';
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$con = connect();
$con->begin_transaction();
$rows = [];
foreach ($request['users'] as $user) {
    if ($request['action'] === 'create') {
        $stmt = $con->prepare('INSERT INTO dbpersons (id, name, password, accessLevel) VALUES (?, ?, ?, ?)');
        $hash = password_hash($user['password'], PASSWORD_BCRYPT);
        $stmt->bind_param('sssi', $user['id'], $user['name'], $hash, $user['level']);
    } elseif ($request['action'] === 'read') {
        $stmt = $con->prepare('SELECT id, accessLevel, role, status FROM dbpersons WHERE id = ?');
        $stmt->bind_param('s', $user['id']);
    } elseif ($request['action'] === 'status') {
        $stmt = $con->prepare('UPDATE dbpersons SET status = ? WHERE id = ?');
        $stmt->bind_param('ss', $user['status'], $user['id']);
    } else {
        $stmt = $con->prepare('DELETE FROM dbpersons WHERE id = ?');
        $stmt->bind_param('s', $user['id']);
    }
    $stmt->execute();
    if ($request['action'] === 'read') {
        $rows[] = $stmt->get_result()->fetch_assoc();
    }
    $stmt->close();
}
$con->commit();
$con->close();
if ($request['action'] === 'read') {
    echo json_encode($rows, JSON_THROW_ON_ERROR);
}
"""


def stored_username(username):
    # Existing PHP registration uses htmlspecialchars's HTML 4 apostrophe entity.
    return html.escape(username.strip(), quote=True).replace('&#x27;', '&#039;')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Browser:
    def __init__(self, base_url=BASE_URL):
        self.base_url = base_url
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPCookieProcessor(self.cookies)
        )

    def request(self, path, data=None, headers=None, method=None):
        payload = data if isinstance(data, bytes) else (
            urllib.parse.urlencode(data).encode() if data is not None else None
        )
        request = urllib.request.Request(
            self.base_url + "/" + path, data=payload, headers=headers or {}, method=method
        )
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode()

    def cookie_header(self):
        return "; ".join(f"{cookie.name}={cookie.value}" for cookie in self.cookies)

    def token(self, path="login.php"):
        status, _, body = self.request(path)
        assert status == 200, (path, status)
        token = re.search(r'name="csrf_token" value="([a-f0-9]{64})"', body)
        assert token, f"No form token at {path}"
        return token.group(1)


@contextmanager
def slow_handler_server():
    """Exercise real handlers with harmless Python workers and real PHP sessions.

    The temporary server shares Apache's session configuration. Only report,
    export and import routes are exposed; no database import or email is run.
    """
    with tempfile.TemporaryDirectory(prefix="fish-auth-worker-") as directory:
        work = Path(directory)
        (work / "reports").mkdir()
        worker = '''from pathlib import Path
import time
Path("started").touch()
deadline = time.monotonic() + 10
while not Path("release").exists() and time.monotonic() < deadline:
    time.sleep(0.01)
Path("finished").touch()
print("Authentication worker fixture complete")
'''
        (work / "reports" / "makeReport.py").write_text(worker)
        (work / "dbManager.py").write_text(worker)
        router = work / "router.php"
        router.write_text('''<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/ready') { echo 'ready'; return; }
$handlers = json_decode(getenv('AUTH_TEST_HANDLERS'), true);
if (!isset($handlers[$path])) { http_response_code(404); return; }
chdir(__DIR__);
require $handlers[$path];
''')
        with socket.socket() as port_socket:
            port_socket.bind(("127.0.0.1", 0))
            port = port_socket.getsockname()[1]
        base_url = f"http://127.0.0.1:{port}"
        handlers = {
            "/report.php": str(ROOT / "reportGeneration/formHandler.php"),
            "/export.php": str(ROOT / "python/ExportHandler.php"),
            "/import.php": str(ROOT / "python/ImportHandler.php"),
        }
        with (work / "server.log").open("w+") as log:
            server = subprocess.Popen(
                ["php", "-d", "output_buffering=4096", "-S", f"127.0.0.1:{port}", str(router)],
                cwd=work, env={**os.environ, "AUTH_TEST_HANDLERS": json.dumps(handlers)},
                stdout=log, stderr=subprocess.STDOUT,
            )
            try:
                deadline = time.monotonic() + 5
                while True:
                    try:
                        with urllib.request.urlopen(base_url + "/ready", timeout=0.2) as response:
                            if response.status == 200:
                                break
                    except (urllib.error.URLError, TimeoutError):
                        pass
                    if server.poll() is not None or time.monotonic() >= deadline:
                        log.seek(0)
                        raise AssertionError("Temporary PHP server did not start: " + log.read())
                    time.sleep(0.02)
                yield work, Browser(base_url)
            finally:
                (work / "release").touch()
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


def import_upload():
    boundary = "auth-test-" + secrets.token_hex(12)
    name = "auth-test-" + secrets.token_hex(12) + ".xlsx"
    payload = (
        f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="{name}"\r\n'
        'Content-Type: application/octet-stream\r\n\r\n'
        f'Harmless test upload\r\n--{boundary}--\r\n'
    ).encode()
    return payload, {"Content-Type": "multipart/form-data; boundary=" + boundary}, Path(tempfile.gettempdir()) / name


class AuthenticationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        prefix = "auth_test_" + secrets.token_hex(8)
        cls.users = {
            level: {
                "id": f"{prefix}_{level}",
                "name": f"Authentication test level {level}",
                "password": " spaces & ' " + secrets.token_hex(12) + " ",
                "level": level,
            }
            for level in (-1, 0, 1, 2, 3, 4, None)
        }
        for user in cls.users.values():
            user["username"] = user["id"]
        cls.users[0]["username"] += "界" * 100
        cls.users[0]["id"] = cls.users[0]["username"]
        cls.users[1]["username"] += "'&<test>"
        cls.users[1]["id"] = stored_username(cls.users[1]["username"])
        cls.run_fixtures("create")
        cls.addClassCleanup(cls.run_fixtures, "delete")
        cls.downloads = {}
        for kind, directory, extension in (
            ("resources", "uploads", "pdf"),
            ("reports", "reportGeneration/reports", "pdf"),
            ("exports", "python/exports", "xlsx"),
        ):
            path = ROOT / directory / f"{prefix}.{extension}"
            with path.open("xb") as fixture:
                fixture.write(b"Private authentication test document")
            cls.addClassCleanup(path.unlink, missing_ok=True)
            cls.downloads[kind] = (path.name, f"{directory}/{path.name}")

    @classmethod
    def run_fixtures(cls, action, users=None):
        result = subprocess.run(
            ["php", "-r", FIXTURES_PHP],
            input=json.dumps({"action": action, "users": list(cls.users.values()) if users is None else users}),
            text=True, check=True, cwd=ROOT, capture_output=True,
        )
        return json.loads(result.stdout) if action == "read" else None

    def setUp(self):
        self.browser = Browser()

    def login(self, level=3, browser=None):
        browser = browser or self.browser
        user = self.users[level]
        return browser.request("login.php", {
            "csrf_token": browser.token(),
            "username": "  " + user["username"].upper() + "  ",
            "password": user["password"],
        })

    def assert_no_php_errors(self, body):
        for marker in ("Fatal error", "Warning:", "Notice:", "Deprecated:"):
            self.assertNotIn(marker, body)

    def test_anonymous_pages_and_mutation_handlers_require_login(self):
        for method in ("GET", "POST"):
            for path in PROTECTED_PAGES:
                with self.subTest(method=method, path=path):
                    status, headers, body = self.browser.request(path, method=method)
                    self.assertEqual(status, 303)
                    self.assertEqual(headers["Location"], "/login.php")
                    self.assertEqual(body, "")
                    self.assertIn("no-store", headers["Cache-Control"])

    def test_anonymous_api_calls_return_401_without_data(self):
        for path in PROTECTED_APIS:
            for method in ("GET", "POST"):
                with self.subTest(path=path, method=method):
                    status, headers, body = self.browser.request(path, method=method)
                    self.assertEqual(status, 401)
                    self.assertEqual(headers["Content-Type"], "application/json")
                    self.assertEqual(json.loads(body), {
                        "success": False, "error": "Please log in to continue."
                    })

    def test_bootstrap_script_cannot_run_over_http(self):
        self.assertEqual(self.browser.request("insertAdmin.php")[0], 404)

    def test_login_cookie_and_csrf_protection(self):
        status, headers, _ = self.browser.request("login.php")
        self.assertEqual(status, 200)
        self.assertIn("HttpOnly", headers["Set-Cookie"])
        self.assertIn("SameSite=Lax", headers["Set-Cookie"])
        self.assertIn("no-store", headers["Cache-Control"])
        user = self.users[3]
        status, _, body = self.browser.request("login.php", {
            "username": user["id"], "password": user["password"],
        })
        self.assertEqual(status, 200)
        self.assertIn("Your login form has expired", body)
        self.assertEqual(self.browser.request("index.php")[0], 303)

    def test_invalid_credentials_and_malformed_inputs_fail_cleanly(self):
        token = self.browser.token()
        cases = [
            {}, {"username": "", "password": ""},
            {"username": "nobody", "password": "incorrect"},
            {"username": self.users[3]["id"], "password": "incorrect"},
            {"username": "' OR 1=1 -- ", "password": "incorrect"},
            {"username[]": "array", "password": "incorrect"},
            {"username": self.users[3]["id"], "password[]": "array"},
        ]
        for values in cases:
            with self.subTest(fields=list(values)):
                status, _, body = self.browser.request("login.php", {
                    **values, "csrf_token": token,
                })
                self.assertEqual(status, 200)
                self.assertIn("Unable to log in with that username and password.", body)
                self.assert_no_php_errors(body)
                self.assertEqual(self.browser.request("index.php")[0], 303)

    def test_access_level_derives_from_role(self):
        # Fixtures only set accessLevel; migration 001's trigger derives role from it and
        # normalizes unsupported values (-1, 4, NULL) to the staff level.
        for level in (-1, 4, None):
            with self.subTest(level=level):
                row = self.run_fixtures("read", [self.users[level]])[0]
                self.assertEqual((row["role"], row["accessLevel"]), ("staff", 1))
                browser = Browser()
                self.assertEqual(self.login(level, browser)[0], 303)
                self.assertEqual(browser.request("index.php")[0], 200)
                self.assertEqual(browser.request("VolunteerRegister.php")[0], 403)
        self.assertEqual(self.run_fixtures("read", [self.users[2]])[0]["role"], "manager")
        self.assertEqual(self.run_fixtures("read", [self.users[3]])[0]["role"], "admin")

    def test_inactive_accounts_cannot_log_in_and_lose_live_sessions(self):
        user = self.users[0]
        self.assertEqual(self.login(0)[0], 303)
        status, _, body = self.browser.request("index.php")
        self.assertEqual(status, 200)
        self.assertIn(user["name"], body)
        self.assertNotIn("volunteerReport.php", body)
        self.assert_no_php_errors(body)
        self.assertEqual(self.browser.request("VolunteerRegister.php")[0], 403)

        # Deactivation takes effect on the next request, not only at the next login.
        self.run_fixtures("status", [{"id": user["id"], "status": "inactive"}])
        self.addCleanup(self.run_fixtures, "status", [{"id": user["id"], "status": "active"}])
        status, headers, _ = self.browser.request("index.php")
        self.assertEqual((status, headers["Location"]), (303, "/login.php"))
        status, _, body = self.login(0)
        self.assertEqual(status, 200)
        self.assertIn("Unable to log in", body)

        self.run_fixtures("status", [{"id": user["id"], "status": "active"}])
        self.assertEqual(self.login(0)[0], 303)
        self.assertEqual(self.browser.request("index.php")[0], 200)

    def test_registration_to_login_and_standard_user_navigation(self):
        self.assertEqual(self.login()[0], 303)
        for suffix in ("plain", "amp&test", "quote'\"<test>\\", "literal&amp;test"):
            with self.subTest(username_kind=suffix):
                username = "auth_registration_" + secrets.token_hex(8) + suffix
                account = {"id": stored_username(username)}
                self.addCleanup(self.run_fixtures, "delete", [account])
                password = "Registration9!" + secrets.token_hex(10)
                status, _, body = self.browser.request("VolunteerRegister.php", {
                    "csrf_token": self.browser.token("VolunteerRegister.php"),
                    "first_name": "Temporary", "last_name": "Registration",
                    "username": username, "password": password,
                    "password-reenter": password,
                })
                self.assertEqual(status, 200)
                self.assert_no_php_errors(body)
                self.assertIn('document.location = "VolunteerRegister.php"', body)
                self.assertEqual(self.run_fixtures("read", [account])[0]["accessLevel"], 1)

                new_user = Browser()
                status, _, body = new_user.request("login.php", {
                    "csrf_token": new_user.token(), "username": " " + username.upper() + " ",
                    "password": password,
                })
                self.assertEqual(status, 303)
                status, _, dashboard = new_user.request("index.php")
                self.assertEqual(status, 200)
                self.assertIn("Temporary Registration", dashboard)
                self.assert_no_php_errors(dashboard)
                for broken_page in ("editProfile.php", "viewProfile.php", "volunteerReport.php",
                                    "inbox.php", "volunteerViewGroup.php", "viewDiscussions.php",
                                    "selectVOTM.php", "leaderboard.php"):
                    self.assertNotIn(broken_page, dashboard)
                links = set(re.findall(r'href="([^"?#]+\.php)"', dashboard))
                self.assertEqual(links, {"index.php", "viewResources.php", "changePassword.php"})
                for path in links:
                    status, _, body = new_user.request(path)
                    self.assertEqual(status, 200, path)
                    self.assert_no_php_errors(body)
                self.assertEqual(new_user.request("addDonor.php")[0], 403)
                token = new_user.token("logout.php")
                self.assertEqual(new_user.request("logout.php", {"csrf_token": token})[0], 303)
                self.assertEqual(new_user.request("index.php")[0], 303)

    def test_unicode_usernames_and_registration_length_limit(self):
        self.assertEqual(self.login()[0], 303)
        prefix = "auth_unicode_" + secrets.token_hex(8)
        cases = [
            (prefix + "界" * (256 - len(prefix)), True),
            (prefix + "界" * (251 - len(prefix)) + "&", True),
            (prefix + "界" * (257 - len(prefix)), False),
            (prefix + "x" * (257 - len(prefix)), False),
            (prefix + "界" * (252 - len(prefix)) + "&", False),
        ]
        for username, valid in cases:
            with self.subTest(stored_characters=len(stored_username(username)), valid=valid):
                account = {"id": stored_username(username)}
                self.addCleanup(self.run_fixtures, "delete", [account])
                password = "Unicode9!" + secrets.token_hex(10)
                status, _, body = self.browser.request("VolunteerRegister.php", {
                    "csrf_token": self.browser.token("VolunteerRegister.php"),
                    "first_name": "Temporary", "last_name": "Unicode",
                    "username": username, "password": password, "password-reenter": password,
                })
                self.assertEqual(status, 200 if valid else 400)
                self.assert_no_php_errors(body)
                row = self.run_fixtures("read", [account])[0]
                if valid:
                    self.assertEqual(row["id"], account["id"])
                else:
                    self.assertIsNone(row)
                user = Browser()
                status, _, body = user.request("login.php", {
                    "csrf_token": user.token(), "username": username, "password": password,
                })
                self.assertEqual(status, 303 if valid else 200)
                if valid:
                    self.assertEqual(user.request("index.php")[0], 200)
                    self.assertEqual(user.request("logout.php", {"csrf_token": user.token("logout.php")})[0], 303)
                else:
                    self.assertIn("Unable to log in", body)
                    self.assertEqual(user.request("index.php")[0], 303)

    def test_long_handlers_allow_logout_before_work_finishes(self):
        for path in ("report.php", "export.php", "import.php"):
            with self.subTest(handler=path):
                self.browser = Browser()
                self.assertEqual(self.login()[0], 303)
                token = self.browser.token("logout.php")
                cookie = self.browser.cookie_header()
                payload = {"export": "donor"} if path == "export.php" else {}
                headers = {"Cookie": cookie}
                if path == "import.php":
                    payload, upload_headers, upload_path = import_upload()
                    headers.update(upload_headers)
                    self.addCleanup(upload_path.unlink, missing_ok=True)
                with slow_handler_server() as (work, worker_browser), ThreadPoolExecutor(max_workers=1) as pool:
                    job = pool.submit(worker_browser.request, path, payload, headers)
                    try:
                        deadline = time.monotonic() + 5
                        while not (work / "started").exists() and not job.done() and time.monotonic() < deadline:
                            time.sleep(0.02)
                        self.assertTrue((work / "started").exists(), "Handler did not start its worker")
                        status, _, _ = self.browser.request("logout.php", {"csrf_token": token})
                        self.assertEqual(status, 303)
                        # The worker waits for this test to release it. Logout must
                        # finish first, not merely succeed after a slow operation.
                        self.assertFalse((work / "finished").exists(), "Logout waited for the worker")
                        self.assertEqual(Browser().request("index.php", headers={"Cookie": cookie})[0], 303)
                        if path == "import.php":
                            # Completing an old import must not overwrite a new login.
                            self.assertEqual(self.login()[0], 303)
                    finally:
                        (work / "release").touch()
                    status, response_headers, body = job.result(timeout=15)
                    self.assert_no_php_errors(body)
                    if path == "import.php":
                        self.assertEqual(status, 303)
                        self.assertEqual(response_headers["Location"], "/login.php")
                        self.assertNotIn("Set-Cookie", response_headers)
                        self.assertEqual(self.browser.request("index.php")[0], 200)
                    else:
                        self.assertEqual(status, 200 if path == "report.php" else 302)
                    self.assertEqual(Browser().request("index.php", headers={"Cookie": cookie})[0], 303)

    def test_import_saves_result_when_session_is_still_active(self):
        self.assertEqual(self.login()[0], 303)
        payload, headers, upload_path = import_upload()
        self.addCleanup(upload_path.unlink, missing_ok=True)
        headers["Cookie"] = self.browser.cookie_header()
        with slow_handler_server() as (work, worker_browser):
            (work / "release").touch()
            status, response_headers, body = worker_browser.request("import.php", payload, headers)
            self.assertEqual(status, 302)
            self.assertIn("../import.php?success=1", response_headers["Location"])
            self.assert_no_php_errors(body)
        status, _, body = self.browser.request("import.php?success=1")
        self.assertEqual(status, 200)
        self.assertIn("Authentication worker fixture complete", body)
        self.assert_no_php_errors(body)

    def test_standard_user_keeps_role_and_literal_credentials(self):
        status, headers, _ = self.login(1)
        self.assertEqual(status, 303)
        self.assertEqual(headers["Location"], "dashboard.php")
        status, _, body = self.browser.request("index.php")
        self.assertEqual(status, 200)
        self.assertIn(self.users[1]["name"], body)
        self.assertNotIn("Add Users", body)
        self.assert_no_php_errors(body)
        for path in ("VolunteerRegister.php", "donationAddEdit.php", "addEvent.php",
                     "addDonationHandler.php", "python/ExportHandler.php"):
            with self.subTest(path=path):
                self.assertEqual(self.browser.request(path, method="POST")[0], 403)
        self.assertEqual(self.browser.request("search_donors.php")[0], 403)

    def test_manager_is_not_promoted_to_super_admin(self):
        self.assertEqual(self.login(2)[0], 303)
        status, _, dashboard = self.browser.request("index.php")
        self.assertEqual(status, 200)
        self.assertIn("searchindex.php", dashboard)
        self.assert_no_php_errors(dashboard)
        self.assertEqual(self.browser.request("searchindex.php")[0], 200)
        self.assertEqual(self.browser.request("search_donors.php")[0], 200)
        for path in ("VolunteerRegister.php", "addDonor.php", "import.php", "export.php",
                     "ReportGeneration.php", "emailgen.php", "donationAddEdit.php"):
            with self.subTest(path=path):
                self.assertNotIn(path, dashboard)
                self.assertEqual(self.browser.request(path)[0], 403)
        self.assertEqual(self.run_fixtures("read", [self.users[2]])[0]["accessLevel"], 2)

    def test_super_admin_can_open_protected_pages(self):
        self.assertEqual(self.login()[0], 303)
        dashboard = self.browser.request("index.php")[2]
        for path in ("index.php", "VolunteerRegister.php", "searchindex.php",
                     "donationAddEdit.php", "import.php", "export.php", "ReportGeneration.php"):
            with self.subTest(path=path):
                status, _, body = self.browser.request(path)
                self.assertEqual(status, 200)
                self.assert_no_php_errors(body)
                self.assertIn(path, dashboard)

    def test_login_rotates_session_and_does_not_accept_old_cookie(self):
        self.browser.token()
        old_cookie = self.browser.cookie_header()
        self.assertEqual(self.login()[0], 303)
        self.assertNotEqual(self.browser.cookie_header(), old_cookie)
        replay = Browser()
        self.assertEqual(replay.request("index.php", headers={"Cookie": old_cookie})[0], 303)
        self.assertEqual(self.browser.request("login.php")[0], 302)

    def test_sessions_from_legacy_login_must_reauthenticate(self):
        result = subprocess.run(["php", "-r", """
            require 'include/auth.php';
            $_SESSION = ['logged_in' => true, '_id' => 'ordinary-user', 'access_level' => 3];
            echo auth_is_logged_in() ? 'authenticated' : 'unauthenticated';
        """], check=True, capture_output=True, text=True, cwd=ROOT)
        self.assertEqual(result.stdout, "unauthenticated")

    def test_documents_require_authentication_including_direct_file_urls(self):
        for kind, (name, direct_path) in self.downloads.items():
            with self.subTest(kind=kind):
                download = "download.php?" + urllib.parse.urlencode({"type": kind, "file": name})
                self.assertEqual(self.browser.request(download)[0], 303)
                self.assertEqual(self.browser.request(direct_path)[0], 403)
        self.assertEqual(self.login()[0], 303)
        for kind, (name, direct_path) in self.downloads.items():
            with self.subTest(kind=kind):
                download = "download.php?" + urllib.parse.urlencode({"type": kind, "file": name})
                status, headers, body = self.browser.request(download)
                self.assertEqual(status, 200)
                self.assertEqual(body, "Private authentication test document")
                self.assertIn("no-store", headers["Cache-Control"])
                self.assertEqual(self.browser.request(direct_path)[0], 403)
        token = self.browser.token("logout.php")
        self.browser.request("logout.php", {"csrf_token": token})
        self.assertEqual(self.browser.request(download)[0], 303)

    def test_document_permissions_and_path_traversal(self):
        self.assertEqual(self.login(1)[0], 303)
        for kind, (name, _) in self.downloads.items():
            with self.subTest(kind=kind):
                download = "download.php?" + urllib.parse.urlencode({"type": kind, "file": name})
                self.assertEqual(self.browser.request(download)[0], 200 if kind == "resources" else 403)
        for name in ("../.env", "../sql/casadb.sql", "..\\.env", "bad\x00.pdf", "missing.pdf"):
            download = "download.php?" + urllib.parse.urlencode({"type": "resources", "file": name})
            self.assertEqual(self.browser.request(download)[0], 404)
        self.assertEqual(self.browser.request("download.php?type=resources&file[]=test")[0], 404)

    def test_logout_requires_token_then_invalidates_session(self):
        self.assertEqual(self.login()[0], 303)
        old_cookie = self.browser.cookie_header()
        token = self.browser.token("logout.php")
        # GET only displays confirmation, and a forged POST cannot log the user out.
        self.assertEqual(self.browser.request("index.php")[0], 200)
        self.assertEqual(self.browser.request("logout.php", {"csrf_token": "wrong"})[0], 403)
        self.assertEqual(self.browser.request("index.php")[0], 200)
        status, headers, _ = self.browser.request("logout.php", {"csrf_token": token})
        self.assertEqual(status, 303)
        self.assertEqual(headers["Location"], "login.php?loggedOut=1")
        self.assertIn("Max-Age=0", headers["Set-Cookie"])
        self.assertEqual(self.browser.cookie_header(), "")
        self.assertEqual(self.browser.request("index.php")[0], 303)
        self.assertIn("You have been logged out.", self.browser.request(headers["Location"])[2])
        replay = Browser()
        for path in ("index.php", "donationAddEdit.php", "python/ExportHandler.php"):
            self.assertEqual(replay.request(path, headers={"Cookie": old_cookie})[0], 303)
        self.assertEqual(replay.request("search_donors.php", headers={"Cookie": old_cookie})[0], 401)


if __name__ == "__main__":
    unittest.main(verbosity=2)
