<?php
/* Member lookup.  2004-05 rjk.  2006-07 tmw accumulators.  2012-09 dps benefit maximums. */
include('hdr.inc.php');
page_top('Member Lookup');

$id = rq('id');
$nm = rq('nm');
?>
<form method="get" action="member.php">
<table border="0" cellspacing="0" cellpadding="3">
<tr><td>Member ID</td><td><input type="text" name="id" size="12" maxlength="9" value="<?php echo h($id); ?>"></td>
    <td>or last name starts with</td><td><input type="text" name="nm" size="20" maxlength="25" value="<?php echo h($nm); ?>"></td>
    <td><input type="submit" value="Search"></td></tr>
</table>
</form>
<?php
if ($id == '' && $nm != '') {
    $res = db_q("SELECT member_id, last_name, first_name, dob, rel, plan_id FROM member WHERE last_name LIKE '"
        . addslashes($nm) . "%' ORDER BY last_name, member_id LIMIT 100");
    echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
    echo '<tr><th>Member ID</th><th>Name</th><th>Born</th><th>Rel</th><th>Plan</th></tr>';
    $n = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $bg = ($n % 2) ? '#EEF3F8' : '#FFFFFF';
        echo '<tr bgcolor="' . $bg . '"><td><a href="member.php?id=' . $row['member_id'] . '">' . $row['member_id'] . '</a></td><td>'
            . h($row['last_name'] . ', ' . $row['first_name']) . '</td><td>' . dt_show(str_replace('-', '', $row['dob']))
            . '</td><td>' . $row['rel'] . '</td><td>' . $row['plan_id'] . '</td></tr>';
        $n++;
    }
    echo '</table>';
    if ($n == 0) {
        echo '<p class="err">No member found.</p>';
    } else if ($n == 100) {
        echo '<p>First 100 shown, narrow the search.</p>';
    }
}
if ($id != '') {
    $m = db_row("SELECT * FROM v_elig WHERE member_id = '" . addslashes($id) . "'");
    if (!$m) {
        echo '<p class="err">Member ' . h($id) . ' is not on file.</p>';
    } else {
        $pl = db_row("SELECT * FROM plan WHERE plan_id = '" . $m['plan_id'] . "'");
        $bd = get_busdate();
        $st = 'ACTIVE';
        $cl = 'ok';
        if (strcmp($bd, $m['eff_date']) < 0) {
            $st = 'NOT YET EFFECTIVE';
            $cl = 'err';
        } else if (strcmp($bd, $m['cov_thru']) > 0) {
            $st = 'TERMINATED';
            $cl = 'err';
        } else if (strcmp($bd, $m['term_date']) > 0) {
            $st = 'GRACE PERIOD';
            $cl = 'err';
        }
        $rels = array('S' => 'Subscriber', 'P' => 'Spouse', 'C' => 'Child');
        echo '<table border="1" cellspacing="0" cellpadding="4" bordercolor="#336699" width="760">';
        echo '<tr><th colspan="4">' . h($m['last_name'] . ', ' . $m['first_name']) . ' &nbsp; ' . $m['member_id'] . '</th></tr>';
        echo '<tr><td>Date of birth</td><td>' . dt_show($m['dob']) . ' (age ' . dt_age($m['dob'], $bd) . ')</td><td>Sex</td><td>' . $m['sex'] . '</td></tr>';
        echo '<tr><td>Relationship</td><td>' . $rels[$m['rel']] . ($m['stud_flag'] == 'Y' ? ' (student)' : '') . ($m['stud_flag'] == 'D' ? ' (disabled dependent)' : '')
            . '</td><td>Group</td><td>' . $m['grp_id'] . ' (' . $m['grp_lives'] . ' lives)</td></tr>';
        echo '<tr><td>Plan</td><td>' . $m['plan_id'] . ' ' . h($pl['plan_name']) . ' / ' . ($m['cov_tier'] == 'F' ? 'Family' : 'Single') . '</td><td>Other insurance</td><td>' . $m['other_ins'] . '</td></tr>';
        echo '<tr><td>Effective</td><td>' . dt_show($m['eff_date']) . '</td><td>Terminated</td><td>'
            . ($m['term_date'] == '99991231' ? '-' : dt_show($m['term_date']) . ' (' . $m['term_rsn'] . ') claims accepted to ' . dt_show($m['cov_thru'])) . '</td></tr>';
        echo '<tr><td>Status today</td><td class="' . $cl . '">' . $st . '</td><td>Lifetime used</td><td>' . fmt_money((int) $m['life_used']) . ' of ' . fmt_money((int) $pl['life_max']) . '</td></tr>';
        echo '</table><br>';

        echo '<table border="1" cellspacing="0" cellpadding="3" bordercolor="#336699">';
        echo '<tr><th>Year</th><th>Accumulator</th><th>Met</th><th>Plan limit</th></tr>';
        $res = db_q("SELECT * FROM accum WHERE member_id = '" . $m['member_id'] . "' ORDER BY plan_yr DESC");
        while ($row = mysqli_fetch_assoc($res)) {
            echo '<tr><td>' . $row['plan_yr'] . '</td><td>Individual deductible</td><td align="right">' . fmt_money((int) $row['ded_met']) . '</td><td align="right">' . fmt_money((int) $pl['ind_ded']) . '</td></tr>';
            echo '<tr><td>' . $row['plan_yr'] . '</td><td>Individual out-of-pocket</td><td align="right">' . fmt_money((int) $row['oop_met']) . '</td><td align="right">' . fmt_money((int) $pl['ind_oop']) . '</td></tr>';
        }
        if ($m['cov_tier'] == 'F') {
            $res = db_q("SELECT * FROM famaccum WHERE sub_id = '" . $m['sub_id'] . "' ORDER BY plan_yr DESC");
            while ($row = mysqli_fetch_assoc($res)) {
                echo '<tr><td>' . $row['plan_yr'] . '</td><td>Family deductible</td><td align="right">' . fmt_money((int) $row['ded_met']) . '</td><td align="right">' . fmt_money((int) $pl['fam_ded']) . '</td></tr>';
                echo '<tr><td>' . $row['plan_yr'] . '</td><td>Family out-of-pocket</td><td align="right">' . fmt_money((int) $row['oop_met']) . '</td><td align="right">' . fmt_money((int) $pl['fam_oop']) . '</td></tr>';
            }
        }
        $res = db_q("SELECT a.plan_yr, a.svc_class, a.amt_used, b.ann_max FROM bnaccum a LEFT JOIN bnft b ON b.plan_id = '" . $m['plan_id']
            . "' AND b.svc_class = a.svc_class WHERE a.member_id = '" . $m['member_id'] . "' ORDER BY a.plan_yr DESC, a.svc_class");
        while ($row = mysqli_fetch_assoc($res)) {
            echo '<tr><td>' . $row['plan_yr'] . '</td><td>Benefit maximum, class ' . $row['svc_class'] . '</td><td align="right">' . fmt_money((int) $row['amt_used']) . '</td><td align="right">' . fmt_money((int) $row['ann_max']) . '</td></tr>';
        }
        echo '</table><br>';

        echo '<b>Family</b><br>';
        $res = db_q("SELECT member_id, first_name, rel, dob FROM member WHERE sub_id = '" . $m['sub_id'] . "' ORDER BY member_id");
        while ($row = mysqli_fetch_assoc($res)) {
            echo '<a href="member.php?id=' . $row['member_id'] . '">' . $row['member_id'] . '</a> ' . h($row['first_name']) . ' (' . $rels[$row['rel']] . ') &nbsp; ';
        }
        echo '<br><br><a href="claimstat.php?mbr=' . $m['member_id'] . '">Claims for this member</a> &nbsp;|&nbsp; <a href="claimentry.php?member=' . $m['member_id'] . '">Key a claim</a>';
    }
}
page_end();
