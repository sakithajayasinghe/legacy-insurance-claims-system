<?php
/* ------------------------------------------------------------------
 * CLAIMSYS - Tarrow Valley Mutual Health
 * config.inc.php : site settings, included by every page and batch.
 *
 * 2004-03 rjk  created
 * 2006-01 rjk  moved off the shared host, paths changed
 * 2011-07 dps  CLAIMSYS_HOME override for the test region   CR-1877
 * 2023-04 ops  PHP 8 / MariaDB move. mysqli_report OFF so the old
 *              "if (!$res) die()" checks keep working.      CR-3310
 * ------------------------------------------------------------------ */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

$g_home = getenv('CLAIMSYS_HOME');
if ($g_home == '') {
    $g_home = '/opt/claimsys';
}

/* database - same box, unix socket */
$g_dbhost = 'localhost';
$g_dbuser = 'clmbatch';
$g_dbpass = 'clm2004';
$g_dbname = 'claimsys';
$g_db     = 0;

/* files */
$g_ctlcard = $g_home . '/ctl/BUSDATE.CTL';
$g_spool   = $g_home . '/spool/KEYED.DAT';
$g_outroot = $g_home . '/output';

$g_company = 'TARROW VALLEY MUTUAL HEALTH';
$g_sysname = 'CLAIMSYS';
$g_sysver  = '4.7.2';

/* adjudication constants - see also the benefit tables */
define('C_RECLEN',      120);       /* claims file record length          */
define('C_MAXLINE',     12);        /* highest line number on a claim     */
define('C_TF_INN',      180);       /* timely filing days, network        */
define('C_TF_OON',      365);       /* timely filing days, non network    */
define('C_DEP_AGE',     19);        /* dependent child limit              */
define('C_STUD_AGE',    24);        /* student limit                      */
define('C_GRP_LARGE',   50);        /* large group, no waiting periods    */
define('C_PA_AMT',      500000);    /* billed above this needs pre-auth   */
define('C_HIDOLLAR',    1000000);   /* payments above this are reviewed   */
define('C_INT_GRACE',   30);        /* days before interest starts        */
define('C_INT_RATE',    12);        /* percent per year                   */
define('C_INT_MIN',     100);       /* interest below this is dropped     */
define('C_MIN_CHECK',   1000);      /* no cheque below this               */

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
