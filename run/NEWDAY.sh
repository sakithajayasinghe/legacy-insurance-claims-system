#!/bin/sh
# NEWDAY - make up a new day of claims and run CLMJOB on it.
# The claims keyed on the intranet since the last run (spool/KEYED.DAT)
# are added to the end of the day's file, then the spool is emptied.
# Prints the run id on success.  Keeps the most recent KEEP_RUNS runs.
#
# usage: run/NEWDAY.sh [claims-file]   (default: generate a new file)

BASE=$(cd "$(dirname "$0")/.." && pwd)
OUTROOT=$BASE/output
SPOOL=$BASE/spool/KEYED.DAT
KEEP_RUNS=${KEEP_RUNS:-20}
COUNT=${COUNT:-10000}

RUNID=$(date +%Y%m%d_%H%M%S)
while [ -e "$OUTROOT/$RUNID" ]; do
    sleep 1
    RUNID=$(date +%Y%m%d_%H%M%S)
done
OUT=$OUTROOT/$RUNID
mkdir -p "$OUT" || exit 16

if [ -n "$1" ]; then
    cp "$1" "$OUT/CLAIMS.DAT" || exit 16
else
    python3 "$BASE/tools/gen_claims.py" --count "$COUNT" --seed "$(date +%s)" \
        --out "$OUT/CLAIMS.DAT" > /dev/null || exit 16
fi
if [ -s "$SPOOL" ]; then
    cat "$SPOOL" >> "$OUT/CLAIMS.DAT"
    : > "$SPOOL"
fi

"$BASE/run/RUNADJUD.sh" "$OUT/CLAIMS.DAT" "$OUT" > "$OUT/JOBLOG.TXT" 2>&1
RC=$?

ls -1 "$OUTROOT" | grep -E '^[0-9_]+$' | sort -r | tail -n +$((KEEP_RUNS + 1)) |
    while read -r old; do rm -rf "${OUTROOT:?}/$old"; done

echo "$RUNID"
exit $RC
