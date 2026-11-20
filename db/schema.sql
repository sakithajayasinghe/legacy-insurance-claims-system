-- ---------------------------------------------------------------------
-- CLAIMSYS  -  Tarrow Valley Mutual Health  -  claims database
-- schema.sql : tables and views.  Loaded by run/RUNADJUD.sh STEP010.
--
-- 2004-03  rjk  first cut (members, providers, claims)
-- 2005-01  rjk  benefit table split out of plan      CR-0412
-- 2006-06  tmw  accumulators moved to own tables     CR-0877
-- 2007-11  tmw  preauth table                        CR-1190
-- 2009-02  dps  v_elig view (grace days were wrong in 3 pages)  CR-1502
-- 2012-08  dps  bnaccum (benefit maximums)           CR-2044
-- 2015-05  akl  v_dupchk view, void status           CR-2610
-- 2019-10  akl  provider carry balance               CR-3127
-- 2023-04  ops  MariaDB 10.11 move, engine left as MyISAM on purpose
-- ---------------------------------------------------------------------
-- NOTE all money columns are whole cents.  Dates are DATE on the
-- master tables and CHAR(8) YYYYMMDD on claim (as they come off the
-- claims file).  Do not "fix" this, half the pages depend on it.

DROP VIEW  IF EXISTS v_elig;
DROP VIEW  IF EXISTS v_dupchk;
DROP TABLE IF EXISTS sysctl;
DROP TABLE IF EXISTS grp;
DROP TABLE IF EXISTS plan;
DROP TABLE IF EXISTS bnft;
DROP TABLE IF EXISTS member;
DROP TABLE IF EXISTS provider;
DROP TABLE IF EXISTS proccode;
DROP TABLE IF EXISTS diagcode;
DROP TABLE IF EXISTS preauth;
DROP TABLE IF EXISTS accum;
DROP TABLE IF EXISTS famaccum;
DROP TABLE IF EXISTS bnaccum;
DROP TABLE IF EXISTS claim;

