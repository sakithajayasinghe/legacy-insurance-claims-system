<?php
/* Claim status inquiry.  2004-06 rjk.  2015-06 akl reason text from the shared table. */
include('hdr.inc.php');
page_top('Claim Status Inquiry');

$clm = rq('clm');
$mbr = rq('mbr');
$stn = array('A' => 'APPROVED', 'D' => 'DENIED', 'P' => 'PENDED', 'V' => 'VOID');
?>
<form method="get" action="claimstat.php">
<table border="0" cellspacing="0" cellpadding="3">
<tr><td>Claim number</td><td><input type="text" name="clm" size="16" maxlength="12" value="<?php echo h($clm); ?>"></td>
    <td>or member ID</td><td><input type="text" name="mbr" size="12" maxlength="9" value="<?php echo h($mbr); ?>"></td>
    <td><input type="submit" value="Search"></td></tr>
</table>
</form>
<?php
if ($clm != '' || $mbr != '') {
    $sql = "SELECT c.*, p.prov_name FROM claim c LEFT JOIN provider p ON p.prov_id = c.prov_id WHERE ";
    if ($clm != '') {
        $sql .= "c.claim_no = '" . addslashes($clm) . "'";
    } else {
        $sql .= "c.member_id = '" . addslashes($mbr) . "'";
    }
    $sql .= " ORDER BY c.svc_date DESC, c.claim_no, c.line_no LIMIT 200";
    $res = db_q($sql);
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Claim</th><th>Ln</th><th>Member</th><th>Provider</th><th>Service</th><th>Proc</th><th>Billed</th><th>Allowed</th>'
        . '<th>Copay</th><th>Deduct.</th><th>Coins.</th><th>Paid</th><th>Status</th><th>Reason</th></tr>';
    $n = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $bg = ($n % 2) ? '#EEF3F8' : '#FFFFFF';
        $rs = '';
        if ($row['reason'] != '') {
            $rs = $row['reason'] . ' ' . (isset($g_rsn[$row['reason']]) ? $g_rsn[$row['reason']] : '');
        }
        echo '<tr bgcolor="' . $bg . '"><td>' . h($row['claim_no']) . '</td><td>' . $row['line_no'] . '</td><td><a href="member.php?id=' . h($row['member_id']) . '">'
            . h($row['member_id']) . '</a></td><td>' . h($row['prov_id'] . ' ' . $row['prov_name']) . '</td><td>' . dt_show($row['svc_date']) . '</td><td>'
            . h($row['proc_code']) . '</td><td align="right">' . fmt_money((int) $row['billed']) . '</td><td align="right">' . fmt_money((int) $row['allowed'])
            . '</td><td align="right">' . fmt_money((int) $row['copay']) . '</td><td align="right">' . fmt_money((int) $row['ded']) . '</td><td align="right">'
            . fmt_money((int) $row['coins']) . '</td><td align="right">' . fmt_money((int) $row['pay']) . '</td><td>' . $stn[$row['status']]
            . ($row['run_ind'] == 'H' ? ' (history)' : '') . '</td><td>' . h($rs) . '</td></tr>';
        $n++;
    }
    echo '</table>';
    if ($n == 0) {
        echo '<p class="err">No claim found.</p>';
    }
    /* keyed today and not yet through the batch */
    if (file_exists($g_spool)) {
        $sp = file($g_spool);
        for ($i = 0; $i < count($sp); $i++) {
            if (($clm != '' && rtrim(substr($sp[$i], 2, 12)) == $clm) || ($mbr != '' && substr($sp[$i], 16, 9) == $mbr)) {
                echo '<p>Claim ' . h(substr($sp[$i], 2, 12)) . ' line ' . h(substr($sp[$i], 14, 2)) . ' was keyed today and is <b>waiting for the nightly batch</b>.</p>';
            }
        }
    }
}
page_end();
