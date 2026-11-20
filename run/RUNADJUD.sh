#!/bin/sh
# RUNADJUD - nightly claims adjudication job (CLMJOB).
#
#   STEP010  reload the database from db/schema.sql + db/seed.sql
#   STEP020  CLMADJ01  adjudicate, cheque run, ledger extract
#   STEP030  CLMEXP01  accumulator export
#   STEP040  CLMRPT01  remittance summary report
#
# usage: run/RUNADJUD.sh <claims-file> <output-dir>
#
# The business date comes from the BUSDATE variable (YYYYMMDD) or, when
# that is not set, from the control card ctl/BUSDATE.CTL, or failing
# both from the system date.
#
# 2010-06 dps  control card                                   CR-1730
# 2011-07 dps  test region: STEP010 reloads the snapshot on every run
#              so that a run can be repeated.  Production ran with
#              STEP010 commented out.                         CR-1877
# 2023-04 ops  one job at a time (flock), two runs at once corrupted
#              the accumulators in March                      CR-3310

BASE=$(cd "$(dirname "$0")/.." && pwd)
export CLAIMSYS_HOME="$BASE"
IN=${1:-$BASE/data/CLAIMS.DAT}
OUT=${2:-$BASE/output/$(date +%Y%m%d_%H%M%S)}
DBU=clmbatch
DBP=clm2004

abend() {
    echo "CLMJOB   - $1"
    echo "CLMJOB   - ENDED    $(date '+%Y-%m-%d %H:%M:%S')  RC=16"
    exit 16
}

echo "CLMJOB   - STARTED  $(date '+%Y-%m-%d %H:%M:%S')  INPUT=$IN"
[ -f "$IN" ] || abend "INPUT NOT FOUND: $IN"
mkdir -p "$OUT" || abend "CANNOT CREATE OUTPUT DIRECTORY: $OUT"

if [ -z "$BUSDATE" ]; then
    if [ -f "$BASE/ctl/BUSDATE.CTL" ]; then
        BUSDATE=$(grep '^BUSDATE=' "$BASE/ctl/BUSDATE.CTL" | head -1 | cut -c9-16)
    fi
    [ -n "$BUSDATE" ] || BUSDATE=$(date +%Y%m%d)
fi
export BUSDATE
echo "CLMJOB   - BUSINESS DATE $BUSDATE"

"$BASE/run/DBUP.sh" || abend "DATABASE NOT AVAILABLE"

# one job at a time: the lock is held on the schema file itself
exec 9< "$BASE/db/schema.sql" || abend "CANNOT OPEN LOCK FILE"
flock 9

MAXRC=0
step() {
    echo "CLMJOB   - $1 RC=$2"
    [ "$2" -gt "$MAXRC" ] && MAXRC=$2
}

# STEP010 - reload snapshot
mysql -u"$DBU" -p"$DBP" claimsys < "$BASE/db/schema.sql" \
  && mysql -u"$DBU" -p"$DBP" claimsys < "$BASE/db/seed.sql"
RC=$?
[ "$RC" -ne 0 ] && RC=16
step STEP010 $RC

if [ "$MAXRC" -lt 8 ]; then
    php "$BASE/batch/CLMADJ01.php" "$IN" "$OUT"
    step STEP020 $?
fi
if [ "$MAXRC" -lt 16 ]; then
    php "$BASE/batch/CLMEXP01.php" "$OUT"
    step STEP030 $?
    php "$BASE/batch/CLMRPT01.php" "$OUT"
    step STEP040 $?
fi

echo "CLMJOB   - ENDED    $(date '+%Y-%m-%d %H:%M:%S')  RC=$MAXRC  OUTPUT=$OUT"

# RC 0 = clean, RC 4 = completed with denied or pended lines,
# RC 8 = control totals out of balance, RC 16 = abend
if [ "$MAXRC" -gt 4 ]; then
    exit "$MAXRC"
fi
exit 0
