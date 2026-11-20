<?php
/* ------------------------------------------------------------------
 * Claim entry - paper claims keyed by the claims clerks.
 * A good claim line is written to the spool file in the same 120 byte
 * layout as the EDI claims file and goes into tonight's batch.
 *
 * The edits below are a copy of edit_rec() in batch/CLMADJ01.php so
 * the clerk sees the problem straight away.  CHANGE BOTH.
 *
 * 2004-06 rjk  created
 * 2004-09 rjk  timely filing warning                          CR-0118
 * 2007-12 tmw  pre-auth number, warning over 5000             CR-1190
 * 2010-06 dps  uses the business date, not today              CR-1730
 * 2015-06 akl  corrected claims                               CR-2610
 * 2023-04 ops  PHP 8                                          CR-3310
 * ------------------------------------------------------------------ */
include('hdr.inc.php');
page_top('Claim Entry');

$busdate = get_busdate();
$errs = array();
$warn = array();
$done = '';

$f_member = rq('member');
$f_prov   = strtoupper(rq('prov'));
$f_svc    = rq('svc');
$f_line   = rq('line');
$f_proc   = strtoupper(rq('proc'));
$f_diag   = strtoupper(rq('diag'));
$f_units  = rq('units');
$f_billed = rq('billed');
$f_sub    = rq('sub');
$f_orig   = strtoupper(rq('orig'));
$f_auth   = strtoupper(rq('auth'));
$f_emerg  = rq('emerg');
$f_cobamt = rq('cobamt');
if ($f_line == '') {
    $f_line = '1';
}
if ($f_sub != 'R') {
    $f_sub = 'O';
}

