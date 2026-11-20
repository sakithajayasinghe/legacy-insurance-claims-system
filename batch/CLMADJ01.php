<?php
/* ====================================================================
 * CLMADJ01 - NIGHTLY CLAIMS ADJUDICATION
 * Tarrow Valley Mutual Health - CLAIMSYS
 *
 * usage: php CLMADJ01.php <claims-file> <output-dir>
 *
 * Reads the day's claims file, edits and prices every claim line,
 * posts the accumulators, then runs the cheque cycle and the ledger
 * extract.  Writes PAYMENTS.DAT DENIALS.DAT PENDED.DAT CHECKS.DAT
 * GLEXTRACT.DAT AUDIT.LOG into the output directory.
 *
 * CHANGE LOG
 * 2004-03-22 rjk  first version, replaces the Access adjudication macro
 * 2004-09-02 rjk  timely filing                                 CR-0118
 * 2005-01-17 rjk  benefit table (was hard coded per plan)       CR-0412
 * 2005-09-12 rjk  storm filing extension                        CR-0561
 * 2006-02-27 tmw  units of zero default to one, EDI vendor
 *                 sends blanks as zeros                         CR-0733
 * 2006-06-19 tmw  family accumulators                           CR-0877
 * 2007-03-05 tmw  duplicate check now before timely filing,
 *                 Provider Relations complaint                  CR-1012
 * 2007-11-26 tmw  pre-authorisation                             CR-1190
 * 2008-02-11 tmw  integer cents everywhere, rhu()               CR-1245
 * 2009-02-09 dps  grace period via v_elig                       CR-1502
 * 2009-10-05 dps  emergency out of network at network benefit   CR-1688
 * 2010-06-14 dps  business date from control card               CR-1730
 * 2011-03-21 dps  HDHP aggregate family deductible              CR-1851
 * 2012-08-13 dps  annual benefit maximums                       CR-2044
 * 2013-04-08 dps  prompt pay interest                           CR-2190
 * 2014-01-13 akl  large groups have no waiting period           CR-2302
 * 2015-05-18 akl  corrected claims (type R), void status        CR-2610
 * 2016-09-26 akl  high dollar review                            CR-2788
 * 2017-06-05 akl  copays count to out-of-pocket by plan flag    CR-2871
 * 2019-10-21 akl  minimum cheque, carry balance                 CR-3127
 * 2021-02-08 akl  student limit raised 23 -> 24                 CR-3240
 * 2023-04-17 ops  PHP 8 changes only, no logic                  CR-3310
 * ==================================================================== */

include(dirname(__FILE__) . '/../inc/config.inc.php');
include(dirname(__FILE__) . '/../inc/common.inc.php');

/* ---- work areas (global, everything below uses them) -------------- */
$g_busdate = '';
$g_seq     = 0;             /* input record number                    */
$g_rec     = array();       /* current claim line off the file        */
$g_mbr     = 0;             /* member row (v_elig)                    */
$g_prv     = 0;             /* provider row                           */
$g_prc     = 0;             /* procedure row                          */
$g_pln     = 0;             /* plan row                               */
$g_bn      = 0;             /* benefit row                            */
$g_emg     = 0;             /* 1 = emergency                          */
$g_resub   = 0;             /* 1 = accepted corrected claim           */
$g_grace   = 0;             /* 1 = service date in the grace period   */
$g_bnused  = 0;             /* benefit maximum used so far            */
$g_w       = array();       /* pricing work area                      */

$g_plans = array();
$g_bnfts = array();
$g_procs = array();
$g_diags = array();
$g_provs = array();

$g_cnt_read = 0;
$g_cnt_appr = 0;
$g_cnt_deny = 0;
$g_cnt_pend = 0;
$g_tot_pay  = 0;
$g_tot_int  = 0;
$g_rc       = 0;

$fp_pay = 0;
$fp_den = 0;
$fp_pnd = 0;
$fp_aud = 0;

/* ==================================================================== */
/* MAIN                                                                 */
/* ==================================================================== */
if ($argc < 3) {
    echo "CLMADJ01 - USAGE: CLMADJ01 <claims-file> <output-dir>\n";
    echo "CLMADJ01 - ENDED RC=16\n";
    exit(16);
}
$in_file = $argv[1];
$out_dir = $argv[2];

$g_busdate = get_busdate();
if (!dt_ok($g_busdate)) {
    echo "CLMADJ01 - INVALID BUSINESS DATE [" . $g_busdate . "]\n";
    echo "CLMADJ01 - ENDED RC=16\n";
    exit(16);
}
$fp_in = @fopen($in_file, 'r');
if (!$fp_in) {
    echo "CLMADJ01 - CANNOT OPEN CLAIMS FILE " . $in_file . "\n";
    echo "CLMADJ01 - ENDED RC=16\n";
    exit(16);
}
if (!db_open()) {
    echo "CLMADJ01 - CANNOT CONNECT TO DATABASE\n";
    echo "CLMADJ01 - ENDED RC=16\n";
    exit(16);
}
$fp_pay = @fopen($out_dir . '/PAYMENTS.DAT', 'w');
$fp_den = @fopen($out_dir . '/DENIALS.DAT', 'w');
$fp_pnd = @fopen($out_dir . '/PENDED.DAT', 'w');
$fp_aud = @fopen($out_dir . '/AUDIT.LOG', 'w');
if (!$fp_pay || !$fp_den || !$fp_pnd || !$fp_aud) {
    echo "CLMADJ01 - CANNOT OPEN OUTPUT FILES IN " . $out_dir . "\n";
    echo "CLMADJ01 - ENDED RC=16\n";
    exit(16);
}

