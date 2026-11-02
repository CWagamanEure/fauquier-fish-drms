# UF2-129: storage for contact people of organization / foundation donors

## Jira keys

- UF2-129 (this ticket, Cory): "Research and decide how to save one or more people related to a Foundation
  or Organization donor in the database. Determine whether this should be a new table (e.g., a related
  contacts table linked to the donor) or an edit to the current donor table. Contact names are optional,
  since sometimes no name is ever provided (see UF2-127)."
- UF2-127 (Tristan, In Review): "For donors in the Organization and Foundation categories, add a place to
  enter contact names. The contact should NOT be a required field, because sometimes the name will never be
  known (e.g., the donation comes through an accountant who won't give a name)."
- UF2-130 (James, blocked by this): "Connect the contact person(s) entered for Organization and Foundation
  donors to the database, following however Cory sets up the storage in UF2-129 (new table or edit to the
  current donor table). Contact names remain optional (see UF2-127)."

## Problem

An organization donor can have several contact people (pastor, grants manager, treasurer). Today `donors`
has one free-text column, `org_contact VARCHAR(255) NULL` (migration 003), exposed by `donor_summary`. It
holds a name only, and nothing writes it: `addDonor.php` / `donor_insert()` do not save it, and
`include/donorForm.php` (UF2-127 front end) already posts `contacts[n][name|title|email|phone]` plus
`primary_contact`, with a note that the backend "still needs a table for these (one donor can have many
contacts)". Locally 2 of 7 organization donors have `org_contact` set, both from the demo seed.

## Decision

A new child table, not more columns on `donors`. One donor has many contacts, each with its own email and
phone, so columns on `donors` (contact_1_name, contact_2_name, ...) would cap the count and repeat fields.
A child table matches the form's repeating rows one to one.

## Scope

In:
- Migration `sql/migrations/013_donor_contacts.sql` (MariaDB 10.11, idempotent, safe to replay in CI):
  ```
  donor_contacts
    id          INT(11) AUTO_INCREMENT PRIMARY KEY
    donor_id    INT(11) NOT NULL        -- FK donors(id) ON DELETE CASCADE ON UPDATE RESTRICT
    name        VARCHAR(255) NULL       -- optional (UF2-127)
    title       VARCHAR(100) NULL       -- role, e.g. "Pastor", "Accountant"
    email       VARCHAR(100) NULL       -- same limits as donors.email / donors.phone
    phone       VARCHAR(30)  NULL
    is_primary  TINYINT(1) NOT NULL DEFAULT 0, CHECK (is_primary IN (0, 1))
    sort_order  SMALLINT NOT NULL DEFAULT 0   -- form row order
    created_at, updated_at TIMESTAMP; updated_by VARCHAR(256) NULL FK dbpersons(id) ON DELETE SET NULL
                                        ON UPDATE CASCADE (as donors.updated_by)
    primary_donor_id INT(11) AS (IF(is_primary = 1, donor_id, NULL)) PERSISTENT, UNIQUE
                                        -- at most one main contact per donor
    CHECK (donor_id <> 999)             -- the anonymous donor is never written
    CHECK (NULLIF(TRIM(name),'') IS NOT NULL OR NULLIF(TRIM(title),'') IS NOT NULL
        OR NULLIF(TRIM(email),'') IS NOT NULL OR NULLIF(TRIM(phone),'') IS NOT NULL)   -- no empty rows
    INDEX (donor_id, sort_order)
  ```
  Column types match `donors` (int(11) id, varchar(100) email, varchar(30) phone, varchar(256) user id,
  checked with `SHOW CREATE TABLE`), InnoDB, utf8mb4_general_ci.
  The donor FK is deliberately `ON UPDATE RESTRICT`, unlike the other donor FKs (`ON UPDATE CASCADE`):
  MariaDB 10.11 refuses a stored generated column or a CHECK on a column whose FK cascades on update
  (ERROR 1901, reproduced by the round 1 auditors). Donor ids are never renumbered, so nothing is lost.