if (rq('go') != '') {
    /* ---- member ---- */
    $mbr = 0;
    if (!preg_match('/^[0-9]{9}$/', $f_member)) {
        $errs[] = 'Member ID must be 9 digits.';
    } else {
        $mbr = db_row("SELECT member_id, DATE_FORMAT(eff_date,'%Y%m%d') AS eff, IFNULL(DATE_FORMAT(term_date,'%Y%m%d'),'') AS term, other_ins "
            . "FROM member WHERE member_id = '" . $f_member . "'");
        if (!$mbr) {
            $errs[] = 'Member ' . $f_member . ' is not on file.';
        }
    }
    /* ---- provider ---- */
    $prv = 0;
    if ($f_prov == '') {
        $errs[] = 'Provider ID is required.';
    } else {
        $prv = db_row("SELECT prov_id, net_stat, status FROM provider WHERE prov_id = '" . addslashes($f_prov) . "'");
        if (!$prv) {
            $errs[] = 'Provider ' . $f_prov . ' is not on file.';
        }
    }
    /* ---- date of service DD/MM/YYYY ---- */
    $svc = '';
    if (!preg_match('/^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/', $f_svc, $mt)) {
        $errs[] = 'Date of service must be DD/MM/YYYY.';
    } else if (!checkdate((int) $mt[2], (int) $mt[1], (int) $mt[3])) {
        $errs[] = 'Date of service is not a real date.';
    } else {
        $svc = $mt[3] . $mt[2] . $mt[1];
        if (strcmp($svc, $busdate) > 0) {
            $errs[] = 'Date of service is after the business date.';
            $svc = '';
        }
    }
    /* ---- line: the paper form has 10 lines ---- */
    if (!ctype_digit($f_line) || (int) $f_line < 1 || (int) $f_line > 10) {
        $errs[] = 'Line number must be 1 to 10.';
    }
    /* ---- codes ---- */
    $prc = db_row("SELECT proc_code, preauth FROM proccode WHERE proc_code = '" . addslashes($f_proc) . "'");
    if (!$prc) {
        $errs[] = 'Procedure code ' . $f_proc . ' is not on file.';
    }
    if (db_val("SELECT COUNT(*) FROM diagcode WHERE diag_code = '" . addslashes($f_diag) . "'") == 0) {
        $errs[] = 'Diagnosis code ' . $f_diag . ' is not on file.';
    }
    /* ---- units and amounts ---- */
    if ($f_units == '') {
        $f_units = '1';
    }
    if (!ctype_digit($f_units) || strlen($f_units) > 3) {
        $errs[] = 'Units must be a number up to 999.';
    }
    $billed = 0;
    if (!preg_match('/^([0-9]{1,7})\.([0-9]{2})$/', $f_billed, $mt)) {
        $errs[] = 'Billed amount must be entered as dollars and cents, e.g. 125.00';
    } else {
        $billed = (int) $mt[1] * 100 + (int) $mt[2];
        if ($billed == 0) {
            $errs[] = 'Billed amount cannot be zero.';
        }
    }
    $cobamt = 0;
    $cob = 'N';
    if ($f_cobamt != '') {
        if (!preg_match('/^([0-9]{1,7})\.([0-9]{2})$/', $f_cobamt, $mt)) {
            $errs[] = 'Other insurance paid must be entered as dollars and cents.';
        } else {
            $cobamt = (int) $mt[1] * 100 + (int) $mt[2];
            $cob = 'Y';
        }
    }
    if ($f_sub == 'R' && $f_orig == '') {
        $errs[] = 'A corrected claim needs the original claim number.';
    }
    if (strlen($f_orig) > 12 || strlen($f_auth) > 10 || !preg_match('/^[A-Z0-9]*$/', $f_orig . $f_auth)) {
        $errs[] = 'Original claim number / pre-authorisation number is not valid.';
    }

    /* ---- warnings: the claim is still taken ---- */
    if (count($errs) == 0) {
        if ($mbr['term'] != '' && strcmp($svc, $mbr['term']) > 0) {
            $warn[] = 'Member coverage ended ' . dt_show($mbr['term']) . ' - the claim may be denied.';
        }
        if (strcmp($svc, $mbr['eff']) < 0) {
            $warn[] = 'Member coverage starts ' . dt_show($mbr['eff']) . ' - the claim may be denied.';
        }
        /* timely filing: six months */
        $y = (int) substr($busdate, 0, 4);
        $m = (int) substr($busdate, 4, 2) - 6;
        if ($m < 1) {
            $m = $m + 12;
            $y = $y - 1;
        }
        $cut = sprintf('%04d%02d', $y, $m) . substr($busdate, 6, 2);
        if (strcmp($svc, $cut) < 0) {
            $warn[] = 'Date of service is more than six months ago - check timely filing.';
        }
        if (($prc['preauth'] == 'Y' || $billed >= 500000) && $f_auth == '' && $f_emerg != 'Y') {
            $warn[] = 'This service normally needs a pre-authorisation number.';
        }
        if ($mbr['other_ins'] == 'Y' && $cob == 'N') {
            $warn[] = 'Member has other insurance on file - enter what the other insurer paid.';
        }
        if ($prv['status'] != 'A') {
            $warn[] = 'Provider is not in active status (' . $prv['status'] . ').';
        }

        /* ---- write the spool record ---- */
        $n = 0;
        if (file_exists($g_spool)) {
            $n = count(file($g_spool));
        }
        $yr  = (int) substr($busdate, 0, 4);
        $jul = dt_days($busdate) - dt_days(sprintf('%04d0101', $yr)) + 1;
        $claim = rq('claim');
        if (!preg_match('/^K[0-9]{11}$/', $claim)) {
            $claim = 'K' . substr($busdate, 2, 2) . sprintf('%03d', $jul) . sprintf('%06d', $n + 1);
        }
        $rec = 'CL' . $claim . sprintf('%02d', $f_line) . $f_member . str_pad($f_prov, 7) . $svc . $busdate
            . str_pad($f_proc, 5) . str_pad($f_diag, 5) . sprintf('%03d', $f_units) . sprintf('%09d', $billed)
            . $f_sub . str_pad($f_orig, 12) . str_pad($f_auth, 10) . ($f_emerg == 'Y' ? 'Y' : 'N') . $cob
            . sprintf('%09d', $cobamt) . 'P';
        $rec = str_pad($rec, C_RECLEN);
        $fp = @fopen($g_spool, 'a');
        if (!$fp || strlen($rec) != C_RECLEN) {
            $errs[] = 'Could not write the claim to the spool file. Call the Help Desk on ext. 4100.';
        } else {
            flock($fp, LOCK_EX);
            fwrite($fp, $rec . "\n");
            flock($fp, LOCK_UN);
            fclose($fp);
            $done = $claim;
            $f_line = (string) ((int) $f_line + 1);
            $f_proc = '';
            $f_billed = '';
            $f_units = '';
        }
    }
}
if (count($errs) > 0) {
    echo '<p class="err">The claim line was NOT accepted:</p><ul>';
    for ($i = 0; $i < count($errs); $i++) {
        echo '<li class="err">' . h($errs[$i]) . '</li>';
    }
    echo '</ul>';
}
if ($done != '') {
    echo '<p class="ok">Claim ' . $done . ' line accepted for tonight\'s batch.</p>';
    for ($i = 0; $i < count($warn); $i++) {
        echo '<p><font color="#996600"><b>Warning:</b> ' . h($warn[$i]) . '</font></p>';
    }
}
?>
<form method="post" action="claimentry.php">
<input type="hidden" name="claim" value="<?php echo h($done); ?>">
<table border="1" cellspacing="0" cellpadding="4" bordercolor="#336699">
<tr><th colspan="4">Paper claim line <?php echo $done != '' ? '(next line of claim ' . $done . ')' : ''; ?></th></tr>
<tr><td>Member ID</td><td><input type="text" name="member" size="12" maxlength="9" value="<?php echo h($f_member); ?>"></td>
    <td>Provider ID</td><td><input type="text" name="prov" size="10" maxlength="7" value="<?php echo h($f_prov); ?>"></td></tr>
