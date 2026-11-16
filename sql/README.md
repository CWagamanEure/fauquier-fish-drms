# Database schema

`casadb.sql` is the baseline dump inherited from the CASA project. Docker imports it (or the
file named by `DB_SEED_FILE` in `.env`) **only the first time** the database volume is created.
Everything after the baseline lives in `migrations/` and is applied by `make migrate`, which
`make dev` runs automatically. Do not re-export the database from phpMyAdmin into this folder.

## Applying migrations

```bash
make dev        # start the stack and apply pending migrations
make migrate    # apply pending migrations only (safe to repeat)
make seed-demo  # optional FISH demo data for local pages (safe to repeat; never on a real database)
make db-shell   # mariadb shell inside the db container
```

Applied files are recorded in the `schema_migrations` table with a checksum. The runner skips
files already listed and stops with an error if an applied file was edited afterwards.

## Adding a migration

1. Create `migrations/NNN_short_description.sql` with the next three-digit number.
2. Write it so it can run twice without error: `CREATE TABLE IF NOT EXISTS`,
   `ADD COLUMN IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`, `CREATE OR REPLACE VIEW/TRIGGER`,
   and backfills guarded by `WHERE target IS NULL`. Foreign keys on existing tables need the
   `information_schema` guard used in `002_donations_cash_fields.sql`.
3. Never edit or renumber a file after it has been pushed; add a new file instead.
4. Prove it is safe to re-run the way CI does: `make migrate` applies it, then replay the whole
   series in order against the migrated database and expect no errors:
   `for f in sql/migrations/[0-9][0-9][0-9]_*.sql; do scripts/db-sql.sh < "$f"; done`
   (a second `make migrate` proves nothing, it skips applied files by checksum). Then commit.

Files are run through the `mariadb` client, so `DELIMITER` works for triggers.

**MariaDB only.** The migrations use MariaDB 10.11 syntax (`ADD COLUMN IF NOT EXISTS`,
`CREATE OR REPLACE TRIGGER`), matching the Docker database in `compose.yaml`. The inherited
`casadb.sql` dump came from a MySQL 8.4 server; if the app is ever deployed against MySQL, the
`IF NOT EXISTS` guards need rewriting with `information_schema` checks like the foreign-key
guard in `002_donations_cash_fields.sql`.

**Production (SiteGround) runs MySQL 8 and is not migrated by these files or by the deploy.** The
repository owner ports each new migration to MySQL 8, applies it on SiteGround, and then adds its
filename to `sql/production-applied.txt`. The deploy job refuses to publish `main` while a migration is
missing from that list (the pull request check shows a warning). See `docs/deploy-siteground.md`,
"Releasing a migration".

## Sprint 1 schema (migrations 001-008, plus 010)

