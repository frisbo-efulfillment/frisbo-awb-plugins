.PHONY: bootstrap build up down restart install shell logs cache-clear \
	module-install module-uninstall module-reset module-enable module-disable nuke

bootstrap:
	./scripts/bootstrap-prestashop.sh

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

install:
	./scripts/install-shop.sh

shell:
	docker compose exec shop bash

logs:
	docker compose logs -f shop db

cache-clear:
	docker compose exec shop php bin/console cache:clear --env=dev

module-install module-uninstall module-reset module-enable module-disable:
	@test -n "$(MODULE)" || { echo "Usage: make $@ MODULE=module_name" >&2; exit 2; }
	docker compose exec shop php bin/console prestashop:module $(patsubst module-%,%,$@) $(MODULE)

nuke:
	@echo "WARNING: destroying the local database volume and shop/ tree; modules/ and .cache/ are preserved."
	docker compose down -v
	rm -rf -- shop