echo "CLMADJ01 - BUSINESS DATE " . $g_busdate . "\n";
fwrite($fp_aud, "CLMADJ01 START BUSDATE=" . $g_busdate . "\n");
load_ref();

while (!feof($fp_in)) {
    $line = fgets($fp_in, 4096);
    if ($line === false) {
        break;
    }
    $line = rtrim($line, "\r\n");
    $g_seq++;
    $g_cnt_read++;
    get_rec($line);

    $code = edit_rec($line);
    if ($code != '') {
        /* format rejects never reach the claim table */
        put_deny($code);
        continue;
    }
    if ($g_rec['units'] == 0) {
        $g_rec['units'] = 1;                                 /* CR-0733 */
        audit('INFO     UNITS DEFAULTED TO 1');
    }

    $disp = adjud();
    post_claim($disp[0], $disp[1]);
    if ($disp[0] == 'D') {
        put_deny($disp[1]);
    } else if ($disp[0] == 'P') {
        put_pend($disp[1]);
    } else {
        put_pay();
    }
}
fclose($fp_in);

fwrite($fp_pay, 'PT' . sprintf('%07d', $g_cnt_appr) . sprintf('%013d', $g_tot_pay) . sprintf('%013d', $g_tot_int) . "\n");
fwrite($fp_den, 'DT' . sprintf('%07d', $g_cnt_deny) . "\n");
fwrite($fp_pnd, 'NT' . sprintf('%07d', $g_cnt_pend) . "\n");
fclose($fp_pay);
fclose($fp_den);
fclose($fp_pnd);

$chk_tot = check_run($out_dir);
gl_extract($out_dir, $chk_tot);

/* ---- control totals ------------------------------------------------ */
if ($g_cnt_read != $g_cnt_appr + $g_cnt_deny + $g_cnt_pend) {
    echo "CLMADJ01 - OUT OF BALANCE: RECORDS READ NOT EQUAL DISPOSITIONS\n";
    $g_rc = 8;
}
$dbpay = (int) db_val("SELECT IFNULL(SUM(pay),0) FROM claim WHERE run_ind = 'C' AND status = 'A'");
if ($dbpay != $g_tot_pay) {
    echo "CLMADJ01 - OUT OF BALANCE: PAYMENT FILE " . $g_tot_pay . " CLAIM TABLE " . $dbpay . "\n";
    $g_rc = 8;
}
if ($g_rc == 0 && $g_cnt_deny + $g_cnt_pend > 0) {
    $g_rc = 4;
}
fwrite($fp_aud, "CLMADJ01 END READ=" . sprintf('%07d', $g_cnt_read) . " APPROVED=" . sprintf('%07d', $g_cnt_appr)
    . " DENIED=" . sprintf('%07d', $g_cnt_deny) . " PENDED=" . sprintf('%07d', $g_cnt_pend) . " RC=" . $g_rc . "\n");
fclose($fp_aud);

echo "CLMADJ01 - RECORDS READ     " . sprintf('%7d', $g_cnt_read) . "\n";
echo "CLMADJ01 - LINES APPROVED   " . sprintf('%7d', $g_cnt_appr) . "\n";
echo "CLMADJ01 - LINES DENIED     " . sprintf('%7d', $g_cnt_deny) . "\n";
echo "CLMADJ01 - LINES PENDED     " . sprintf('%7d', $g_cnt_pend) . "\n";
echo "CLMADJ01 - ENDED RC=" . $g_rc . "\n";
exit($g_rc);

/* ==================================================================== */
/* load_ref - reference tables into memory.  The first version read    */
/* them per claim and the nightly ran past 6am.                        */
/* ==================================================================== */
function load_ref()
{
    global $g_plans, $g_bnfts, $g_procs, $g_diags, $g_provs;

    $res = db_q("SELECT * FROM plan");
    while ($row = mysqli_fetch_assoc($res)) {
        $g_plans[$row['plan_id']] = $row;
    }
    $res = db_q("SELECT * FROM bnft");
    while ($row = mysqli_fetch_assoc($res)) {
        $g_bnfts[$row['plan_id'] . $row['svc_class']] = $row;
    }
    $res = db_q("SELECT * FROM proccode");
    while ($row = mysqli_fetch_assoc($res)) {
        $g_procs[$row['proc_code']] = $row;
    }
    $res = db_q("SELECT diag_code FROM diagcode");
    while ($row = mysqli_fetch_assoc($res)) {
        $g_diags[$row['diag_code']] = 1;
    }
    $res = db_q("SELECT prov_id, net_stat, cntr_pct, status, IFNULL(DATE_FORMAT(stat_date,'%Y%m%d'),'00000000') AS stat_date, "
        . "DATE_FORMAT(lic_exp,'%Y%m%d') AS lic_exp FROM provider");
    while ($row = mysqli_fetch_assoc($res)) {
        $g_provs[$row['prov_id']] = $row;
    }
}