- Backfill in the same migration, exactly:
  ```
  INSERT INTO donor_contacts (donor_id, name, is_primary, sort_order)
  SELECT d.id, TRIM(d.org_contact), 1, 0 FROM donors d
  WHERE d.donor_type = 'organization' AND d.id <> 999 AND TRIM(COALESCE(d.org_contact, '')) <> ''
    AND NOT EXISTS (SELECT 1 FROM donor_contacts c WHERE c.donor_id = d.id);
  ```
  Only organization donors (an individual's stray `org_contact` is not a contact person; locally there are
  none). Inactive organizations are included: their history is kept. The guard is "the donor has no contacts
  at all", so a replay, including CI's replay after the seed has added contacts, inserts nothing.
- `org_contact` stays on `donors` and in `donor_summary` for now (dropping it would mean recreating the view
  and is a cleanup for UF2-103); `sql/README.md` marks it deprecated: "read and write `donor_contacts`".
- `sql/README.md`: a `donor_contacts` row (UF2-129, UF2-130) stating the table and these rules for UF2-130:
  only `donor_type = 'organization'` (later foundation) donors get contacts; writes require
  `fish_can('donors', 'edit')` and the CSRF token and use prepared statements; every UPDATE/DELETE filters
  on both `id` and `donor_id`, with `donor_id` taken from the donor being edited on the server; at most 10
  contacts per donor, enforced on the server; changing the main contact clears the old one before setting
  the new one, in one transaction (otherwise the UNIQUE key fires midway).
- `sql/seed/demo_fish.sql`, right after section 4 inserts them, using `@d_rotary` and `@d_grace`:
  Rotary gets `Priya Natarajan` (primary, matching its `org_contact`) plus a non-primary `Treasurer` with a
  name and email; Grace gets `Rev. Ellen Moss` (primary, matching its `org_contact`) plus a non-primary
  contact with title `Accountant` and a phone but no name (the UF2-127 case). So AC3 holds after the seed too.
- Tests in `tests/donors_integration.py`, through new actions in the existing PHP fixture helper (no
  `docker compose exec`):
  - `contacts_constraints`: creates a throwaway organization donor, tries each insert in AC4-AC6 in a
    try/catch on `mysqli_sql_exception`, returns accepted/rejected per case, then deletes the donor and
    returns the remaining contact count for it (AC7).
  - `contacts_backfill`: creates a throwaway organization donor with `org_contact = '  Pat Doe '`, one with
    a blank `org_contact`, and one individual with an `org_contact`; runs the real `013_donor_contacts.sql`
    file through `multi_query` twice; returns the contacts for each; deletes the donors (AC3, AC2).
  - The seeded Rotary and Grace contacts are checked as described above (AC8).
- `README.md`: the `make test-donors` sentence mentions the donor_contacts checks (UF2-129).
- Jira: comment the decision, the table and the UF2-130 rules on UF2-129 and UF2-130.
- After merge (PR description): teammates run `make migrate` (and `make seed-demo` for the demo contacts).

Out:
- Reading `contacts[...]` from the POST, the insert/update/delete helpers in `database/dbDonors.php`, and
  showing contacts on `donorProfile.php`: UF2-130 (James). The form note "Contact people are not saved yet"
  stays until then.
- A `foundation` value for `donors.donor_type`: separate ticket (the radio stays disabled).
- Dropping `donors.org_contact` and removing the dead `newDonor.php` input: UF2-103.
- Contacts for individual donors.

## Acceptance criteria

1. `make migrate` applies 013 on a database at 012, and a second `make migrate` changes nothing.
2. Replaying the whole migration series the way CI and `sql/README.md` do leaves the `donor_contacts` row
   count unchanged; running 013 twice on throwaway donors adds rows only the first time.
3. After 013, each organization donor (not 999) with a non-blank `org_contact` and no prior contacts has
   exactly one primary row with `name = TRIM(org_contact)`; donors with a blank value, and individuals,
   get none.
4. A second `is_primary = 1` row for the same donor fails; two non-primary rows succeed; `is_primary = 2`
   fails.
