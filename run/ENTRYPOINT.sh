#!/bin/sh
# Container start: bring up the database, make sure there is at least
# one batch run to show, then start the intranet pages on port 8080.

BASE=$(cd "$(dirname "$0")/.." && pwd)
export CLAIMSYS_HOME="$BASE"

"$BASE/run/DBUP.sh" || exit 16
mkdir -p "$BASE/output" "$BASE/spool"
chown -R www-data:www-data "$BASE/output" "$BASE/spool"

if [ -z "$(ls -1 "$BASE/output" | grep -E '^[0-9_]+$')" ]; then
    su -s /bin/sh www-data -c "$BASE/run/NEWDAY.sh $BASE/data/CLAIMS.DAT" > /dev/null
fi

exec su -s /bin/sh www-data -c "PHP_CLI_SERVER_WORKERS=4 CLAIMSYS_HOME=$BASE php -S 0.0.0.0:8080 -t $BASE/web"