-- system control values (next cheque number etc.)
CREATE TABLE sysctl (
  ctl_key   VARCHAR(20) NOT NULL,
  ctl_val   VARCHAR(40) NOT NULL,
  PRIMARY KEY (ctl_key)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- employer groups
CREATE TABLE grp (
  grp_id    CHAR(6)     NOT NULL,
  grp_name  VARCHAR(40) NOT NULL,
  lives     INT         NOT NULL DEFAULT 0,
  eff_date  DATE        NOT NULL,
  PRIMARY KEY (grp_id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- plans.  ded_type E = embedded individual deductible, A = aggregate
-- copay_oop Y = copays count to the out-of-pocket maximum
CREATE TABLE plan (
  plan_id   CHAR(3)     NOT NULL,
  plan_name VARCHAR(30) NOT NULL,
  ind_ded   INT         NOT NULL,
  fam_ded   INT         NOT NULL,
  ind_oop   INT         NOT NULL,
  fam_oop   INT         NOT NULL,
  ded_type  CHAR(1)     NOT NULL DEFAULT 'E',
  copay_oop CHAR(1)     NOT NULL DEFAULT 'N',
  ucr_pct   INT         NOT NULL DEFAULT 100,
  life_max  BIGINT      NOT NULL,
  PRIMARY KEY (plan_id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- benefit table: one row per plan and service class
CREATE TABLE bnft (
  plan_id   CHAR(3)  NOT NULL,
  svc_class CHAR(2)  NOT NULL,
  covered   CHAR(1)  NOT NULL DEFAULT 'Y',
  copay     INT      NOT NULL DEFAULT 0,
  ded_appl  CHAR(1)  NOT NULL DEFAULT 'Y',
  inn_pct   INT      NOT NULL DEFAULT 80,
  oon_pct   INT      NOT NULL DEFAULT 60,
  wait_days INT      NOT NULL DEFAULT 0,
  ann_max   INT      NOT NULL DEFAULT 0,
  PRIMARY KEY (plan_id, svc_class)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- members.  member_id = 7 digit subscriber + 2 digit person suffix
-- rel S subscriber, P spouse/partner, C child
-- stud_flag Y certified student, D disabled dependent, N neither
CREATE TABLE member (
  member_id  CHAR(9)     NOT NULL,
  sub_id     CHAR(7)     NOT NULL,
  last_name  VARCHAR(25) NOT NULL,
  first_name VARCHAR(20) NOT NULL,
  dob        DATE        NOT NULL,
  sex        CHAR(1)     NOT NULL,
  rel        CHAR(1)     NOT NULL,
  grp_id     CHAR(6)     NOT NULL,
  plan_id    CHAR(3)     NOT NULL,
  cov_tier   CHAR(1)     NOT NULL DEFAULT 'S',
  eff_date   DATE        NOT NULL,
  term_date  DATE            NULL,
  term_rsn   CHAR(2)     NOT NULL DEFAULT '',
  stud_flag  CHAR(1)     NOT NULL DEFAULT 'N',
  other_ins  CHAR(1)     NOT NULL DEFAULT 'N',
  life_used  BIGINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id),
  KEY ix_member_sub (sub_id),
  KEY ix_member_name (last_name)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- providers.  net_stat I in network, O out of network
-- status A active, S payment suspended, X sanctioned (from stat_date)
CREATE TABLE provider (
  prov_id    CHAR(7)     NOT NULL,
  prov_name  VARCHAR(40) NOT NULL,
  prov_type  CHAR(2)     NOT NULL,
  net_stat   CHAR(1)     NOT NULL,
  cntr_pct   INT         NOT NULL DEFAULT 100,
  status     CHAR(1)     NOT NULL DEFAULT 'A',
  stat_date  DATE            NULL,
  lic_exp    DATE        NOT NULL,
  carry_bal  INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (prov_id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- procedure codes (house codes) with the fee schedule
CREATE TABLE proccode (
  proc_code CHAR(5)     NOT NULL,
  descr     VARCHAR(40) NOT NULL,
  svc_class CHAR(2)     NOT NULL,
  fee       INT         NOT NULL,
  preauth   CHAR(1)     NOT NULL DEFAULT 'N',
  sex_only  CHAR(1)     NOT NULL DEFAULT '',
  min_age   INT         NOT NULL DEFAULT 0,
  max_age   INT         NOT NULL DEFAULT 999,
  PRIMARY KEY (proc_code)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- diagnosis codes (house codes); first letter is the category
CREATE TABLE diagcode (
  diag_code CHAR(5)     NOT NULL,
  descr     VARCHAR(40) NOT NULL,
  PRIMARY KEY (diag_code)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- pre-authorisations.  status A approved, C cancelled
CREATE TABLE preauth (
  auth_no   CHAR(10) NOT NULL,
  member_id CHAR(9)  NOT NULL,
  proc_code CHAR(5)  NOT NULL,
  from_date DATE     NOT NULL,
  to_date   DATE     NOT NULL,
  status    CHAR(1)  NOT NULL DEFAULT 'A',
  PRIMARY KEY (auth_no)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- member accumulators per plan year
CREATE TABLE accum (
  member_id CHAR(9) NOT NULL,
  plan_yr   INT     NOT NULL,
  ded_met   INT     NOT NULL DEFAULT 0,
  oop_met   INT     NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id, plan_yr)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- family accumulators per plan year (family tier only)
CREATE TABLE famaccum (
  sub_id    CHAR(7) NOT NULL,
  plan_yr   INT     NOT NULL,
  ded_met   INT     NOT NULL DEFAULT 0,
  oop_met   INT     NOT NULL DEFAULT 0,
  PRIMARY KEY (sub_id, plan_yr)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- benefit maximum accumulators (only classes with an annual maximum)
CREATE TABLE bnaccum (
  member_id CHAR(9) NOT NULL,
  plan_yr   INT     NOT NULL,
  svc_class CHAR(2) NOT NULL,
  amt_used  INT     NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id, plan_yr, svc_class)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- claim lines.  status A approved, D denied, P pended, V void
-- run_ind H = history (loaded), C = current batch run
CREATE TABLE claim (
  clm_seq   INT      NOT NULL AUTO_INCREMENT,
  claim_no  CHAR(12) NOT NULL,
  line_no   INT      NOT NULL,
  member_id CHAR(9)  NOT NULL,
  prov_id   CHAR(7)  NOT NULL,
  svc_date  CHAR(8)  NOT NULL,
  rcv_date  CHAR(8)  NOT NULL,
  proc_code CHAR(5)  NOT NULL,
  diag_code CHAR(5)  NOT NULL,
  units     INT      NOT NULL DEFAULT 1,
  billed    INT      NOT NULL DEFAULT 0,
  allowed   INT      NOT NULL DEFAULT 0,
  copay     INT      NOT NULL DEFAULT 0,
  ded       INT      NOT NULL DEFAULT 0,
  coins     INT      NOT NULL DEFAULT 0,
  pay       INT      NOT NULL DEFAULT 0,
  int_amt   INT      NOT NULL DEFAULT 0,
  status    CHAR(1)  NOT NULL,
  reason    CHAR(3)  NOT NULL DEFAULT '',
  run_ind   CHAR(1)  NOT NULL DEFAULT 'C',
  PRIMARY KEY (clm_seq),
  KEY ix_claim_dup (member_id, svc_date, proc_code, prov_id),
  KEY ix_claim_no (claim_no),
  KEY ix_claim_prov (prov_id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- eligibility view.  cov_thru is the last day a service date is
-- accepted: members cancelled for non-payment (NP) keep a grace
-- period after the termination date.                     CR-1502
CREATE VIEW v_elig AS
SELECT m.member_id, m.sub_id, m.last_name, m.first_name,
       DATE_FORMAT(m.dob, '%Y%m%d')      AS dob,
       m.sex, m.rel, m.grp_id, m.plan_id, m.cov_tier,
       DATE_FORMAT(m.eff_date, '%Y%m%d') AS eff_date,
       IFNULL(DATE_FORMAT(m.term_date, '%Y%m%d'), '99991231') AS term_date,
       CASE WHEN m.term_date IS NULL THEN '99991231'
            WHEN m.term_rsn = 'NP'
                 THEN DATE_FORMAT(DATE_ADD(m.term_date, INTERVAL 31 DAY), '%Y%m%d')
            ELSE DATE_FORMAT(m.term_date, '%Y%m%d') END AS cov_thru,
       m.term_rsn, m.stud_flag, m.other_ins, m.life_used,
       IFNULL(g.lives, 0) AS grp_lives
  FROM member m LEFT JOIN grp g ON g.grp_id = m.grp_id;

-- duplicate check view.  Void lines never count as "on file".  CR-2610
CREATE VIEW v_dupchk AS
SELECT clm_seq, claim_no, line_no, member_id, prov_id, svc_date,
       proc_code, status
  FROM claim
 WHERE status <> 'V';
