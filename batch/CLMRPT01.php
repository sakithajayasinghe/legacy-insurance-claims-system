<?php
/* ====================================================================
 * CLMRPT01 - REMITTANCE SUMMARY REPORT
 * Tarrow Valley Mutual Health - CLAIMSYS
 *
 * usage: php CLMRPT01.php <output-dir>
 *
 * Prints REMIT.RPT from the files CLMADJ01 left in the output
 * directory (PAYMENTS, DENIALS, PENDED, CHECKS, GLEXTRACT) and the
 * claim table.  Run after CLMADJ01 in the same job.
 *
 * 2004-04-05 rjk  created
 * 2007-01-22 tmw  page headings, 56 lines a page (new printer)
 * 2013-04-08 dps  interest column                              CR-2190
 * 2019-10-21 akl  cheque / carried column                      CR-3127
 * ==================================================================== */

include(dirname(__FILE__) . '/../inc/config.inc.php');
include(dirname(__FILE__) . '/../inc/common.inc.php');

$g_page  = 0;
$g_lines = 99;
$g_fp    = 0;
$g_busdate = '';

function rpt($txt)
{
    global $g_page, $g_lines, $g_fp, $g_busdate, $g_company;
    if ($g_lines >= 56) {
        $g_page++;
        if ($g_page > 1) {
            fwrite($g_fp, "\f");
        }
        fwrite($g_fp, str_pad($g_company, 44) . str_pad('CLAIMSYS', 44) . 'PAGE ' . sprintf('%4d', $g_page) . "\n");
        fwrite($g_fp, str_pad('CLMRPT01  NIGHTLY ADJUDICATION - REMITTANCE SUMMARY', 70) . 'BUSINESS DATE ' . dt_show($g_busdate) . "\n");
        fwrite($g_fp, "\n");
        $g_lines = 3;
    }
    fwrite($g_fp, rtrim($txt) . "\n");
    $g_lines++;
}

function amt($c, $w)
{
    return str_pad(fmt_money($c), $w, ' ', STR_PAD_LEFT);
}

if ($argc < 2) {
    echo "CLMRPT01 - USAGE: CLMRPT01 <output-dir>\n";
    echo "CLMRPT01 - ENDED RC=16\n";
    exit(16);
}
$dir = $argv[1];
$g_busdate = get_busdate();
if (!db_open()) {
    echo "CLMRPT01 - CANNOT CONNECT TO DATABASE\n";
    echo "CLMRPT01 - ENDED RC=16\n";
    exit(16);
}
$f_pay = @file($dir . '/PAYMENTS.DAT');
$f_den = @file($dir . '/DENIALS.DAT');
$f_pnd = @file($dir . '/PENDED.DAT');
$f_chk = @file($dir . '/CHECKS.DAT');
$f_gl  = @file($dir . '/GLEXTRACT.DAT');
$g_fp  = @fopen($dir . '/REMIT.RPT', 'w');
if ($f_pay === false || $f_den === false || $f_pnd === false || $f_chk === false || $f_gl === false || !$g_fp) {
    echo "CLMRPT01 - CANNOT OPEN FILES IN " . $dir . "\n";
    echo "CLMRPT01 - ENDED RC=16\n";
    exit(16);
}