/* ==================================================================== */
/* get_rec - cut the 120 byte record into $g_rec                       */
/*   1-2 type  3-14 claim  15-16 line  17-25 member  26-32 provider    */
/*   33-40 service date  41-48 received date  49-53 procedure          */
/*   54-58 diagnosis  59-61 units  62-70 billed  71 submit type        */
/*   72-83 original claim  84-93 pre-auth  94 emergency  95 cob        */
/*   96-104 cob paid  105 source  106-120 filler                       */
/* ==================================================================== */
function get_rec($line)
{
    global $g_rec;
    $l = str_pad($line, C_RECLEN);
    $g_rec = array();
    $g_rec['type']    = substr($l, 0, 2);
    $g_rec['claim']   = substr($l, 2, 12);
    $g_rec['line']    = substr($l, 14, 2);
    $g_rec['member']  = substr($l, 16, 9);
    $g_rec['prov']    = substr($l, 25, 7);
    $g_rec['svc']     = substr($l, 32, 8);
    $g_rec['rcv']     = substr($l, 40, 8);
    $g_rec['proc']    = substr($l, 48, 5);
    $g_rec['diag']    = substr($l, 53, 5);
    $g_rec['units']   = substr($l, 58, 3);
    $g_rec['billed']  = substr($l, 61, 9);
    $g_rec['subtype'] = substr($l, 70, 1);
    $g_rec['orig']    = substr($l, 71, 12);
    $g_rec['auth']    = substr($l, 83, 10);
    $g_rec['emerg']   = substr($l, 93, 1);
    $g_rec['cob']     = substr($l, 94, 1);
    $g_rec['cobpaid'] = substr($l, 95, 9);
    $g_rec['source']  = substr($l, 104, 1);
}

/* ==================================================================== */
/* edit_rec - record format edits.  Returns the reason code of the     */
/* first edit that fails or '' when the record is good.  The same      */
/* edits are in web/claimentry.php - CHANGE BOTH.                      */
/* ==================================================================== */
function edit_rec($line)
{
    global $g_rec, $g_busdate;

    if (strlen($line) != C_RECLEN) {
        return '101';
    }
    if ($g_rec['type'] != 'CL') {
        return '101';
    }
    if (trim($g_rec['claim']) == '' || strpos(rtrim($g_rec['claim']), ' ') !== false) {
        return '101';
    }
    if (!ctype_digit($g_rec['line'])) {
        return '103';
    }
    if ((int) $g_rec['line'] < 1 || (int) $g_rec['line'] > C_MAXLINE) {
        return '103';
    }
    if (!ctype_digit($g_rec['units']) || !ctype_digit($g_rec['billed']) || !ctype_digit($g_rec['cobpaid'])) {
        return '104';
    }
    if (!dt_ok($g_rec['svc'])) {
        return '105';
    }
    if (!dt_ok($g_rec['rcv'])) {
        return '106';
    }
    if (strcmp($g_rec['svc'], $g_busdate) > 0) {
        return '107';
    }
    if (strcmp($g_rec['rcv'], $g_rec['svc']) < 0 || strcmp($g_rec['rcv'], $g_busdate) > 0) {
        return '108';
    }
    $g_rec['line']    = (int) $g_rec['line'];
    $g_rec['units']   = (int) $g_rec['units'];
    $g_rec['billed']  = (int) $g_rec['billed'];
    $g_rec['cobpaid'] = (int) $g_rec['cobpaid'];
    if ($g_rec['billed'] == 0) {
        return '109';
    }
    return '';
}

