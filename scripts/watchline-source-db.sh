#!/usr/bin/env bash
set -euo pipefail
umask 077

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
data_dir="$project_root/.local/mysql"
socket_path="$project_root/.local/mysql.sock"
pid_path="$project_root/.local/mysql.pid"
port="${WATCHLINE_LOCAL_DB_PORT:-33107}"

case "${1:-status}" in
    start)
        mkdir -p "$project_root/.local"
        if mysqladmin --no-defaults --socket="$socket_path" -u root ping >/dev/null 2>&1; then
            echo "Watchline source database is already running on port $port."
            exit 0
        fi
        if [[ ! -d "$data_dir/mysql" ]]; then
            mariadb-install-db --no-defaults --datadir="$data_dir" --auth-root-authentication-method=normal --skip-test-db
        fi
        daemon="$(command -v mariadbd || command -v mysqld)"
        nohup "$daemon" --no-defaults --datadir="$data_dir" \
            --socket="$socket_path" --port="$port" --bind-address=127.0.0.1 \
            --pid-file="$pid_path" --log-error="$project_root/.local/mysql.log" \
            --skip-name-resolve > "$project_root/.local/mysql-console.log" 2>&1 &
        for ((attempt = 0; attempt < 50; attempt++)); do
            if mysqladmin --no-defaults --socket="$socket_path" -u root ping >/dev/null 2>&1; then
                echo "Watchline source database is running on 127.0.0.1:$port."
                exit 0
            fi
            sleep 0.2
        done
        echo "Database startup failed. See .local/mysql.log." >&2
        exit 1
        ;;
    stop)
        mysqladmin --no-defaults --socket="$socket_path" -u root shutdown
        echo "Watchline source database stopped. The storefront still uses SQLite."
        ;;
    status)
        mysqladmin --no-defaults --socket="$socket_path" -u root ping
        ;;
    *)
        echo "Usage: bash scripts/watchline-source-db.sh start|stop|status" >&2
        exit 1
        ;;
esac