/* ---- totals off the payment file ---------------------------------- */
$n_app = 0;
$t = array(0, 0, 0, 0, 0, 0, 0, 0, 0);      /* bil alw cop ded coi ncv pay int prs */
for ($i = 0; $i < count($f_pay); $i++) {
    $l = $f_pay[$i];
    if (substr($l, 0, 2) != 'PD') {
        continue;
    }
    $n_app++;
    $t[0] += (int) substr($l, 56, 9);
    $t[1] += (int) substr($l, 65, 9);
    $t[2] += (int) substr($l, 74, 9);
    $t[3] += (int) substr($l, 83, 9);
    $t[4] += (int) substr($l, 92, 9);
    $t[5] += (int) substr($l, 101, 9);
    $t[6] += (int) substr($l, 110, 9);
    $t[7] += (int) substr($l, 119, 7);
    $t[8] += (int) substr($l, 126, 9);
}
$den = array();
$n_den = 0;
for ($i = 0; $i < count($f_den); $i++) {
    if (substr($f_den[$i], 0, 2) != 'DN') {
        continue;
    }
    $c = substr($f_den[$i], 61, 3);
    if (!isset($den[$c])) {
        $den[$c] = 0;
    }
    $den[$c]++;
    $n_den++;
}
ksort($den);
$pnd = array();
$n_pnd = 0;
$t_est = 0;
for ($i = 0; $i < count($f_pnd); $i++) {
    if (substr($f_pnd[$i], 0, 2) != 'PN') {
        continue;
    }
    $c = substr($f_pnd[$i], 61, 3);
    if (!isset($pnd[$c])) {
        $pnd[$c] = array(0, 0);
    }
    $pnd[$c][0]++;
    $pnd[$c][1] += (int) substr($f_pnd[$i], 114, 9);
    $n_pnd++;
}
ksort($pnd);

rpt('SECTION 1 - RUN CONTROL TOTALS');
rpt('');
rpt('  CLAIM LINES READ ............' . str_pad(number_format($n_app + $n_den + $n_pnd), 18, ' ', STR_PAD_LEFT));
rpt('  LINES APPROVED ..............' . str_pad(number_format($n_app), 18, ' ', STR_PAD_LEFT));
rpt('  LINES DENIED ................' . str_pad(number_format($n_den), 18, ' ', STR_PAD_LEFT));
rpt('  LINES PENDED ................' . str_pad(number_format($n_pnd), 18, ' ', STR_PAD_LEFT));
rpt('');
rpt('  BILLED ON APPROVED LINES ....' . amt($t[0], 18));
rpt('  ALLOWED .....................' . amt($t[1], 18));
rpt('  MEMBER COPAY ................' . amt($t[2], 18));
rpt('  MEMBER DEDUCTIBLE ...........' . amt($t[3], 18));
rpt('  MEMBER COINSURANCE ..........' . amt($t[4], 18));
rpt('  OVER BENEFIT MAXIMUM ........' . amt($t[5], 18));
rpt('  PLAN PAYMENT ................' . amt($t[6], 18));
rpt('  LATE PAYMENT INTEREST .......' . amt($t[7], 18));
rpt('  PATIENT RESPONSIBILITY ......' . amt($t[8], 18));
rpt('');

rpt('SECTION 2 - DENIALS BY REASON');
rpt('');
rpt('  CODE  REASON                                                  LINES');
rpt('  ----  --------------------------------------------------  ---------');
foreach ($den as $c => $n) {
    rpt('  ' . $c . '   ' . str_pad($g_rsn[$c], 50) . str_pad(number_format($n), 11, ' ', STR_PAD_LEFT));
}
if ($n_den == 0) {
    rpt('  NONE');
}
rpt('');

rpt('SECTION 3 - PENDED BY REASON');
rpt('');
rpt('  CODE  REASON                                                  LINES    ESTIMATED PAYMENT');
rpt('  ----  --------------------------------------------------  ---------  -------------------');
foreach ($pnd as $c => $v) {
    rpt('  ' . $c . '   ' . str_pad($g_rsn[$c], 50) . str_pad(number_format($v[0]), 11, ' ', STR_PAD_LEFT) . amt($v[1], 21));
}
if ($n_pnd == 0) {
    rpt('  NONE');
}
rpt('');

rpt('SECTION 4 - APPROVED LINES BY PLAN');
rpt('');
rpt('  PLAN  NAME                                LINES             ALLOWED                PAID');
rpt('  ----  ------------------------------  ---------  ------------------  ------------------');
$res = db_q("SELECT m.plan_id, p.plan_name, COUNT(*) AS n, SUM(c.allowed) AS alw, SUM(c.pay) AS pay "
    . "FROM claim c, member m, plan p WHERE m.member_id = c.member_id AND p.plan_id = m.plan_id "
    . "AND c.run_ind = 'C' AND c.status = 'A' GROUP BY m.plan_id, p.plan_name ORDER BY m.plan_id");