/* ==================================================================== */
/* adjud - adjudicate one claim line.                                  */
/* Returns array(disposition, reason): A approved, D denied, P pended. */
/* The edits run in the order below and the FIRST one that fails       */
/* decides the reason code.  Order was agreed with Claims Operations,  */
/* do not move edits about without a change request.                   */
/* ==================================================================== */
function adjud()
{
    global $g_rec, $g_mbr, $g_prv, $g_prc, $g_pln, $g_bn, $g_emg, $g_resub, $g_grace, $g_bnused, $g_w;
    global $g_plans, $g_bnfts, $g_procs, $g_diags, $g_provs, $g_dxok, $g_tfext, $g_busdate;

    $g_mbr = 0;
    $g_prv = 0;
    $g_prc = 0;
    $g_pln = 0;
    $g_bn  = 0;
    $g_resub  = 0;
    $g_grace  = 0;
    $g_bnused = 0;
    $g_w = array('alw' => 0, 'cop' => 0, 'ded' => 0, 'coi' => 0, 'ncv' => 0, 'pay' => 0, 'int' => 0, 'prs' => 0, 'oopc' => 0);
    $g_emg = 0;
    if ($g_rec['emerg'] == 'Y') {
        $g_emg = 1;
    }
    $svc = $g_rec['svc'];

    /* ---------------------------------------------------- member ---- */
    if (!ctype_digit($g_rec['member'])) {
        return array('D', '201');
    }
    $g_mbr = db_row("SELECT * FROM v_elig WHERE member_id = '" . addslashes($g_rec['member']) . "'");
    if (!$g_mbr) {
        return array('D', '201');
    }
    if (strcmp($svc, $g_mbr['eff_date']) < 0) {
        return array('D', '202');
    }
    if (strcmp($svc, $g_mbr['cov_thru']) > 0) {
        return array('D', '203');
    }
    if (strcmp($svc, $g_mbr['term_date']) > 0) {
        $g_grace = 1;
    }
    /* dependent children: to the end of the month they turn 19,
       students to the day before the 24th birthday, disabled no limit */
    if ($g_mbr['rel'] == 'C') {
        if ($g_mbr['stud_flag'] == 'D') {
            /* no limit */
        } else if ($g_mbr['stud_flag'] == 'Y') {
            if (dt_age($g_mbr['dob'], $svc) >= C_STUD_AGE) {
                return array('D', '204');
            }
        } else {
            $lim = sprintf('%04d', (int) substr($g_mbr['dob'], 0, 4) + C_DEP_AGE) . substr($g_mbr['dob'], 4, 2);
            if (strcmp(substr($svc, 0, 6), $lim) > 0) {
                return array('D', '204');
            }
        }
    }

    /* -------------------------------------------------- provider ---- */
    if (!isset($g_provs[$g_rec['prov']])) {
        return array('D', '301');
    }
    $g_prv = $g_provs[$g_rec['prov']];
    switch ($g_prv['status']) {
        case 'X':
            if (strcmp($svc, $g_prv['stat_date']) >= 0) {
                return array('D', '302');
            }
            /* sanction not in force on the date of service - drop through */
        case 'S':
            /* suspended providers are held further down, after the edits */
        default:
            if (strcmp($g_prv['lic_exp'], $svc) < 0) {
                return array('D', '303');
            }
    }

    /* --------------------------------------------- code validity ---- */
    if (!isset($g_procs[$g_rec['proc']])) {
        return array('D', '601');
    }
    $g_prc = $g_procs[$g_rec['proc']];
    if (!isset($g_diags[$g_rec['diag']])) {
        return array('D', '602');
    }

    /* ------------------------------------------------- duplicate ---- */
    /* same member, provider, procedure and date already on file.
       An approved or pended line is always a duplicate.  A denied line
       may be sent again only as a corrected claim (type R) that quotes
       the denied claim number.                         CR-1012 CR-2610 */
    $res = db_q("SELECT claim_no, status FROM v_dupchk WHERE member_id = '" . $g_rec['member']
        . "' AND svc_date = '" . $svc . "' AND proc_code = '" . addslashes($g_rec['proc'])
        . "' AND prov_id = '" . addslashes($g_rec['prov']) . "'");
    $n_live = 0;
    $n_den  = 0;
    $n_orig = 0;
    $orig = rtrim($g_rec['orig']);
    while ($row = mysqli_fetch_assoc($res)) {
        if ($row['status'] == 'D') {
            $n_den++;
            if (strcmp($row['claim_no'], $orig) == 0) {
                $n_orig++;
            }
        } else {
            $n_live++;
        }
    }
    mysqli_free_result($res);
    if ($n_live > 0) {
        return array('D', '501');
    }
    if ($g_rec['subtype'] == 'R') {
        if ($n_orig == 0) {
            return array('D', '503');
        }
        $g_resub = 1;
        audit('INFO     CORRECTED CLAIM ACCEPTED ORIG=' . $orig);
    } else {
        if ($n_den > 0) {
            return array('D', '502');
        }
    }

    /* --------------------------------------------- timely filing ---- */
    /* corrected claims are not timed again                    CR-2610 */
    if (!$g_resub) {
        $lim = C_TF_OON;
        if ($g_prv['net_stat'] == 'I') {
            $lim = C_TF_INN;
        }
        $ext = 0;
        for ($i = 0; $i < count($g_tfext); $i++) {              /* CR-0561 */
            if (strcmp($svc, $g_tfext[$i][0]) >= 0 && strcmp($svc, $g_tfext[$i][1]) <= 0) {
                $ext = 1;
            }
        }
        if (!$ext && dt_days($g_rec['rcv']) - dt_days($svc) > $lim) {
            return array('D', '401');
        }
    }

    /* -------------------------------------------- clinical edits ---- */
    $age = dt_age($g_mbr['dob'], $svc);
    if ($g_prc['sex_only'] != '' && $g_prc['sex_only'] != $g_mbr['sex']) {
        return array('D', '603');
    }
    if ($age < (int) $g_prc['min_age'] || $age > (int) $g_prc['max_age']) {
        return array('D', '604');
    }
    $cls = $g_prc['svc_class'];
    if (isset($g_dxok[$cls])) {
        if (strpos($g_dxok[$cls], substr($g_rec['diag'], 0, 1)) === false) {
            return array('D', '605');
        }
    }

    /* -------------------------------------------------- coverage ---- */
    if (!isset($g_plans[$g_mbr['plan_id']]) || !isset($g_bnfts[$g_mbr['plan_id'] . $cls])) {
        return array('D', '607');
    }
    $g_pln = $g_plans[$g_mbr['plan_id']];
    $g_bn = $g_bnfts[$g_mbr['plan_id'] . $cls];
    if ($g_bn['covered'] != 'Y') {
        return array('D', '607');
    }
    if ($g_prv['net_stat'] != 'I' && (int) $g_bn['oon_pct'] == 0 && !$g_emg) {
        return array('D', '608');
    }
    /* waiting period from the member effective date; groups of 50
       lives and over are exempt                               CR-2302 */
    if ((int) $g_bn['wait_days'] > 0 && (int) $g_mbr['grp_lives'] < C_GRP_LARGE) {
        if (dt_days($svc) - dt_days($g_mbr['eff_date']) < (int) $g_bn['wait_days']) {
            return array('D', '701');
        }
    }

    /* ----------------------------------------- pre-authorisation ---- */
    if (($g_prc['preauth'] == 'Y' || $g_rec['billed'] > C_PA_AMT) && !$g_emg) {
        $auth = trim($g_rec['auth']);
        if ($auth == '') {
            return array('D', '702');
        }
        $pa = db_row("SELECT member_id, proc_code, status, DATE_FORMAT(from_date,'%Y%m%d') AS f, "
            . "DATE_FORMAT(to_date,'%Y%m%d') AS t FROM preauth WHERE auth_no = '" . addslashes($auth) . "'");
        if (!$pa) {
            return array('D', '703');
        }
        if ($pa['member_id'] != $g_rec['member'] || $pa['proc_code'] != $g_rec['proc'] || $pa['status'] != 'A') {
            return array('D', '703');
        }
        if (strcmp($svc, $pa['f']) < 0 || strcmp($svc, $pa['t']) > 0) {
            return array('D', '703');
        }
    }

    /* ------------------------------------------- other insurance ---- */
    if ($g_mbr['other_ins'] == 'Y' && $g_rec['cob'] != 'Y') {
        return array('D', '801');
    }

    /* ------------------------------------------ benefit maximums ---- */
    if ((int) $g_bn['ann_max'] > 0) {
        $g_bnused = (int) db_val("SELECT amt_used FROM bnaccum WHERE member_id = '" . $g_rec['member']
            . "' AND plan_yr = " . substr($svc, 0, 4) . " AND svc_class = '" . $cls . "'");
        if ($g_bnused >= (int) $g_bn['ann_max']) {
            return array('D', '704');
        }
    }
    if ((int) $g_mbr['life_used'] >= (int) $g_pln['life_max']) {
        return array('D', '705');
    }

    /* ------------------------------------------------------ pends --- */
    if ($g_prv['status'] == 'S') {
        return array('P', '901');
    }
    if ($g_grace) {
        return array('P', '903');
    }

    /* ---------------------------------------------------- pricing --- */
    price();
    if ($g_w['pay'] > C_HIDOLLAR) {                            /* CR-2788 */
        return array('P', '902');
    }

    /* prompt pay interest: 12% a year on the payment for every day
       past 30 from the received date to the run date         CR-2190 */
    $late = dt_days($g_busdate) - dt_days($g_rec['rcv']) - C_INT_GRACE;
    if ($late > 0 && $g_w['pay'] > 0) {
        $g_w['int'] = rhu($g_w['pay'] * C_INT_RATE * $late, 36500);
        if ($g_w['int'] < C_INT_MIN) {
            $g_w['int'] = 0;
        }
    }
    return array('A', '');
}

