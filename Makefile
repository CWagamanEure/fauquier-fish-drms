.DEFAULT_GOAL := help
.PHONY: help dev stop logs status

help:
	@echo "make dev     Build and start the Docker app and database"
	@echo "make stop    Stop services, preserving database data"
	@echo "make logs    Follow service logs (Ctrl-C to exit)"
	@echo "make status  Show service status and ports"

# Never overwrite existing local credentials or database seed settings.
.env:
	cp .env.example .env

dev: .env
	docker compose up -d --build --wait --wait-timeout 180
	@echo "Default URLs: app http://localhost:8080 | phpMyAdmin http://localhost:8081"
	@echo "If you changed ports in .env, run make status to see them."

stop:
	docker compose stop

logs:
	docker compose logs --tail=100 -f

status:
	docker compose ps
