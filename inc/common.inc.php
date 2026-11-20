<?php
/* ------------------------------------------------------------------
 * CLAIMSYS - common.inc.php : shared routines (db, dates, money)
 *
 * 2004-03 rjk  created
 * 2004-11 rjk  dt_days rewritten, mktime was an hour out in October
 * 2005-09 rjk  hurricane filing extension table           CR-0561
 * 2008-02 tmw  rhu() - no more float rounding on money     CR-1245
 * 2010-06 dps  business date from control card             CR-1730
 * 2015-05 akl  denial text table moved here from 4 pages   CR-2610
 * 2023-04 ops  mysql_* -> mysqli_*                         CR-3310
 * ------------------------------------------------------------------ */

/* denial / pend reason texts.  Printed on DENIALS.DAT - do not reword
   without telling Provider Relations, the letters quote them. */
$g_rsn = array(
    '101' => 'INVALID RECORD FORMAT',
    '103' => 'INVALID CLAIM LINE NUMBER',
    '104' => 'NON-NUMERIC UNITS OR AMOUNT',
    '105' => 'INVALID DATE OF SERVICE',
    '106' => 'INVALID RECEIVED DATE',
    '107' => 'DATE OF SERVICE AFTER RUN DATE',
    '108' => 'RECEIVED DATE OUT OF SEQUENCE',
    '109' => 'ZERO BILLED AMOUNT',
    '201' => 'MEMBER NOT ON FILE',
    '202' => 'COVERAGE NOT IN EFFECT ON DATE OF SERVICE',
    '203' => 'COVERAGE TERMINATED',
    '204' => 'DEPENDENT OVER AGE LIMIT',
    '301' => 'PROVIDER NOT ON FILE',
    '302' => 'PROVIDER SANCTIONED',
    '303' => 'PROVIDER LICENCE EXPIRED',
    '401' => 'TIMELY FILING LIMIT EXCEEDED',
    '501' => 'DUPLICATE CLAIM',
    '502' => 'PREVIOUSLY DENIED - RESUBMIT AS CORRECTED CLAIM',
    '503' => 'ORIGINAL CLAIM NOT FOUND',
    '601' => 'INVALID PROCEDURE CODE',
    '602' => 'INVALID DIAGNOSIS CODE',
    '603' => 'PROCEDURE INCONSISTENT WITH PATIENT SEX',
    '604' => 'PROCEDURE INCONSISTENT WITH PATIENT AGE',
    '605' => 'DIAGNOSIS INCONSISTENT WITH PROCEDURE',
    '607' => 'SERVICE NOT COVERED UNDER PLAN',
    '608' => 'OUT-OF-NETWORK SERVICE NOT COVERED',
    '701' => 'WAITING PERIOD NOT SATISFIED',
    '702' => 'PRE-AUTHORISATION REQUIRED',
    '703' => 'PRE-AUTHORISATION NOT VALID',
    '704' => 'ANNUAL BENEFIT MAXIMUM REACHED',
    '705' => 'LIFETIME MAXIMUM REACHED',
    '801' => 'OTHER INSURANCE INFORMATION REQUIRED',
    '901' => 'PROVIDER PAYMENT SUSPENDED - REVIEW',
    '902' => 'HIGH DOLLAR CLAIM REVIEW',
    '903' => 'PREMIUM GRACE PERIOD - PENDED'
);

/* which diagnosis categories (first letter) support a service class.
   Classes not listed take any diagnosis.  Medical Policy memo 2006-14. */
$g_dxok = array(
    'MT' => 'P',
    'DN' => 'D',
    'DM' => 'D',
    'VS' => 'V',
    'MH' => 'N',
    'PV' => 'W',
    'CH' => 'MI',
    'PT' => 'MI',
    'CS' => 'K'
);

/* ledger accounts by service class.  Finance chart of accounts 2007. */
$g_glacct = array(
    'OV' => '5100', 'SP' => '5100', 'PV' => '5100', 'LB' => '5100', 'RD' => '5100',
    'MT' => '5100', 'PT' => '5100', 'CH' => '5100', 'DE' => '5100',
    'ER' => '5110', 'IP' => '5110', 'OS' => '5110',
    'MH' => '5120',
    'DN' => '5200', 'DM' => '5200',
    'VS' => '5300'
);
$g_glname = array(
    '5100' => 'MEDICAL CLAIMS EXPENSE',
    '5110' => 'HOSPITAL CLAIMS EXPENSE',
    '5120' => 'MENTAL HEALTH CLAIMS EXPENSE',
    '5200' => 'DENTAL CLAIMS EXPENSE',
    '5300' => 'VISION CLAIMS EXPENSE',
    '5900' => 'INTEREST ON LATE CLAIMS',
    '2100' => 'CLAIMS PAYABLE - CHEQUES',
    '2150' => 'CLAIMS PAYABLE - HELD'
);

/* CR-0561 filing extension after the 2005 storms.  Nothing that old
   can come in now but Compliance asked us to leave the table. */