/* ==================================================================== */
/* price - work out allowed amount, member share and payment for the   */
/* line in $g_rec.  Results in $g_w (all cents).                       */
/*   alw allowed   cop copay   ded deductible   coi coinsurance        */
/*   ncv over benefit maximum   pay plan payment   prs patient owes    */
/*   oopc amount that counts to the out-of-pocket maximum              */
/* ==================================================================== */
function price()
{
    global $g_rec, $g_mbr, $g_prv, $g_prc, $g_pln, $g_bn, $g_emg, $g_bnused, $g_w;

    $bil = $g_rec['billed'];
    $yr  = substr($g_rec['svc'], 0, 4);
    $fam = 0;
    if ($g_mbr['cov_tier'] == 'F') {
        $fam = 1;
    }

    /* allowed amount.  Network: fee schedule at the contract
       percentage.  Non network: usual and customary = fee schedule at
       the plan percentage.  Never more than billed. */
    $sched = (int) $g_prc['fee'] * $g_rec['units'];
    if ($g_prv['net_stat'] == 'I') {
        $alw = rhu($sched * (int) $g_prv['cntr_pct'], 100);
        $pct = (int) $g_bn['inn_pct'];
        $bal = 0;
    } else {
        $alw = rhu($sched * (int) $g_pln['ucr_pct'], 100);
        $pct = (int) $g_bn['oon_pct'];
        if ($g_emg) {
            $pct = (int) $g_bn['inn_pct'];                     /* CR-1688 */
        }
        $bal = 1;
    }
    if ($alw > $bil) {
        $alw = $bil;
    }

    /* accumulators for the plan year of the service date */
    $acc = db_row("SELECT ded_met, oop_met FROM accum WHERE member_id = '" . $g_rec['member'] . "' AND plan_yr = " . $yr);
    if (!$acc) {
        $acc = array('ded_met' => 0, 'oop_met' => 0);
    }
    $fac = array('ded_met' => 0, 'oop_met' => 0);
    if ($fam) {
        $fac = db_row("SELECT ded_met, oop_met FROM famaccum WHERE sub_id = '" . $g_mbr['sub_id'] . "' AND plan_yr = " . $yr);
        if (!$fac) {
            $fac = array('ded_met' => 0, 'oop_met' => 0);
        }
    }

    /* deductible still to meet */
    $ded_rem = 0;
    if ($g_bn['ded_appl'] == 'Y') {
        if ($fam && $g_pln['ded_type'] == 'A') {
            /* aggregate: the family amount only               CR-1851 */
            $ded_rem = (int) $g_pln['fam_ded'] - (int) $fac['ded_met'];
        } else {
            $ded_rem = (int) $g_pln['ind_ded'] - (int) $acc['ded_met'];
            if ($fam) {
                $x = (int) $g_pln['fam_ded'] - (int) $fac['ded_met'];
                if ($x < $ded_rem) {
                    $ded_rem = $x;
                }
            }
        }
        if ($ded_rem < 0) {
            $ded_rem = 0;
        }
    }

    /* out-of-pocket still to meet */
    $oop_rem = (int) $g_pln['ind_oop'] - (int) $acc['oop_met'];
    if ($fam) {
        $x = (int) $g_pln['fam_oop'] - (int) $fac['oop_met'];
        if ($x < $oop_rem) {
            $oop_rem = $x;
        }
    }
    if ($oop_rem < 0) {
        $oop_rem = 0;
    }
    $oop_start = $oop_rem;

    /* copay: once per visit (member, provider, date) */
    $cop = 0;
    if ((int) $g_bn['copay'] > 0) {
        $n = (int) db_val("SELECT COUNT(*) FROM claim WHERE member_id = '" . $g_rec['member'] . "' AND svc_date = '"
            . $g_rec['svc'] . "' AND prov_id = '" . $g_rec['prov'] . "' AND status = 'A' AND copay > 0");
        if ($n == 0) {
            $cop = (int) $g_bn['copay'];
            if ($cop > $alw) {
                $cop = $alw;
            }
        }
    }
    $oopc = 0;
    if ($cop > 0 && $g_pln['copay_oop'] == 'Y') {              /* CR-2871 */
        if ($cop > $oop_rem) {
            $cop = $oop_rem;
        }
        $oop_rem = $oop_rem - $cop;
        $oopc = $oopc + $cop;
    }
    $rem = $alw - $cop;

    /* deductible */
    $ded = $ded_rem;
    if ($ded > $rem) {
        $ded = $rem;
    }
    if ($ded > $oop_rem) {
        $ded = $oop_rem;
    }
    $oop_rem = $oop_rem - $ded;
    $oopc = $oopc + $ded;
    $rem = $rem - $ded;

    /* coinsurance: member share, fractions of a cent dropped in the
       member's favour */
    $x = $rem * (100 - $pct);
    $coi = ($x - ($x % 100)) / 100;
    if ($coi > $oop_rem) {
        $coi = $oop_rem;
    }
    $oopc = $oopc + $coi;
    $pay = $rem - $coi;
    if ($oop_start > 0 && $oopc >= $oop_start) {
        audit('INFO     OUT-OF-POCKET MAXIMUM REACHED');
    }

    /* benefit maximums: pay only what is left                 CR-2044 */
    $ncv = 0;
    if ((int) $g_bn['ann_max'] > 0) {
        $avl = (int) $g_bn['ann_max'] - $g_bnused;
        if ($pay > $avl) {
            $ncv = $ncv + ($pay - $avl);
            $pay = $avl;
            audit('INFO     ANNUAL MAXIMUM CUTBACK');
        }
    }
    $avl = (int) $g_pln['life_max'] - (int) $g_mbr['life_used'];
    if ($pay > $avl) {
        $ncv = $ncv + ($pay - $avl);
        $pay = $avl;
        audit('INFO     LIFETIME MAXIMUM CUTBACK');
    }

    /* other insurance paid first: we never pay more than what is left
       of our allowed amount */
    $cobp = 0;
    if ($g_rec['cob'] == 'Y') {
        $cobp = $g_rec['cobpaid'];
        $cap = $alw - $cobp;
        if ($cap < 0) {
            $cap = 0;
        }
        if ($pay > $cap) {
            $pay = $cap;
        }
    }

    /* what the patient still owes */
    $prs = $cop + $ded + $coi + $ncv - $cobp;
    if ($bal) {
        $prs = $prs + ($bil - $alw);
    }
    if ($prs < 0) {
        $prs = 0;
    }

    $g_w['alw'] = $alw;
    $g_w['cop'] = $cop;
    $g_w['ded'] = $ded;
    $g_w['coi'] = $coi;
    $g_w['ncv'] = $ncv;
    $g_w['pay'] = $pay;
    $g_w['prs'] = $prs;
    $g_w['oopc'] = $oopc;
}

