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
4. Run `make migrate` twice locally, then commit the file.

Files are run through the `mariadb` client, so `DELIMITER` works for triggers.

**MariaDB only.** The migrations use MariaDB 10.11 syntax (`ADD COLUMN IF NOT EXISTS`,
`CREATE OR REPLACE TRIGGER`), matching the Docker database in `compose.yaml`. The inherited
`casadb.sql` dump came from a MySQL 8.4 server; if the app is ever deployed against MySQL, the
`IF NOT EXISTS` guards need rewriting with `information_schema` checks like the foreign-key
guard in `002_donations_cash_fields.sql`.

## Sprint 1 schema (migrations 001-008)

| Table / view | Purpose | Ticket |
|---|---|---|
| `dbpersons` + `first_name`, `last_name`, `email`, `role`, `status` | Staff accounts; `role` is the source of truth, `accessLevel` is kept in sync for the legacy page gate | UF2-7, UF2-15 |
| `user_permissions` | View / edit / delete per area (donors, donations, campaigns, communications, reports, data_import, users). `include/permissions.php` reads the user's row or, when none exists, the role preset. Rows override the preset and outlive a role change: code that changes `dbpersons.role` must re-apply that user's preset rows (DELETE + INSERT ... FROM `role_permission_presets`). | UF2-7, UF2-83..86 |
| `role_permission_presets` (view) | Default permission rows per role; re-apply when a user's role changes | UF2-7 |
| `donors` + type, org fields, middle name, preferred contact, preference flags, `donor_since` | Add-donor form and donor list | UF2-60, UF2-9 |
| `donations` + `donation_date`, payment fields, platform, honoree, receipt, `recorded_by`, `net_amount` | Cash donation form and lists. Triggers keep `date` (legacy MM/DD/YYYY) and `donation_date` in sync | UF2-41, UF2-48, UF2-26 |
| `non_cash_donations`, `non_cash_distributions` | In-kind gifts and where they went | UF2-16, UF2-18 |
| `volunteer_hours` | Hours per supporter | UF2-27 |
| `communications` | Contact log per supporter | UF2-11, UF2-21, UF2-23, UF2-27 |
| `donor_summary` (view) | One row per donor with lifetime totals, last gift, derived status (new / active / lapsed), last contact and `is_active`, for donor.php and donorProfile.php (recreated by 008) | UF2-26, UF2-27 |
| `donors` + `is_active`, `updated_by`, `deactivated_at`, `deactivated_by` (008) | Soft delete from updateDonor.php and an audit of who last changed the row; 008 also backfills `user_permissions` for accounts created since 001 | UF2-26, UF2-27, UF2-60, UF2-81 |

`dbevents` doubles as the campaigns table: `donations.eventID` and every `campaign_id` column
point at it. Anonymous gifts use donor id 999 (migration 003 guarantees the row exists). Demo rows
from `make seed-demo` carry markers instead of fixed ids: activity rows are `recorded_by` a
`fish.*` demo user and demo donors/campaigns have notes starting with `[demo]`; the seed deletes
and re-inserts them on every run. Deleting a donor who has cash gifts, non-cash gifts, hours or
communications is blocked by foreign keys; deactivate through updateDonor.php (`is_active`) instead,
which keeps the history. Deleting is still blocked. `donor_summary` freezes its column list when created, so a migration
that adds a `donors` column must also recreate the view.
