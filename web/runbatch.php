<?php
/* Submit the nightly job by hand.  Operations asked for this in 2008
   after the cron box died twice in a month.  The job makes up a day of
   EDI claims, adds whatever was keyed on Claim Entry, and runs CLMJOB.
   2008-10 tmw  created
   2019-11 akl  claim count box
   2023-04 ops  PHP 8, one job at a time                       CR-3310 */
include('hdr.inc.php');

if (rq('go') != '') {
    $cnt = (int) rq('count');
    if ($cnt < 1 || $cnt > 200000) {
        $cnt = 10000;
    }
    @set_time_limit(0);
    $out = array();
    $rc = 0;
    exec('COUNT=' . $cnt . ' ' . escapeshellarg($g_home . '/run/NEWDAY.sh') . ' 2>&1', $out, $rc);
    $run = '';
    if (count($out) > 0) {
        $run = trim($out[count($out) - 1]);
    }
    if (preg_match('/^[0-9]{8}_[0-9]{6}$/', $run)) {
        header('Location: report.php?run=' . $run . '&show=log');
        exit;
    }
    page_top('Run Nightly Batch');
    echo '<p class="err">The job did not start (RC=' . $rc . ').</p><pre>' . h(implode("\n", $out)) . '</pre>';
    page_end();
    exit;
}

page_top('Run Nightly Batch');
$n_key = 0;
if (file_exists($g_spool)) {
    $n_key = count(file($g_spool));
}
?>
<p>This submits job <b>CLMJOB</b> now instead of waiting for tonight. It will:</p>
<ol>
<li>receive a new day of EDI claims (in this test region they are generated),</li>
<li>add the <b><?php echo $n_key; ?></b> claim line(s) keyed on Claim Entry and empty the spool,</li>
<li>reload the database snapshot, adjudicate, cut cheques and print the reports.</li>
</ol>
<p><font color="#CC0000">The run takes about 15 seconds for 10,000 claims. Do not press the button twice.</font></p>
<form method="post" action="runbatch.php">
EDI claim lines to receive: <input type="text" name="count" size="7" maxlength="6" value="10000">
<input type="submit" name="go" value="Run Nightly Batch Now">
</form>
<?php
page_end();
