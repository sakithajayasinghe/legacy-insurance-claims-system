<?php
/* ====================================================================
 * CLMEXP01 - END OF RUN ACCUMULATOR EXPORT
 * Tarrow Valley Mutual Health - CLAIMSYS
 *
 * usage: php CLMEXP01.php <output-dir>
 *
 * Unloads the accumulators as they stand after adjudication to
 * ACCUM.DAT for the member portal load and the actuaries.
 *   AI member, year, deductible met, out-of-pocket met
 *   AF subscriber, year, family deductible met, family out-of-pocket
 *   AB member, year, service class, benefit amount used
 *   AL member, lifetime amount used (only when not zero)
 *   AP provider, carried balance (only when not zero)
 *   AT trailer with the record counts
 *
 * 2006-07-03 tmw  created                                      CR-0877
 * 2012-08-13 dps  AB records                                   CR-2044
 * 2019-10-21 akl  AP records                                   CR-3127
 * ==================================================================== */

include(dirname(__FILE__) . '/../inc/config.inc.php');
include(dirname(__FILE__) . '/../inc/common.inc.php');

if ($argc < 2) {
    echo "CLMEXP01 - USAGE: CLMEXP01 <output-dir>\n";
    echo "CLMEXP01 - ENDED RC=16\n";
    exit(16);
}
if (!db_open()) {
    echo "CLMEXP01 - CANNOT CONNECT TO DATABASE\n";
    echo "CLMEXP01 - ENDED RC=16\n";
    exit(16);
}
$fp = @fopen($argv[1] . '/ACCUM.DAT', 'w');
if (!$fp) {
    echo "CLMEXP01 - CANNOT OPEN ACCUM.DAT\n";
    echo "CLMEXP01 - ENDED RC=16\n";
    exit(16);
}

$n_ai = 0;
$n_af = 0;
$n_ab = 0;
$n_al = 0;
$n_ap = 0;

$res = db_q("SELECT member_id, plan_yr, ded_met, oop_met FROM accum ORDER BY member_id, plan_yr");
while ($row = mysqli_fetch_row($res)) {
    fwrite($fp, 'AI' . $row[0] . sprintf('%04d', $row[1]) . sprintf('%09d', $row[2]) . sprintf('%09d', $row[3]) . "\n");
    $n_ai++;
}
$res = db_q("SELECT sub_id, plan_yr, ded_met, oop_met FROM famaccum ORDER BY sub_id, plan_yr");
while ($row = mysqli_fetch_row($res)) {
    fwrite($fp, 'AF' . $row[0] . sprintf('%04d', $row[1]) . sprintf('%09d', $row[2]) . sprintf('%09d', $row[3]) . "\n");
    $n_af++;
}
$res = db_q("SELECT member_id, plan_yr, svc_class, amt_used FROM bnaccum ORDER BY member_id, plan_yr, svc_class");
while ($row = mysqli_fetch_row($res)) {
    fwrite($fp, 'AB' . $row[0] . sprintf('%04d', $row[1]) . $row[2] . sprintf('%09d', $row[3]) . "\n");
    $n_ab++;
}
$res = db_q("SELECT member_id, life_used FROM member WHERE life_used <> 0 ORDER BY member_id");
while ($row = mysqli_fetch_row($res)) {
    fwrite($fp, 'AL' . $row[0] . sprintf('%011d', $row[1]) . "\n");
    $n_al++;
}
$res = db_q("SELECT prov_id, carry_bal FROM provider WHERE carry_bal <> 0 ORDER BY prov_id");
while ($row = mysqli_fetch_row($res)) {
    fwrite($fp, 'AP' . $row[0] . fmt_sign((int) $row[1], 9) . "\n");
    $n_ap++;
}
fwrite($fp, 'AT' . sprintf('%07d', $n_ai) . sprintf('%07d', $n_af) . sprintf('%07d', $n_ab) . sprintf('%07d', $n_al)
    . sprintf('%07d', $n_ap) . "\n");
fclose($fp);

echo "CLMEXP01 - ACCUMULATOR RECORDS " . sprintf('%7d', $n_ai + $n_af + $n_ab + $n_al + $n_ap) . "\n";
echo "CLMEXP01 - ENDED RC=0\n";
exit(0);