$g_tfext = array(
    array('20050825', '20051031')
);

/* ---------------------------------------------------------------- db */
function db_open()
{
    global $g_db, $g_dbhost, $g_dbuser, $g_dbpass, $g_dbname;
    $g_db = @mysqli_connect($g_dbhost, $g_dbuser, $g_dbpass, $g_dbname);
    if (!$g_db) {
        return 0;
    }
    mysqli_query($g_db, "SET NAMES latin1");
    return 1;
}

function db_q($sql)
{
    global $g_db;
    $res = mysqli_query($g_db, $sql);
    if (!$res) {
        echo "CLAIMSYS - SQL ERROR: " . mysqli_error($g_db) . "\n";
        echo "CLAIMSYS - " . $sql . "\n";
        echo "CLAIMSYS - ABEND RC=16\n";
        exit(16);
    }
    return $res;
}

/* one row or 0 */
function db_row($sql)
{
    $res = db_q($sql);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    if (!$row) {
        return 0;
    }
    return $row;
}

/* first column of first row, '' if none */
function db_val($sql)
{
    $res = db_q($sql);
    $row = mysqli_fetch_row($res);
    mysqli_free_result($res);
    if (!$row) {
        return '';
    }
    return $row[0];
}

/* ------------------------------------------------------------- dates */
/* all dates are 8 character YYYYMMDD strings */
function dt_ok($d)
{
    if (strlen($d) != 8 || !ctype_digit($d)) {
        return 0;
    }
    $y  = (int) substr($d, 0, 4);
    $m  = (int) substr($d, 4, 2);
    $dd = (int) substr($d, 6, 2);
    if ($y < 1900 || $m < 1 || $m > 12 || $dd < 1) {
        return 0;
    }
    $dim = array(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $x = $dim[$m];
    if ($m == 2 && $y % 4 == 0) {
        $x = 29;
    }
    if ($dd > $x) {
        return 0;
    }
    return 1;
}

/* serial day number, only ever used for differences */
function dt_days($d)
{
    $y  = (int) substr($d, 0, 4);
    $m  = (int) substr($d, 4, 2);
    $dd = (int) substr($d, 6, 2);
    if ($m <= 2) {
        $y  = $y - 1;
        $m  = $m + 12;
    }
    $n = 365 * $y + (int) floor($y / 4) - (int) floor($y / 100) + (int) floor($y / 400);
    $n = $n + (int) floor((153 * ($m - 3) + 2) / 5) + $dd;
    return $n;
}

/* completed years between birth date and $on */
function dt_age($dob, $on)
{
    $a = (int) substr($on, 0, 4) - (int) substr($dob, 0, 4);
    if (strcmp(substr($on, 4, 4), substr($dob, 4, 4)) < 0) {
        $a = $a - 1;
    }
    return $a;
}

function dt_show($d)
{
    if (strlen($d) != 8) {
        return $d;
    }
    return substr($d, 6, 2) . '/' . substr($d, 4, 2) . '/' . substr($d, 0, 4);
}

/* Y2K windowing for the old 6 digit tape dates.  The tape feed was
   switched off in 2009, kept in case Finance find more archive tapes. */
function dt_win6($d6)
{
    $yy = (int) substr($d6, 0, 2);
    if ($yy < 50) {
        return '20' . $d6;
    }
    return '19' . $d6;
}

/* business (run) date: BUSDATE variable, else control card, else today */
function get_busdate()
{
    global $g_ctlcard;
    $d = getenv('BUSDATE');
    if ($d == '') {
        if (file_exists($g_ctlcard)) {
            $fp = fopen($g_ctlcard, 'r');
            while (!feof($fp)) {
                $ln = trim((string) fgets($fp, 200));
                if (substr($ln, 0, 8) == 'BUSDATE=') {
                    $d = trim(substr($ln, 8));
                    break;
                }
            }
            fclose($fp);
        }
    }
    if ($d == '') {
        $d = date('Ymd');
    }
    return $d;
}

/* ------------------------------------------------------------- money */
/* $num / $den rounded half up, whole numbers only (cents).  CR-1245 */
function rhu($num, $den)
{
    $x = 2 * $num + $den;
    $y = 2 * $den;
    return ($x - ($x % $y)) / $y;
}

/* pre CR-1245 coinsurance.  Float, do not use - kept for the 2007
   re-pricing audit queries which still call it by name. */
function calc_coins_old($amt, $pct)
{
    return round($amt * (1 - $pct / 100), 2);
}

function fmt_money($c)
{
    $neg = '';
    if ($c < 0) {
        $neg = '-';
        $c = -$c;
    }
    $d = ($c - ($c % 100)) / 100;
    return $neg . number_format($d, 0, '.', ',') . '.' . sprintf('%02d', $c % 100);
}

/* signed fixed width number: sign then zero filled digits */
function fmt_sign($c, $w)
{
    if ($c < 0) {
        return '-' . sprintf('%0' . $w . 'd', -$c);
    }
    return '+' . sprintf('%0' . $w . 'd', $c);
}