/* ==================================================================== */
/* post_claim - write the line to the claim table and, when approved,  */
/* post the accumulators.  Pended and denied lines post nothing.       */
/* ==================================================================== */
function post_claim($disp, $code)
{
    global $g_rec, $g_mbr, $g_prc, $g_bn, $g_w;

    $w = $g_w;
    if ($disp != 'A') {
        $est = $w['pay'];
        $w = array('alw' => 0, 'cop' => 0, 'ded' => 0, 'coi' => 0, 'ncv' => 0, 'pay' => 0, 'int' => 0, 'prs' => 0, 'oopc' => 0);
        $g_w['est'] = $est;
    }
    db_q("INSERT INTO claim (claim_no, line_no, member_id, prov_id, svc_date, rcv_date, proc_code, diag_code, "
        . "units, billed, allowed, copay, ded, coins, pay, int_amt, status, reason, run_ind) VALUES ('"
        . addslashes($g_rec['claim']) . "', " . $g_rec['line'] . ", '" . addslashes($g_rec['member']) . "', '"
        . addslashes($g_rec['prov']) . "', '" . $g_rec['svc'] . "', '" . $g_rec['rcv'] . "', '"
        . addslashes($g_rec['proc']) . "', '" . addslashes($g_rec['diag']) . "', " . $g_rec['units'] . ", "
        . $g_rec['billed'] . ", " . $w['alw'] . ", " . $w['cop'] . ", " . $w['ded'] . ", " . $w['coi'] . ", "
        . $w['pay'] . ", " . $w['int'] . ", '" . $disp . "', '" . $code . "', 'C')");
    if ($disp != 'A') {
        return;
    }

    $yr = substr($g_rec['svc'], 0, 4);
    db_q("INSERT INTO accum (member_id, plan_yr, ded_met, oop_met) VALUES ('" . $g_rec['member'] . "', " . $yr . ", "
        . $w['ded'] . ", " . $w['oopc'] . ") ON DUPLICATE KEY UPDATE ded_met = ded_met + " . $w['ded']
        . ", oop_met = oop_met + " . $w['oopc']);
    if ($g_mbr['cov_tier'] == 'F') {
        db_q("INSERT INTO famaccum (sub_id, plan_yr, ded_met, oop_met) VALUES ('" . $g_mbr['sub_id'] . "', " . $yr . ", "
            . $w['ded'] . ", " . $w['oopc'] . ") ON DUPLICATE KEY UPDATE ded_met = ded_met + " . $w['ded']
            . ", oop_met = oop_met + " . $w['oopc']);
    }
    if ((int) $g_bn['ann_max'] > 0) {
        db_q("INSERT INTO bnaccum (member_id, plan_yr, svc_class, amt_used) VALUES ('" . $g_rec['member'] . "', " . $yr
            . ", '" . $g_prc['svc_class'] . "', " . $w['pay'] . ") ON DUPLICATE KEY UPDATE amt_used = amt_used + " . $w['pay']);
    }
    if ($w['pay'] > 0) {
        db_q("UPDATE member SET life_used = life_used + " . $w['pay'] . " WHERE member_id = '" . $g_rec['member'] . "'");
    }
}

