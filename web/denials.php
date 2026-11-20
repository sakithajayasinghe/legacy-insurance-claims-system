<?php
/* Denied and pended lines of a batch run, straight off DENIALS.DAT and
   PENDED.DAT.  2005-02 rjk.  2016-10 akl pends added.                */
include('hdr.inc.php');
page_top('Denials and Pends');

$run = cur_run();
if ($run == '') {
    echo '<p class="err">No batch run found.</p>';
    page_end();
    exit;
}
run_select('denials.php', $run);
$dir  = $g_outroot . '/' . $run;
$code = rq('code');
$den  = @file($dir . '/DENIALS.DAT');
$pnd  = @file($dir . '/PENDED.DAT');
if ($den === false || $pnd === false) {
    echo '<p class="err">Output files are missing for run ' . $run . '.</p>';
    page_end();
    exit;
}
$all = array_merge($den, $pnd);
$cnt = array();
for ($i = 0; $i < count($all); $i++) {
    $t = substr($all[$i], 0, 2);
    if ($t == 'DN' || $t == 'PN') {
        $c = substr($all[$i], 61, 3);
        $cnt[$c] = isset($cnt[$c]) ? $cnt[$c] + 1 : 1;
    }
}
ksort($cnt);
echo '<table border="0" cellspacing="0" cellpadding="6"><tr valign="top"><td>';
echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699"><tr><th>Code</th><th>Reason</th><th>Lines</th></tr>';
foreach ($cnt as $c => $n) {
    echo '<tr' . ((string) $c == $code ? ' bgcolor="#FFFFCC"' : '') . '><td><a href="denials.php?run=' . $run . '&code=' . $c . '">' . $c . '</a></td><td>'
        . h(isset($g_rsn[$c]) ? $g_rsn[$c] : '') . '</td><td align="right">' . $n . '</td></tr>';
}
echo '</table></td><td>';
if ($code == '') {
    echo 'Pick a reason code to list the lines.';
} else {
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Rec</th><th>Claim</th><th>Ln</th><th>Member</th><th>Provider</th><th>Service</th><th>Proc</th><th>Billed</th><th>Type</th></tr>';
    $n = 0;
    for ($i = 0; $i < count($all) && $n < 300; $i++) {
        $l = $all[$i];
        $t = substr($l, 0, 2);
        if (($t != 'DN' && $t != 'PN') || substr($l, 61, 3) != $code) {
            continue;
        }
        $bg = ($n % 2) ? '#EEF3F8' : '#FFFFFF';
        echo '<tr bgcolor="' . $bg . '"><td>' . (int) substr($l, 2, 7) . '</td><td><a href="claimstat.php?clm=' . urlencode(trim(substr($l, 9, 12))) . '">'
            . h(substr($l, 9, 12)) . '</a></td><td>' . h(substr($l, 21, 2)) . '</td><td>' . h(substr($l, 23, 9)) . '</td><td>' . h(substr($l, 32, 7))
            . '</td><td>' . h(substr($l, 39, 8)) . '</td><td>' . h(substr($l, 47, 5)) . '</td><td align="right">' . h(substr($l, 52, 9))
            . '</td><td>' . ($t == 'DN' ? 'DENIED' : 'PENDED') . '</td></tr>';
        $n++;
    }
    echo '</table>';
    if ($n == 300) {
        echo 'First 300 lines shown.';
    }
}
echo '</td></tr></table>';
page_end();
