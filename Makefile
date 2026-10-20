.DEFAULT_GOAL := help
.PHONY: help dev stop logs status migrate seed-demo db-shell test-auth hooks test-gate test-donors

help:
	@echo "make dev        Build and start the Docker app and database, then apply migrations"
	@echo "make stop       Stop services, preserving database data"
	@echo "make logs       Follow service logs (Ctrl-C to exit)"
	@echo "make status     Show service status and ports"
	@echo "make migrate    Apply pending sql/migrations/*.sql to the local database (safe to repeat)"
	@echo "make seed-demo  Load or reset the optional FISH demo data from sql/seed/demo_fish.sql"
	@echo "make db-shell   Open a mariadb shell in the db container"
	@echo "make test-auth  Run the login/logout integration tests against the local stack"
	@echo "make test-donors Run the donor page integration tests (needs make seed-demo)"
	@echo "make hooks      Point git at .githooks (pre-push review gate); make dev does this too"
	@echo "make test-gate  Test the pre-push review gate in a temporary repository"

# Never overwrite existing local credentials or database seed settings.
.env:
	cp .env.example .env

dev: .env hooks
	docker compose up -d --build --wait --wait-timeout 180
	@$(MAKE) --no-print-directory migrate
	@echo "Default URLs: app http://localhost:8080 | phpMyAdmin http://localhost:8081"
	@echo "If you changed ports in .env, run make status to see them."

stop:
	docker compose stop

logs:
	docker compose logs --tail=100 -f

status:
	docker compose ps

# Schema changes live in sql/migrations and are applied by scripts/db-migrate.sh.
# The compose seed file only runs on first volume init, so migrations are what keep an
# existing local database current. Both targets are safe to run repeatedly.
migrate: .env
	@docker compose up -d --wait --wait-timeout 180 db
	@scripts/db-migrate.sh

seed-demo: migrate
	@scripts/db-sql.sh < sql/seed/demo_fish.sql
	@echo "Demo data loaded (re-running resets the demo rows to their canonical values)."

db-shell:
	docker compose exec db sh -c 'MYSQL_PWD="$$MARIADB_PASSWORD" exec mariadb -u"$$MARIADB_USER" "$$MARIADB_DATABASE"'

test-auth:
	docker compose exec -T web python tests/auth_integration.py

# Review gate: git refuses to push a feature branch without a review-swarm approval record.
hooks:
	@git config core.hooksPath .githooks && echo "git hooks: .githooks (pre-push review gate active)"

test-gate:
	@tests/review_gate.sh

test-donors:
	docker compose exec -T web python tests/donors_integration.py
