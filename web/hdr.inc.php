<?php
/* CLAIMSYS intranet - page top, menu and the helpers every page uses.
 * 2004-05 rjk  created
 * 2008-09 tmw  run selector, shared by the report pages
 * 2012-02 dps  register_globals is gone on the new box: rq()      CR-1960
 * 2023-04 ops  PHP 8                                              CR-3310 */
include_once(dirname(__FILE__) . '/../inc/config.inc.php');
include_once(dirname(__FILE__) . '/../inc/common.inc.php');

function rq($k)
{
    if (isset($_REQUEST[$k]) && is_string($_REQUEST[$k])) {
        return trim($_REQUEST[$k]);
    }
    return '';
}

function h($s)
{
    return htmlspecialchars((string) $s);
}

/* batch run folders, newest first */
function run_list()
{
    global $g_outroot;
    $runs = array();
    $d = @opendir($g_outroot);
    if ($d) {
        while (($f = readdir($d)) !== false) {
            if (preg_match('/^[0-9]{8}_[0-9]{6}$/', $f) && is_dir($g_outroot . '/' . $f)) {
                $runs[] = $f;
            }
        }
        closedir($d);
    }
    rsort($runs);
    return $runs;
}

/* run asked for on the URL, else the latest one; '' when there is none */
function cur_run()
{
    $runs = run_list();
    $r = rq('run');
    if ($r != '' && in_array($r, $runs)) {
        return $r;
    }
    if (count($runs) > 0) {
        return $runs[0];
    }
    return '';
}

function run_select($page, $run)
{
    $runs = run_list();
    echo '<form method="get" action="' . $page . '"><font size="2">Batch run: </font><select name="run">';
    for ($i = 0; $i < count($runs); $i++) {
        $r = $runs[$i];
        $lbl = substr($r, 6, 2) . '/' . substr($r, 4, 2) . '/' . substr($r, 0, 4) . ' ' . substr($r, 9, 2) . ':' . substr($r, 11, 2) . ':' . substr($r, 13, 2);
        echo '<option value="' . $r . '"' . ($r == $run ? ' selected' : '') . '>' . $lbl . ($i == 0 ? ' (latest)' : '') . '</option>';
    }
    echo '</select> <input type="submit" value="Show"></form>';
}

function page_top($title)
{
    global $g_company, $g_sysname, $g_sysver;
    $bd = get_busdate();
?>
<html>
<head>
<title><?php echo $g_sysname . ' - ' . h($title); ?></title>
<style type="text/css">
body, td, th, input, select { font-family: Verdana, Arial, Helvetica, sans-serif; font-size: 11px; }
th { background-color: #336699; color: #FFFFFF; text-align: left; }
a { color: #003399; }
.m a { color: #FFFFFF; text-decoration: none; font-weight: bold; }
.err { color: #CC0000; font-weight: bold; }
.ok { color: #006600; font-weight: bold; }
pre { font-family: "Courier New", Courier, monospace; font-size: 11px; }
</style>
</head>
<body bgcolor="#FFFFFF" leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
<table width="100%" border="0" cellspacing="0" cellpadding="4">
<tr bgcolor="#003366">
  <td><font color="#FFFFFF" size="4"><b><?php echo $g_company; ?></b></font><br>
      <font color="#CCCCCC"><?php echo $g_sysname; ?> Claims Intranet v<?php echo $g_sysver; ?></font></td>
  <td align="right"><font color="#FFFFFF">Business date: <b><?php echo dt_show($bd); ?></b><br>TEST REGION</font></td>
</tr>
<tr bgcolor="#336699" class="m">
  <td colspan="2">
    <a href="index.php">Main Menu</a> &nbsp;|&nbsp;
    <a href="member.php">Member Lookup</a> &nbsp;|&nbsp;
    <a href="claimentry.php">Claim Entry</a> &nbsp;|&nbsp;
    <a href="claimstat.php">Claim Status</a> &nbsp;|&nbsp;
    <a href="report.php">Batch Report</a> &nbsp;|&nbsp;
    <a href="denials.php">Denials &amp; Pends</a> &nbsp;|&nbsp;
    <a href="payments.php">Payments</a> &nbsp;|&nbsp;
    <a href="runbatch.php">Run Nightly Batch</a>
  </td>
</tr>
</table>
<table width="100%" border="0" cellspacing="0" cellpadding="8"><tr><td>
<font size="3" color="#003366"><b><?php echo h($title); ?></b></font>
<hr size="1" noshade color="#336699">
<?php
    if (!db_open()) {
        echo '<p class="err">The claims database is not available. Call the Help Desk on ext. 4100.</p>';
        page_end();
        exit;
    }
}

function page_end()
{
?>
</td></tr></table>
<hr size="1" noshade color="#CCCCCC">
<center><font size="1" color="#666666">CLAIMSYS - internal use only - best viewed at 1024x768</font></center>
</body>
</html>
<?php
}
