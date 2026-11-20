<?php
include('hdr.inc.php');
page_top('Main Menu');

$n_mbr = db_val("SELECT COUNT(*) FROM member");
$n_prv = db_val("SELECT COUNT(*) FROM provider");
$n_clm = db_val("SELECT COUNT(*) FROM claim");
$n_cur = db_val("SELECT COUNT(*) FROM claim WHERE run_ind = 'C'");
$n_key = 0;
if (file_exists($g_spool)) {
    $n_key = count(file($g_spool));
}
$run = cur_run();
?>
<table border="0" cellspacing="0" cellpadding="6" width="760">
<tr valign="top">
<td width="55%">
  <table border="1" cellspacing="0" cellpadding="4" bordercolor="#336699" width="100%">
  <tr><th>Function</th><th>Use it to</th></tr>
  <tr><td><a href="member.php">Member Lookup</a></td><td>Find a member, check eligibility and accumulators</td></tr>
  <tr><td><a href="claimentry.php">Claim Entry</a></td><td>Key a paper claim for tonight's batch</td></tr>
  <tr><td><a href="claimstat.php">Claim Status</a></td><td>Answer a provider or member call about a claim</td></tr>
  <tr><td><a href="report.php">Batch Report</a></td><td>Remittance summary of a nightly run</td></tr>
  <tr><td><a href="denials.php">Denials &amp; Pends</a></td><td>Lines denied or held for review</td></tr>
  <tr><td><a href="payments.php">Payments</a></td><td>Cheque register and paid lines by provider</td></tr>
  <tr><td><a href="runbatch.php">Run Nightly Batch</a></td><td>Operations only: submit the adjudication job now</td></tr>
  </table>
</td>
<td>
  <table border="1" cellspacing="0" cellpadding="4" bordercolor="#336699" width="100%">
  <tr><th colspan="2">System status</th></tr>
  <tr><td>Business date</td><td><b><?php echo dt_show(get_busdate()); ?></b></td></tr>
  <tr><td>Last batch run</td><td><?php echo $run == '' ? 'none' : '<a href="report.php?run=' . $run . '">' . $run . '</a>'; ?></td></tr>
  <tr><td>Members on file</td><td><?php echo number_format((int) $n_mbr); ?></td></tr>
  <tr><td>Providers on file</td><td><?php echo number_format((int) $n_prv); ?></td></tr>
  <tr><td>Claim lines on file</td><td><?php echo number_format((int) $n_clm); ?> (<?php echo number_format((int) $n_cur); ?> from the last run)</td></tr>
  <tr><td>Keyed, waiting for batch</td><td><?php echo $n_key; ?></td></tr>
  </table>
</td>
</tr>
</table>
<p><font color="#666666">Reminder from Claims Operations: paper claims keyed after 6pm go into the next night's run.</font></p>
<?php
page_end();
