<?php
/* Cheque register and paid lines of a batch run.  2005-02 rjk.
   2019-11 akl carried balances.                               CR-3127 */
include('hdr.inc.php');
page_top('Payments');

$run = cur_run();
if ($run == '') {
    echo '<p class="err">No batch run found.</p>';
    page_end();
    exit;
}
run_select('payments.php', $run);
$dir  = $g_outroot . '/' . $run;
$prov = strtoupper(rq('prov'));
$chk  = @file($dir . '/CHECKS.DAT');
if ($chk === false) {
    echo '<p class="err">CHECKS.DAT is missing for run ' . $run . '.</p>';
    page_end();
    exit;
}
$names = array();
$res = db_q("SELECT prov_id, prov_name FROM provider");
while ($row = mysqli_fetch_assoc($res)) {
    $names[$row['prov_id']] = $row['prov_name'];
}

if ($prov != '') {
    echo '<p><a href="payments.php?run=' . $run . '">&lt;&lt; back to the cheque register</a></p>';
    echo '<b>Paid lines for ' . h($prov) . ' ' . h(isset($names[$prov]) ? $names[$prov] : '') . '</b><br>';
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Claim</th><th>Ln</th><th>Member</th><th>Service</th><th>Proc</th><th>Billed</th><th>Allowed</th><th>Copay</th><th>Deduct.</th>'
        . '<th>Coins.</th><th>Over max</th><th>Paid</th><th>Interest</th><th>Patient owes</th></tr>';
    $fp = @fopen($dir . '/PAYMENTS.DAT', 'r');
    $n = 0;
    while ($fp && !feof($fp)) {
        $l = (string) fgets($fp, 400);
        if (substr($l, 0, 2) != 'PD' || substr($l, 32, 7) != $prov) {
            continue;
        }
        $bg = ($n % 2) ? '#EEF3F8' : '#FFFFFF';
        echo '<tr bgcolor="' . $bg . '"><td>' . h(substr($l, 9, 12)) . '</td><td>' . substr($l, 21, 2) . '</td><td>' . substr($l, 23, 9) . '</td><td>'
            . dt_show(substr($l, 39, 8)) . '</td><td>' . substr($l, 47, 5) . '</td>';
        $off = array(56, 65, 74, 83, 92, 101, 110);
        for ($i = 0; $i < 7; $i++) {
            echo '<td align="right">' . fmt_money((int) substr($l, $off[$i], 9)) . '</td>';
        }
        echo '<td align="right">' . fmt_money((int) substr($l, 119, 7)) . '</td><td align="right">' . fmt_money((int) substr($l, 126, 9)) . '</td></tr>';
        $n++;
    }
    if ($fp) {
        fclose($fp);
    }
    echo '</table>' . $n . ' line(s).';
} else {
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Provider</th><th>Name</th><th>Lines</th><th>Gross</th><th>Interest</th><th>Brought fwd</th><th>Net</th><th>Cheque no.</th><th>Carried fwd</th></tr>';
    $n = 0;
    for ($i = 0; $i < count($chk); $i++) {
        $l = $chk[$i];
        if (substr($l, 0, 2) != 'CK') {
            continue;
        }
        $p = substr($l, 2, 7);
        $bg = ($n % 2) ? '#EEF3F8' : '#FFFFFF';
        $iss = (substr($l, 73, 1) == 'I');
        echo '<tr bgcolor="' . $bg . '"><td><a href="payments.php?run=' . $run . '&prov=' . $p . '">' . $p . '</a></td><td>' . h(isset($names[$p]) ? $names[$p] : '')
            . '</td><td align="right">' . (int) substr($l, 16, 5) . '</td><td align="right">' . fmt_money((int) substr($l, 21, 11)) . '</td><td align="right">'
            . fmt_money((int) substr($l, 32, 9)) . '</td><td align="right">' . fmt_money((int) substr($l, 41, 10)) . '</td><td align="right">'
            . fmt_money((int) substr($l, 51, 12)) . '</td><td>' . ($iss ? substr($l, 9, 7) : '<font color="#996600">HELD</font>') . '</td><td align="right">'
            . fmt_money((int) substr($l, 63, 10)) . '</td></tr>';
        $n++;
    }
    echo '</table>' . $n . ' provider(s).';
}
page_end();
