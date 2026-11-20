<?php
/* Batch report viewer.  2004-06 rjk.  2008-09 tmw run selector, job log. */
include('hdr.inc.php');
page_top('Nightly Batch Report');

$run = cur_run();
if ($run == '') {
    echo '<p class="err">No batch run found.</p>';
    page_end();
    exit;
}
run_select('report.php', $run);
$dir = $g_outroot . '/' . $run;
$what = rq('show');
echo '<p><a href="report.php?run=' . $run . '">Remittance summary</a> &nbsp;|&nbsp; <a href="report.php?run=' . $run . '&show=log">Job log</a>'
    . ' &nbsp;|&nbsp; <a href="report.php?run=' . $run . '&show=gl">Ledger extract</a> &nbsp;|&nbsp; <a href="report.php?run=' . $run
    . '&show=audit">Audit log (last 200 lines)</a></p>';
$file = 'REMIT.RPT';
if ($what == 'log') {
    $file = 'JOBLOG.TXT';
} else if ($what == 'gl') {
    $file = 'GLEXTRACT.DAT';
} else if ($what == 'audit') {
    $file = 'AUDIT.LOG';
}
$txt = @file($dir . '/' . $file);
if ($txt === false) {
    echo '<p class="err">' . $file . ' is missing for run ' . $run . '.</p>';
} else {
    if ($what == 'audit' && count($txt) > 200) {
        $txt = array_slice($txt, count($txt) - 200);
    }
    echo '<table border="1" cellspacing="0" cellpadding="6" bordercolor="#336699"><tr><th>' . $file . ' &nbsp; run ' . $run . '</th></tr><tr><td bgcolor="#F8F8F0"><pre>';
    for ($i = 0; $i < count($txt); $i++) {
        echo h(str_replace("\f", '', $txt[$i]));
    }
    echo '</pre></td></tr></table>';
}
page_end();