/* ==================================================================== */
/* output records                                                       */
/* ==================================================================== */
function rec_key()
{
    global $g_rec, $g_seq;
    $ln = $g_rec['line'];
    if (is_int($ln)) {
        $ln = sprintf('%02d', $ln);
    }
    return sprintf('%07d', $g_seq) . $g_rec['claim'] . $ln . $g_rec['member'] . $g_rec['prov'] . $g_rec['svc'] . $g_rec['proc'];
}

function put_deny($code)
{
    global $g_rec, $g_rsn, $g_cnt_deny, $fp_den;
    $bil = $g_rec['billed'];
    if (is_int($bil)) {
        $bil = sprintf('%09d', $bil);
    }
    fwrite($fp_den, 'DN' . rec_key() . $bil . $code . str_pad($g_rsn[$code], 50) . "\n");
    $g_cnt_deny++;
    audit('DENIED   ' . $code . ' ' . $g_rsn[$code]);
}

function put_pend($code)
{
    global $g_rec, $g_rsn, $g_w, $g_cnt_pend, $fp_pnd;
    fwrite($fp_pnd, 'PN' . rec_key() . sprintf('%09d', $g_rec['billed']) . $code . str_pad($g_rsn[$code], 50)
        . sprintf('%09d', $g_w['est']) . "\n");
    $g_cnt_pend++;
    audit('PENDED   ' . $code . ' ' . $g_rsn[$code]);
}

function put_pay()
{
    global $g_rec, $g_prv, $g_w, $g_cnt_appr, $g_tot_pay, $g_tot_int, $fp_pay;
    fwrite($fp_pay, 'PD' . rec_key() . sprintf('%03d', $g_rec['units']) . $g_prv['net_stat']
        . sprintf('%09d', $g_rec['billed']) . sprintf('%09d', $g_w['alw']) . sprintf('%09d', $g_w['cop'])
        . sprintf('%09d', $g_w['ded']) . sprintf('%09d', $g_w['coi']) . sprintf('%09d', $g_w['ncv'])
        . sprintf('%09d', $g_w['pay']) . sprintf('%07d', $g_w['int']) . sprintf('%09d', $g_w['prs']) . "\n");
    $g_cnt_appr++;
    $g_tot_pay = $g_tot_pay + $g_w['pay'];
    $g_tot_int = $g_tot_int + $g_w['int'];
    audit('APPROVED PAY=' . sprintf('%09d', $g_w['pay']) . ' INT=' . sprintf('%07d', $g_w['int']));
}

function audit($txt)
{
    global $g_rec, $g_seq, $fp_aud;
    $ln = $g_rec['line'];
    if (is_int($ln)) {
        $ln = sprintf('%02d', $ln);
    }
    fwrite($fp_aud, sprintf('%07d', $g_seq) . ' ' . $g_rec['claim'] . ' ' . $ln . ' ' . $txt . "\n");
}

