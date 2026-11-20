#!/bin/sh
# DBUP - make sure the CLAIMSYS database server is up and the claimsys
# database and batch account exist.  Safe to call any number of times.
# On the old box this was the "is mysqld alive" cron check.
#
# usage: run/DBUP.sh        (exit 0 = database ready, 16 = not available)

BASE=$(cd "$(dirname "$0")/.." && pwd)
DBU=clmbatch
DBP=clm2004

ping_db() {
    mysql -u"$DBU" -p"$DBP" -e "SELECT 1" claimsys > /dev/null 2>&1
}

ping_db && exit 0

if [ "$(id -u)" != "0" ]; then
    echo "DBUP     - DATABASE NOT AVAILABLE AND NOT ROOT, CANNOT START IT"
    exit 16
fi

if ! mysqladmin ping > /dev/null 2>&1; then
    mkdir -p /run/mysqld
    chown mysql:mysql /run/mysqld
    if [ ! -d /var/lib/mysql/mysql ]; then
        mariadb-install-db --user=mysql > /dev/null 2>&1
    fi
    mysqld_safe --user=mysql > /dev/null 2>&1 &
    n=0
    while ! mysqladmin ping > /dev/null 2>&1; do
        n=$((n + 1))
        if [ "$n" -gt 60 ]; then
            echo "DBUP     - DATABASE SERVER DID NOT START"
            exit 16
        fi
        sleep 1
    done
fi

mysql < "$BASE/db/bootstrap.sql" || exit 16
ping_db || exit 16
exit 0