<tr><td>Date of service</td><td><input type="text" name="svc" size="12" maxlength="10" value="<?php echo h($f_svc); ?>"> DD/MM/YYYY</td>
    <td>Line number</td><td><input type="text" name="line" size="3" maxlength="2" value="<?php echo h($f_line); ?>"></td></tr>
<tr><td>Procedure code</td><td><input type="text" name="proc" size="7" maxlength="5" value="<?php echo h($f_proc); ?>"></td>
    <td>Diagnosis code</td><td><input type="text" name="diag" size="7" maxlength="5" value="<?php echo h($f_diag); ?>"></td></tr>
<tr><td>Units</td><td><input type="text" name="units" size="4" maxlength="3" value="<?php echo h($f_units); ?>"></td>
    <td>Billed amount</td><td><input type="text" name="billed" size="12" maxlength="10" value="<?php echo h($f_billed); ?>"></td></tr>
<tr><td>Submission</td><td><select name="sub"><option value="O"<?php echo $f_sub == 'O' ? ' selected' : ''; ?>>Original</option>
        <option value="R"<?php echo $f_sub == 'R' ? ' selected' : ''; ?>>Corrected claim</option></select></td>
    <td>Original claim no.</td><td><input type="text" name="orig" size="14" maxlength="12" value="<?php echo h($f_orig); ?>"></td></tr>
<tr><td>Pre-auth number</td><td><input type="text" name="auth" size="12" maxlength="10" value="<?php echo h($f_auth); ?>"></td>
    <td>Emergency</td><td><input type="checkbox" name="emerg" value="Y"<?php echo $f_emerg == 'Y' ? ' checked' : ''; ?>></td></tr>
<tr><td>Other insurance paid</td><td colspan="3"><input type="text" name="cobamt" size="12" maxlength="10" value="<?php echo h($f_cobamt); ?>"> leave blank if none</td></tr>
<tr><td colspan="4" align="center"><input type="submit" name="go" value="Accept Claim Line"> <input type="reset" value="Clear"></td></tr>
</table>
</form>
<?php
/* ---- what is waiting in the spool ---- */
echo '<br><b>Keyed and waiting for the nightly batch</b><br>';
$sp = array();
if (file_exists($g_spool)) {
    $sp = file($g_spool);
}
if (count($sp) == 0) {
    echo 'Nothing waiting.';
} else {
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Claim</th><th>Ln</th><th>Member</th><th>Provider</th><th>Service</th><th>Proc</th><th>Diag</th><th>Units</th><th>Billed</th></tr>';
    for ($i = 0; $i < count($sp); $i++) {
        $l = $sp[$i];
        echo '<tr><td>' . h(substr($l, 2, 12)) . '</td><td>' . h(substr($l, 14, 2)) . '</td><td>' . h(substr($l, 16, 9)) . '</td><td>' . h(substr($l, 25, 7))
            . '</td><td>' . dt_show(substr($l, 32, 8)) . '</td><td>' . h(substr($l, 48, 5)) . '</td><td>' . h(substr($l, 53, 5)) . '</td><td>'
            . (int) substr($l, 58, 3) . '</td><td align="right">' . fmt_money((int) substr($l, 61, 9)) . '</td></tr>';
    }
    echo '</table>';
}
page_end();
