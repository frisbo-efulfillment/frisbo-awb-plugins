#!/usr/bin/env bash
set -euo pipefail

root_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root_dir"

if [ ! -f shop/index.php ]; then
    echo "ERROR: PrestaShop source is missing. Run make bootstrap first." >&2
    exit 1
fi

shell_ps_domain=${PS_DOMAIN-}
shell_ps_domain_set=${PS_DOMAIN+x}
shell_ps_admin_dir=${PS_ADMIN_DIR-}
shell_ps_admin_dir_set=${PS_ADMIN_DIR+x}
shell_admin_email=${ADMIN_EMAIL-}
shell_admin_email_set=${ADMIN_EMAIL+x}
shell_admin_password=${ADMIN_PASSWORD-}
shell_admin_password_set=${ADMIN_PASSWORD+x}

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source ./.env
    set +a
fi

if [ "$shell_ps_domain_set" = x ]; then PS_DOMAIN=$shell_ps_domain; fi
if [ "$shell_ps_admin_dir_set" = x ]; then PS_ADMIN_DIR=$shell_ps_admin_dir; fi
if [ "$shell_admin_email_set" = x ]; then ADMIN_EMAIL=$shell_admin_email; fi
if [ "$shell_admin_password_set" = x ]; then ADMIN_PASSWORD=$shell_admin_password; fi

PS_DOMAIN=${PS_DOMAIN:-localhost:8088}
PS_ADMIN_DIR=${PS_ADMIN_DIR:-admin-dev}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.test}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-Dev123456!}
DB_SERVER=db
DB_PORT=3306
DB_NAME=prestashop
DB_USER=prestashop
DB_PASSWORD=prestashop

if ! [[ "$PS_ADMIN_DIR" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "ERROR: PS_ADMIN_DIR must be a single safe directory name." >&2
    exit 2
fi

docker compose up -d --build

echo "Waiting for MariaDB to become healthy..."
db_container=$(docker compose ps -q db)
for attempt in $(seq 1 60); do
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$db_container")
    if [ "$health" = healthy ]; then
        break
    fi
    if [ "$health" = unhealthy ]; then
        echo "ERROR: MariaDB healthcheck failed." >&2
        docker compose logs db >&2
        exit 1
    fi
    if [ "$attempt" -eq 60 ]; then
        echo "ERROR: timed out waiting for MariaDB." >&2
        docker compose logs db >&2
        exit 1
    fi
    sleep 2
done

if [ -f shop/app/config/parameters.php ]; then
    echo "PrestaShop is already installed; leaving the database and source untouched."
    exit 0
fi

if [ ! -f shop/install/index_cli.php ]; then
    echo "ERROR: PrestaShop installer source is missing. Run make bootstrap from a clean shop/ tree." >&2
    exit 1
fi

if [ -d shop/admin ]; then
    if [ -e "shop/$PS_ADMIN_DIR" ]; then
        echo "ERROR: both shop/admin and shop/$PS_ADMIN_DIR exist; refusing to overwrite either." >&2
        exit 1
    fi
    mv shop/admin "shop/$PS_ADMIN_DIR"
elif [ ! -d "shop/$PS_ADMIN_DIR" ]; then
    echo "ERROR: neither shop/admin nor shop/$PS_ADMIN_DIR exists." >&2
    exit 1
fi

docker compose exec -T shop \
    php -d memory_limit=-1 install/index_cli.php \
    --domain="$PS_DOMAIN" \
    --db_server="$DB_SERVER:$DB_PORT" \
    --db_name="$DB_NAME" \
    --db_user="$DB_USER" \
    --db_password="$DB_PASSWORD" \
    --prefix="ps_" \
    --firstname="Dev" \
    --lastname="Admin" \
    --password="$ADMIN_PASSWORD" \
    --email="$ADMIN_EMAIL" \
    --language="en" \
    --country="ro" \
    --newsletter=0 \
    --send_email=0 \
    --ssl=0

docker compose exec -T shop rm -rf /var/www/html/install
docker compose exec -T shop php bin/console cache:clear --env=dev

echo "Store:      $PS_DOMAIN"
echo "Back office $PS_DOMAIN/$PS_ADMIN_DIR"
echo "Admin user: $ADMIN_EMAIL"