| Table / view | Purpose | Ticket |
|---|---|---|
| `dbpersons` + `first_name`, `last_name`, `email`, `role`, `status` | Staff accounts; `role` is the source of truth, `accessLevel` is kept in sync for the legacy page gate | UF2-7, UF2-15 |
| `user_permissions` | View / edit / delete per area (donors, donations, campaigns, communications, reports, data_import, users). `include/permissions.php` reads the user's row or, when none exists, the role preset. Rows override the preset and outlive a role change: code that changes `dbpersons.role` must re-apply that user's preset rows (DELETE + INSERT ... FROM `role_permission_presets`; `user_update()` in `database/dbUsers.php` does this for the Manage Users pages). | UF2-7, UF2-83..86 |
| `role_permission_presets` (view) | Default permission rows per role; re-apply when a user's role changes | UF2-7 |
| `donors` + type, org fields, middle name, preferred contact, preference flags, `donor_since` | Add-donor form and donor list. `org_contact` is deprecated since 013: read and write `donor_contacts` | UF2-60, UF2-9, UF2-129 |
| `donations` + `donation_date`, payment fields, platform, honoree, receipt, `recorded_by`, `net_amount` | Cash donation form and lists. Triggers keep `date` (legacy MM/DD/YYYY) and `donation_date` in sync | UF2-41, UF2-48, UF2-26 |
| `non_cash_donations`, `non_cash_distributions` | In-kind gifts (quantity in items plus `weight_lbs`, required on save since 012, which dropped `unit`) and where they went | UF2-16, UF2-18, UF2-124 |
| `volunteer_hours` | Hours per supporter | UF2-27 |
| `volunteer_hours` + `start_time`, `end_time`, `location`, `is_verified`, `counts_for_letter` (010) | Shift times, place and the two coordinator flags on addHours.php / hours.php | UF2-116..120 |
| `volunteer_hours_changes` (010) | One plain-text summary per saved edit of an hours entry (who and when); cascades with its entry. The entry's own `created_at` / `recorded_by` stand for "Entry logged" | UF2-120 |
| `donor_contacts` (013) | Contact people of an organization donor: optional `name` (UF2-127), `title`, `email`, `phone`, `sort_order`, at most one `is_primary` per donor (unique on the generated `primary_donor_id`). CHECKs refuse an all-blank row, `is_primary` other than 0/1 and donor 999. Cascades when the donor row is deleted; its donor FK is `ON UPDATE RESTRICT` because MariaDB refuses the generated column and CHECK otherwise. 013 copied each organization's `org_contact` in as its main contact. See the UF2-130 rules below | UF2-129, UF2-130 |
| `communications` + `person_contacted_id`, `follow_up_on`, status `follow_up` (014) | Contact log per supporter. `person_contacted` is the display name (a staff member's name, or a typed name for someone without an account); `person_contacted_id` links the `dbpersons` account when one was picked (SET NULL when it is deleted). `addCommunication.php` writes one-on-one rows through `database/dbCommunications.php` | UF2-11, UF2-21, UF2-23, UF2-27, UF2-146..148 |
| `donor_summary` (view) | One row per donor with lifetime totals, last gift, derived status (active / lybunt / sybunt / inactive since 011; new / active / lapsed before), last contact and `is_active`, for donor.php and donorProfile.php (recreated by 008 and 011) | UF2-26, UF2-27 |
| `donors` + `is_active`, `updated_by`, `deactivated_at`, `deactivated_by` (008) | Soft delete from updateDonor.php and an audit of who last changed the row; 008 also backfills `user_permissions` for accounts created since 001 | UF2-26, UF2-27, UF2-60, UF2-81 |
| `donors.donor_type` + `'foundation'` (015) | Foundations use the organization fields and contact people; `donor_summary` shows their `org_name` (recreated by 015) | UF2-129 |
| `outreach`, `outreach_channels`, `outreach_files` (016) | One row per social post or mass mailing, one row per channel it went out on, and its optional photo (stored privately under `uploads/outreach/`, served by `download.php?type=outreach`). The app removes the file from disk when a photo is replaced or removed or the post is deleted; the `ON DELETE CASCADE` on `outreach_files` only removes the row, so any other code that deletes outreach rows must remove the file too | UF2-141..144 |
| `outreach_channels.post_url` (017) | One link per channel (a post on Facebook and Instagram has two). 017 copied each post's old single `outreach.post_url` onto its channels and cleared it; that column is no longer read or written | UF2-141..144 |
| `import_batches`, `import_rows` (018) | Data & Imports: one `import_batches` row per uploaded file (`checked` until Import, then `importing` / `imported`, or `cancelled`; an Import interrupted for 10 minutes is closed and its leftover rows go to Needs review) and one `import_rows` row per data row, holding its cells as JSON and its status: `ready` (passed every check), `error`, `duplicate` (`match_id` is the record it matches), `imported` (`record_id` is the record created; NULL for volunteer hours), `skipped`, or `saving` while it is being saved. `dup_key` is what makes two rows of one file the same. The uploaded file itself is not kept | Sprint 5 imports |

`dbevents` doubles as the campaigns table: `donations.eventID` and every `campaign_id` column
point at it. A `completed` value of `'1'` or `'yes'` hides a campaign from the donation form. Anonymous gifts use donor id 999 (migration 003 guarantees the row exists). Demo rows
from `make seed-demo` carry markers instead of fixed ids: activity rows are `recorded_by` a
`fish.*` demo user and demo donors/campaigns have notes starting with `[demo]`; the campaign demo
(seed section 9) marks its donors and activity `demo-seed` and its campaigns `[demo]`. The seed deletes
and re-inserts all of them on every run. Deleting a donor who has cash gifts, non-cash gifts, hours or
communications is blocked by foreign keys, which keeps the history; there is currently no UI to
deactivate a donor instead. Deleting is still blocked. `donor_summary` freezes its column list when created, so a migration
that adds a `donors` column must also recreate the view.

Rules for saving and showing `donor_contacts` (UF2-130):

- Only `donor_type = 'organization'` donors (later foundation) get contacts. Contacts are kept when a donor
  becomes an individual, and are shown and edited only while it is an organization.
- Writes need `fish_can('donors', 'edit')` and the CSRF token, and use prepared statements. Every UPDATE or
  DELETE filters on both `id` and `donor_id`, with `donor_id` taken from the donor being edited on the server.
- Trim each posted field and skip rows that are all blank (not an error). `primary_contact` names a
  `contacts[]` key; if that row was skipped, the first saved row is the main contact. `sort_order` follows
  the posted order. More than 10 rows is refused with a form error.
- Changing the main contact clears the old one before setting the new one, in one transaction (the unique
  key fires otherwise). Saving contacts sets `donors.org_contact = NULL` in the same transaction, so a
  migration replay never brings a deleted contact back.
- Read contacts by `donor_id`, only on pages gated by `fish_can('donors', 'view')`, and escape every field
  with `h()`.