while ($row = mysqli_fetch_assoc($res)) {
    rpt('  ' . str_pad($row['plan_id'], 6) . str_pad($row['plan_name'], 30) . str_pad(number_format((int) $row['n']), 11, ' ', STR_PAD_LEFT)
        . amt((int) $row['alw'], 20) . amt((int) $row['pay'], 20));
}
rpt('');

rpt('SECTION 5 - PROVIDER REMITTANCE');
rpt('');
$names = array();
$res = db_q("SELECT prov_id, prov_name FROM provider");
while ($row = mysqli_fetch_assoc($res)) {
    $names[$row['prov_id']] = $row['prov_name'];
}
$h1 = '  PROVIDER NAME                            LINES          GROSS    INTEREST    BROUGHT FWD            NET  CHEQUE';
$h2 = '  -------- ------------------------------ ------ -------------- ----------- -------------- -------------- -------';
rpt($h1);
rpt($h2);
$n_iss = 0;
$n_car = 0;
$t_iss = 0;
for ($i = 0; $i < count($f_chk); $i++) {
    $l = $f_chk[$i];
    if (substr($l, 0, 2) != 'CK') {
        continue;
    }
    if ($g_lines >= 56) {
        rpt($h1);
        rpt($h2);
    }
    $prov = substr($l, 2, 7);
    $no   = substr($l, 9, 7);
    $cin  = (int) substr($l, 41, 10);
    $net  = (int) substr($l, 51, 12);
    $nm   = '';
    if (isset($names[$prov])) {
        $nm = $names[$prov];
    }
    if (substr($l, 73, 1) == 'I') {
        $n_iss++;
        $t_iss += $net;
    } else {
        $no = 'CARRIED';
        $n_car++;
    }
    rpt('  ' . $prov . '  ' . str_pad(substr($nm, 0, 30), 30) . str_pad((string) (int) substr($l, 16, 5), 7, ' ', STR_PAD_LEFT)
        . amt((int) substr($l, 21, 11), 15) . amt((int) substr($l, 32, 9), 12) . amt($cin, 15) . amt($net, 15) . ' ' . $no);
}
rpt('');
rpt('  CHEQUES ISSUED ..............' . str_pad(number_format($n_iss), 18, ' ', STR_PAD_LEFT));
rpt('  TOTAL OF CHEQUES ............' . amt($t_iss, 18));
rpt('  PROVIDERS CARRIED FORWARD ...' . str_pad(number_format($n_car), 18, ' ', STR_PAD_LEFT));
rpt('');

rpt('SECTION 6 - LEDGER EXTRACT');
rpt('');
rpt('  ACCT  DESCRIPTION                                   DEBIT              CREDIT');
rpt('  ----  ------------------------------  ------------------  ------------------');
for ($i = 0; $i < count($f_gl); $i++) {
    $l = $f_gl[$i];
    if (substr($l, 0, 2) == 'GL') {
        $a = (int) substr($l, 15, 13);
        if (substr($l, 14, 1) == 'D') {
            rpt('  ' . substr($l, 10, 4) . '  ' . substr($l, 28, 30) . amt($a, 20));
        } else {
            rpt('  ' . substr($l, 10, 4) . '  ' . substr($l, 28, 30) . str_repeat(' ', 20) . amt($a, 20));
        }
    } else if (substr($l, 0, 2) == 'GT') {
        rpt('        ' . str_pad('TOTAL', 30) . amt((int) substr($l, 5, 13), 20) . amt((int) substr($l, 18, 13), 20));
    }
}
rpt('');
rpt('*** END OF REPORT ***');
fclose($g_fp);

echo "CLMRPT01 - REPORT PAGES " . sprintf('%4d', $g_page) . "\n";
echo "CLMRPT01 - ENDED RC=0\n";
exit(0);
