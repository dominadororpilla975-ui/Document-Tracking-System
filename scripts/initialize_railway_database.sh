#!/bin/sh
set -eu

db_host="${DB_HOST:-${MYSQLHOST:-}}"
db_port="${DB_PORT:-${MYSQLPORT:-3306}}"
db_user="${DB_USER:-${MYSQLUSER:-}}"
db_password="${DB_PASS:-${MYSQLPASSWORD:-}}"
db_name="${DB_NAME:-${MYSQLDATABASE:-}}"

if [ -z "$db_host" ] || [ -z "$db_user" ] || [ -z "$db_name" ]; then
    echo "Set DB_HOST, DB_USER, and DB_NAME (or their Railway MYSQL* equivalents)." >&2
    exit 1
fi

export MYSQL_PWD="$db_password"

required_tables="$(
    mysql --protocol=tcp --host="$db_host" --port="$db_port" --user="$db_user" \
        --batch --skip-column-names "$db_name" \
        --execute="SELECT COUNT(*) FROM information_schema.tables
                   WHERE table_schema = DATABASE()
                     AND table_name IN ('departments', 'users', 'reports')"
)"

if [ "$required_tables" -eq 0 ]; then
    echo "Initializing the empty application database schema."
    mysql --protocol=tcp --host="$db_host" --port="$db_port" --user="$db_user" \
        "$db_name" < database/Document_tracking.sql
elif [ "$required_tables" -ne 3 ]; then
    echo "The database has a partial application schema; refusing to initialize or migrate it." >&2
    exit 1
fi

php config/db_migrate.php