5. A row with name, title, email and phone all NULL or blank fails; a row with only a title and phone succeeds.
6. A row with `donor_id = 999` fails; a row for a missing donor fails (FK).
7. Deleting a donor deletes its contacts.
8. `make seed-demo` runs twice without error and leaves Rotary with 2 contacts and Grace with 2 contacts,
   one primary each, as described above.
9. Existing suites still pass: test-donors, test-donations, test-auth, test-users, test-hours, test-gate.

## Verification plan

```
make migrate                                      # 1: on the local DB at 012 (has demo data)
SELECT ... donor_contacts JOIN donors             # 3: by hand, before seed-demo: Rotary/Grace rows from org_contact
make migrate                                      # 1: nothing applied
count; for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done; count   # 2
make seed-demo && make seed-demo                  # 8
make test-donors                                  # 2-8 (new tests) + existing
make test-donations; make test-auth; make test-users; make test-hours; make test-gate   # 9
php -l on touched PHP (in Docker)
```
CI replays every migration on a seeded database, so it exercises the replay guard but not a first-time
backfill; the `contacts_backfill` test and the manual step cover that.

## Risks

- ERROR 1901 if anyone later adds `ON UPDATE CASCADE` to the donor FK (see the note above).
- CHECK constraints are enforced since MariaDB 10.2; a replayed `CREATE TABLE IF NOT EXISTS` does not
  re-add them, so they are defined only in the CREATE.
- ON DELETE CASCADE: donors are normally soft-deleted (`is_active = 0`), so cascade only fires on a hard
  delete (seed teardown, tests); contacts of an inactive donor are kept.
- Contacts hold names, emails and phones (PII), like `donors`; no new page shows them in this ticket.

## Responses to round 1

- Critical (data, correctness), DDL fails with ERROR 1901: fixed, donor FK is `ON UPDATE RESTRICT`, reason
  recorded in Scope and Risks.
- Backfill guard unspecified (high, data): fixed, exact statement given, including 999 and blank exclusion.
- AC3 depends on seed / seed contradicts AC3 (high, data, correctness, verification): fixed, the seed keeps
  the `org_contact` names as primaries and adds the accountant as a non-primary row; the backfill is tested
  on its own throwaway donors, plus a manual check before seed-demo.
- Backfill copies individuals' org_contact (low): fixed, restricted to organizations; inactive ones kept.
- AC1/AC2 replay does not match README/CI (medium): fixed, the full-series loop is used.
- is_primary values other than 0/1 (medium): fixed, `CHECK (is_primary IN (0,1))`; the transaction note
  for changing the primary is in the README rules.
- Ambiguous Rotary/Grace (low): fixed, `@d_rotary` / `@d_grace`.
- Fixture actions for rejected inserts; drop docker exec fallback (medium, low): fixed, two named actions.
- UF2-130 IDOR rule and contact cap (medium, low, security): fixed, both in the README rules and Jira comment.
- After-merge steps and README test sentence (low): fixed.

## Implementation notes from round 2 (approved, findings low/medium, all taken)

- UF2-130 rules added to the sql/README row and the Jira comment: contacts are read only on pages gated by
  `fish_can('donors','view')`, by `donor_id`, every field escaped with `h()`; each field is trimmed and
  all-blank rows are skipped (not an error); `primary_contact` maps to the `contacts[]` key, and if that row
  was skipped the first saved row becomes primary; `sort_order` follows the posted order; past 10 rows the
  save is refused with a form error; saving contacts sets `donors.org_contact = NULL` in the same
  transaction, so a migration replay cannot bring a deleted contact back; contacts are kept when a donor
  switches to individual and shown/edited only while it is an organization.
- `contacts_backfill` drains every `multi_query` result and checks errno, deletes its donors in a
  `finally`, and runs outside the helper's shared transaction (013's DDL commits implicitly). 013 is plain
  server-side SQL (no DELIMITER). Running the real file applies the backfill to the whole database, which
  only repeats what the migration itself did.
- After any edit to 013 before merge: drop `donor_contacts`, delete its `schema_migrations` row, re-run
  `make migrate`.
- The `include/donorForm.php` comment is reworded to point at `donor_contacts` and UF2-130.