/* ==================================================================== */
/* check_run - one cheque per provider for the lines approved in this  */
/* run.  The provider's carried balance (held small amounts, or money  */
/* the provider owes us) is netted in.  Anything under the minimum is  */
/* carried to the next run and no cheque is cut.           CR-3127     */
/* Returns array(total cheques, change in held balance).               */
/* ==================================================================== */
function check_run($out_dir)
{
    global $fp_aud;

    $fp = @fopen($out_dir . '/CHECKS.DAT', 'w');
    if (!$fp) {
        echo "CLMADJ01 - CANNOT OPEN CHECKS.DAT\n";
        echo "CLMADJ01 - ENDED RC=16\n";
        exit(16);
    }
    $chkno = (int) db_val("SELECT ctl_val FROM sysctl WHERE ctl_key = 'NEXT_CHECK_NO'");
    $n_iss = 0;
    $n_car = 0;
    $t_iss = 0;
    $t_held = 0;

    $res = db_q("SELECT c.prov_id, COUNT(*) AS n, SUM(c.pay) AS gross, SUM(c.int_amt) AS intr, p.carry_bal "
        . "FROM claim c, provider p WHERE p.prov_id = c.prov_id AND c.run_ind = 'C' AND c.status = 'A' "
        . "GROUP BY c.prov_id, p.carry_bal ORDER BY c.prov_id");
    $rows = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_free_result($res);

    for ($i = 0; $i < count($rows); $i++) {
        $prov  = $rows[$i]['prov_id'];
        $gross = (int) $rows[$i]['gross'];
        $intr  = (int) $rows[$i]['intr'];
        $cin   = (int) $rows[$i]['carry_bal'];
        $net   = $gross + $intr + $cin;
        if ($net >= C_MIN_CHECK) {
            $no   = $chkno;
            $chkno++;
            $cout = 0;
            $st   = 'I';
            $n_iss++;
            $t_iss = $t_iss + $net;
            fwrite($fp_aud, 'CHEQUE   ' . sprintf('%07d', $no) . ' ' . $prov . ' AMT=' . sprintf('%011d', $net) . "\n");
        } else {
            $no   = 0;
            $cout = $net;
            $st   = 'C';
            $n_car++;
            fwrite($fp_aud, 'CARRIED  ' . $prov . ' BAL=' . fmt_sign($cout, 9) . "\n");
        }
        $t_held = $t_held + ($cout - $cin);
        if ($cout != $cin) {
            db_q("UPDATE provider SET carry_bal = " . $cout . " WHERE prov_id = '" . $prov . "'");
        }
        fwrite($fp, 'CK' . $prov . sprintf('%07d', $no) . sprintf('%05d', $rows[$i]['n']) . sprintf('%011d', $gross)
            . sprintf('%09d', $intr) . fmt_sign($cin, 9) . fmt_sign($net, 11) . fmt_sign($cout, 9) . $st . "\n");
    }
    db_q("UPDATE sysctl SET ctl_val = '" . $chkno . "' WHERE ctl_key = 'NEXT_CHECK_NO'");
    fwrite($fp, 'CT' . sprintf('%05d', $n_iss) . sprintf('%05d', $n_car) . sprintf('%013d', $t_iss) . "\n");
    fclose($fp);
    echo "CLMADJ01 - CHEQUES ISSUED   " . sprintf('%7d', $n_iss) . "\n";
    echo "CLMADJ01 - PROVIDERS CARRIED" . sprintf('%7d', $n_car) . "\n";
    return array($t_iss, $t_held);
}

/* ==================================================================== */
/* gl_extract - ledger postings for Finance.  Expense by service class */
/* group and interest are debits; cheques and the change in the held   */
/* balance are credits (a fall in the held balance posts as a debit).  */
/* Debits must equal credits or the run is flagged RC=8.               */
/* ==================================================================== */
function gl_extract($out_dir, $chk)
{
    global $g_glacct, $g_glname, $g_busdate, $g_tot_int, $g_rc;

    $fp = @fopen($out_dir . '/GLEXTRACT.DAT', 'w');
    if (!$fp) {
        echo "CLMADJ01 - CANNOT OPEN GLEXTRACT.DAT\n";
        echo "CLMADJ01 - ENDED RC=16\n";
        exit(16);
    }
    $exp = array('5100' => 0, '5110' => 0, '5120' => 0, '5200' => 0, '5300' => 0);
    $res = db_q("SELECT p.svc_class, SUM(c.pay) AS amt FROM claim c, proccode p WHERE p.proc_code = c.proc_code "
        . "AND c.run_ind = 'C' AND c.status = 'A' GROUP BY p.svc_class ORDER BY p.svc_class");
    while ($row = mysqli_fetch_assoc($res)) {
        $acct = '5100';
        if (isset($g_glacct[$row['svc_class']])) {
            $acct = $g_glacct[$row['svc_class']];
        }
        $exp[$acct] = $exp[$acct] + (int) $row['amt'];
    }
    mysqli_free_result($res);

    $n = 0;
    $dr = 0;
    $cr = 0;
    foreach ($exp as $acct => $amt) {
        if ($amt != 0) {
            fwrite($fp, 'GL' . $g_busdate . $acct . 'D' . sprintf('%013d', $amt) . str_pad($g_glname[$acct], 30) . "\n");
            $n++;
            $dr = $dr + $amt;
        }
    }
    if ($g_tot_int != 0) {
        fwrite($fp, 'GL' . $g_busdate . '5900' . 'D' . sprintf('%013d', $g_tot_int) . str_pad($g_glname['5900'], 30) . "\n");
        $n++;
        $dr = $dr + $g_tot_int;
    }
    if ($chk[0] != 0) {
        fwrite($fp, 'GL' . $g_busdate . '2100' . 'C' . sprintf('%013d', $chk[0]) . str_pad($g_glname['2100'], 30) . "\n");
        $n++;
        $cr = $cr + $chk[0];
    }
    if ($chk[1] > 0) {
        fwrite($fp, 'GL' . $g_busdate . '2150' . 'C' . sprintf('%013d', $chk[1]) . str_pad($g_glname['2150'], 30) . "\n");
        $n++;
        $cr = $cr + $chk[1];
    } else if ($chk[1] < 0) {
        fwrite($fp, 'GL' . $g_busdate . '2150' . 'D' . sprintf('%013d', -$chk[1]) . str_pad($g_glname['2150'], 30) . "\n");
        $n++;
        $dr = $dr + (-$chk[1]);
    }
    fwrite($fp, 'GT' . sprintf('%03d', $n) . sprintf('%013d', $dr) . sprintf('%013d', $cr) . "\n");
    fclose($fp);
    if ($dr != $cr) {
        echo "CLMADJ01 - OUT OF BALANCE: LEDGER DEBITS " . $dr . " CREDITS " . $cr . "\n";
        $g_rc = 8;
    }
}
