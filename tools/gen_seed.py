#!/usr/bin/env python3
"""Build db/seed.sql, the test-region snapshot of the CLAIMSYS database.

Everything is generated (names, people, providers, balances); nothing is
real data.  The output is deterministic: the random seed is fixed, so
re-running this script reproduces db/seed.sql byte for byte.  The snapshot
is frozen around the control-card business date 2024-10-15.

usage: gen_seed.py [--out db/seed.sql]
"""
import argparse
import random
from datetime import date, timedelta

SEED = 20040301
SNAP = date(2024, 10, 15)

# plan_id, name, ind_ded, fam_ded, ind_oop, fam_oop, ded_type, copay_oop, ucr_pct, life_max
PLANS = [
    ("PG1", "PPO GOLD",     50000, 100000, 300000,  600000, "E", "N", 140, 200000000),
    ("PS1", "PPO SILVER",  100000, 200000, 500000, 1000000, "E", "Y", 125, 200000000),
    ("HD1", "HDHP SAVER",  250000, 500000, 600000, 1200000, "A", "Y", 125, 150000000),
    ("HM1", "HMO SELECT",       0,      0, 250000,  500000, "E", "Y", 100, 200000000),
    ("BZ1", "BRONZE BASIC", 500000, 1000000, 750000, 1500000, "E", "N", 110, 100000000),
]
PLAN_WEIGHTS = [30, 30, 15, 15, 10]

# class: (covered, copay, ded_appl, inn_pct, oon_pct, wait_days, ann_max) per plan
BNFT = {
    "PG1": {
        "OV": ("Y", 2500, "N", 100, 70, 0, 0),   "SP": ("Y", 4000, "N", 100, 70, 0, 0),
        "PV": ("Y", 0, "N", 100, 70, 0, 0),      "ER": ("Y", 15000, "N", 100, 100, 0, 0),
        "IP": ("Y", 0, "Y", 80, 60, 0, 0),       "OS": ("Y", 0, "Y", 80, 60, 0, 0),
        "LB": ("Y", 0, "N", 100, 70, 0, 0),      "RD": ("Y", 0, "Y", 80, 60, 0, 0),
        "MT": ("Y", 0, "Y", 80, 60, 270, 0),     "MH": ("Y", 2500, "N", 100, 70, 0, 0),
        "CH": ("Y", 2000, "N", 100, 50, 0, 100000), "PT": ("Y", 2000, "N", 100, 70, 0, 0),
        "DN": ("Y", 0, "N", 80, 80, 0, 100000),  "DM": ("Y", 0, "Y", 50, 50, 180, 150000),
        "VS": ("Y", 1000, "N", 100, 100, 0, 30000), "DE": ("Y", 0, "Y", 80, 60, 0, 250000),
    },
    "PS1": {
        "OV": ("Y", 3500, "N", 100, 60, 0, 0),   "SP": ("Y", 5000, "N", 80, 60, 0, 0),
        "PV": ("Y", 0, "N", 100, 60, 0, 0),      "ER": ("Y", 25000, "Y", 80, 80, 0, 0),
        "IP": ("Y", 0, "Y", 70, 50, 0, 0),       "OS": ("Y", 0, "Y", 70, 50, 0, 0),
        "LB": ("Y", 0, "Y", 80, 60, 0, 0),       "RD": ("Y", 0, "Y", 70, 50, 0, 0),
        "MT": ("Y", 0, "Y", 70, 50, 270, 0),     "MH": ("Y", 3500, "N", 100, 60, 0, 0),
        "CH": ("Y", 3000, "N", 100, 50, 0, 75000), "PT": ("Y", 3000, "N", 100, 60, 0, 0),
        "DN": ("Y", 0, "N", 80, 80, 0, 100000),  "DM": ("Y", 0, "Y", 50, 50, 365, 100000),
        "VS": ("Y", 1500, "N", 100, 100, 0, 20000), "DE": ("Y", 0, "Y", 70, 50, 0, 200000),
    },
    "HD1": {
        "OV": ("Y", 0, "Y", 80, 60, 0, 0),       "SP": ("Y", 0, "Y", 80, 60, 0, 0),
        "PV": ("Y", 0, "N", 100, 60, 0, 0),      "ER": ("Y", 0, "Y", 80, 80, 0, 0),
        "IP": ("Y", 0, "Y", 80, 60, 0, 0),       "OS": ("Y", 0, "Y", 80, 60, 0, 0),
        "LB": ("Y", 0, "Y", 80, 60, 0, 0),       "RD": ("Y", 0, "Y", 80, 60, 0, 0),
        "MT": ("Y", 0, "Y", 80, 60, 270, 0),     "MH": ("Y", 0, "Y", 80, 60, 0, 0),
        "CH": ("Y", 0, "Y", 80, 50, 0, 60000),   "PT": ("Y", 0, "Y", 80, 60, 0, 0),
        "DN": ("N", 0, "N", 0, 0, 0, 0),         "DM": ("N", 0, "N", 0, 0, 0, 0),
        "VS": ("N", 0, "N", 0, 0, 0, 0),         "DE": ("Y", 0, "Y", 80, 60, 0, 250000),
    },
    "HM1": {
        "OV": ("Y", 2000, "N", 100, 0, 0, 0),    "SP": ("Y", 3500, "N", 100, 0, 0, 0),
        "PV": ("Y", 0, "N", 100, 0, 0, 0),       "ER": ("Y", 10000, "N", 100, 0, 0, 0),
        "IP": ("Y", 50000, "N", 100, 0, 0, 0),   "OS": ("Y", 25000, "N", 100, 0, 0, 0),
        "LB": ("Y", 0, "N", 100, 0, 0, 0),       "RD": ("Y", 5000, "N", 100, 0, 0, 0),
        "MT": ("Y", 25000, "N", 100, 0, 180, 0), "MH": ("Y", 2000, "N", 100, 0, 0, 0),
        "CH": ("Y", 2000, "N", 100, 0, 0, 50000), "PT": ("Y", 2000, "N", 100, 0, 0, 0),
        "DN": ("Y", 0, "N", 80, 0, 0, 75000),    "DM": ("Y", 0, "N", 50, 0, 180, 100000),
        "VS": ("Y", 1000, "N", 100, 0, 0, 25000), "DE": ("Y", 0, "N", 80, 0, 0, 150000),
    },
    "BZ1": {
        "OV": ("Y", 5000, "N", 100, 50, 0, 0),   "SP": ("Y", 0, "Y", 60, 50, 0, 0),
        "PV": ("Y", 0, "N", 100, 50, 0, 0),      "ER": ("Y", 0, "Y", 60, 60, 0, 0),
        "IP": ("Y", 0, "Y", 60, 50, 0, 0),       "OS": ("Y", 0, "Y", 60, 50, 0, 0),
        "LB": ("Y", 0, "Y", 60, 50, 0, 0),       "RD": ("Y", 0, "Y", 60, 50, 0, 0),
        "MT": ("Y", 0, "Y", 60, 50, 365, 0),     "MH": ("Y", 5000, "N", 100, 50, 0, 0),
        "CH": ("N", 0, "N", 0, 0, 0, 0),         "PT": ("Y", 0, "Y", 60, 50, 0, 0),
        "DE": ("Y", 0, "Y", 60, 50, 0, 100000),
    },
}

# proc_code, descr, class, fee, preauth, sex_only, min_age, max_age
PROCS = [
    ("10010", "OFFICE VISIT - BRIEF", "OV", 6500, "N", "", 0, 999),
    ("10020", "OFFICE VISIT - STANDARD", "OV", 8500, "N", "", 0, 999),
    ("10030", "OFFICE VISIT - EXTENDED", "OV", 12500, "N", "", 0, 999),
    ("12010", "SPECIALIST CONSULTATION", "SP", 14000, "N", "", 0, 999),
    ("12020", "SPECIALIST FOLLOW-UP", "SP", 9500, "N", "", 0, 999),
    ("15010", "ADULT WELLNESS EXAM", "PV", 12000, "N", "", 18, 999),
    ("15020", "WELL-CHILD VISIT", "PV", 9000, "N", "", 0, 17),
    ("15030", "IMMUNISATION", "PV", 3500, "N", "", 0, 999),
    ("15040", "SCREENING MAMMOGRAM", "PV", 13500, "N", "F", 40, 999),
    ("15050", "PROSTATE SCREENING", "PV", 6000, "N", "M", 50, 999),
    ("15060", "COLON SCREENING", "PV", 42000, "N", "", 45, 999),
    ("20010", "EMERGENCY ROOM VISIT", "ER", 65000, "N", "", 0, 999),
    ("20020", "EMERGENCY ROOM VISIT - COMPLEX", "ER", 110000, "N", "", 0, 999),
    ("30010", "INPATIENT DAY - MEDICAL/SURGICAL", "IP", 420000, "Y", "", 0, 999),
    ("30020", "INPATIENT DAY - INTENSIVE CARE", "IP", 780000, "Y", "", 0, 999),
    ("35010", "OUTPATIENT SURGERY - MINOR", "OS", 180000, "N", "", 0, 999),
    ("35020", "OUTPATIENT SURGERY - MAJOR", "OS", 380000, "Y", "", 0, 999),
    ("35030", "ENDOSCOPY", "OS", 95000, "N", "", 0, 999),
    ("40010", "BLOOD PANEL - BASIC", "LB", 1800, "N", "", 0, 999),
    ("40020", "BLOOD PANEL - COMPREHENSIVE", "LB", 4500, "N", "", 0, 999),
    ("40030", "CULTURE", "LB", 3200, "N", "", 0, 999),
    ("40040", "PATHOLOGY EXAMINATION", "LB", 9500, "N", "", 0, 999),
    ("45010", "X-RAY", "RD", 11000, "N", "", 0, 999),
    ("45020", "ULTRASOUND", "RD", 24000, "N", "", 0, 999),
    ("45030", "CT SCAN", "RD", 68000, "N", "", 0, 999),
    ("45040", "MRI SCAN", "RD", 140000, "Y", "", 0, 999),
    ("50010", "PRENATAL VISIT", "MT", 13000, "N", "F", 12, 55),
    ("50020", "DELIVERY", "MT", 340000, "N", "F", 12, 55),
    ("50030", "POSTNATAL VISIT", "MT", 11000, "N", "F", 12, 55),
    ("55010", "THERAPY SESSION", "MH", 11500, "N", "", 0, 999),
    ("55020", "PSYCHIATRIC EVALUATION", "MH", 21000, "N", "", 0, 999),
    ("60010", "CHIROPRACTIC ADJUSTMENT", "CH", 4800, "N", "", 0, 999),
    ("62010", "PHYSICAL THERAPY SESSION", "PT", 7200, "N", "", 0, 999),
    ("62020", "PHYSICAL THERAPY EVALUATION", "PT", 12000, "N", "", 0, 999),
    ("70010", "DENTAL EXAM AND CLEANING", "DN", 9000, "N", "", 0, 999),
    ("70020", "DENTAL FILLING", "DN", 16000, "N", "", 0, 999),
    ("70030", "DENTAL X-RAY", "DN", 6500, "N", "", 0, 999),
    ("72010", "CROWN", "DM", 98000, "N", "", 0, 999),
    ("72020", "ROOT CANAL", "DM", 115000, "N", "", 0, 999),
    ("72030", "ORTHODONTIC ADJUSTMENT", "DM", 22000, "N", "", 0, 18),
    ("75010", "EYE EXAMINATION", "VS", 7500, "N", "", 0, 999),
    ("75020", "LENSES", "VS", 14000, "N", "", 0, 999),
    ("80010", "WHEELCHAIR", "DE", 60000, "N", "", 0, 999),
    ("80020", "CPAP DEVICE", "DE", 85000, "Y", "", 18, 999),
    ("80030", "CRUTCHES", "DE", 4500, "N", "", 0, 999),
    ("90010", "COSMETIC PROCEDURE", "CS", 250000, "N", "", 0, 999),
]

DIAGS = [
    ("G1000", "FEVER"), ("G1010", "FATIGUE"), ("G1020", "ABDOMINAL PAIN"),
    ("G1030", "HEADACHE"), ("G1040", "SKIN RASH"),
    ("R2000", "UPPER RESPIRATORY INFECTION"), ("R2010", "BRONCHITIS"),
    ("R2020", "ASTHMA"), ("R2030", "PNEUMONIA"),
    ("C3000", "HYPERTENSION"), ("C3010", "CHEST PAIN"), ("C3020", "ARRHYTHMIA"),
    ("E3500", "DIABETES"), ("E3510", "THYROID DISORDER"),
    ("M4000", "LOW BACK PAIN"), ("M4010", "NECK PAIN"), ("M4020", "JOINT PAIN"),
    ("M4030", "ARTHRITIS"),
    ("I5000", "FRACTURE"), ("I5010", "SPRAIN"), ("I5020", "LACERATION"),
    ("I5030", "CONCUSSION"),
    ("P6000", "PREGNANCY SUPERVISION"), ("P6010", "DELIVERY"), ("P6020", "POSTPARTUM CARE"),
    ("N7000", "ANXIETY"), ("N7010", "DEPRESSION"), ("N7020", "SLEEP DISORDER"),
    ("D8000", "DENTAL CARIES"), ("D8010", "GINGIVITIS"), ("D8020", "TOOTH FRACTURE"),
    ("D8030", "MALOCCLUSION"),
    ("V8500", "REFRACTIVE ERROR"), ("V8510", "EYE STRAIN"),
    ("W9000", "ROUTINE EXAMINATION"), ("W9010", "IMMUNISATION ENCOUNTER"),
    ("W9020", "SCREENING ENCOUNTER"),
    ("K9500", "COSMETIC ENCOUNTER"),
]

SUR_A = ["Ash", "Bram", "Cald", "Dun", "Elm", "Fair", "Glen", "Hart", "Ives", "Kes",
         "Lin", "Mar", "Nor", "Oak", "Pen", "Quill", "Rad", "Stan", "Thorn", "Ul",
         "Vane", "Wex", "Yar", "Brack", "Cor", "Hal", "Tarn", "Whit"]
SUR_B = ["wick", "ford", "ley", "mere", "stone", "worth", "field", "by", "ton",
         "dale", "holt", "croft", "combe", "well"]
FIRST_M = ["Adam", "Brian", "Colin", "Derek", "Edwin", "Felix", "Gavin", "Hugo", "Ian",
           "Jonas", "Karl", "Leon", "Miles", "Nolan", "Oscar", "Peter", "Rhys", "Simon",
           "Tobin", "Victor", "Wade", "Ezra"]
FIRST_F = ["Alma", "Beth", "Clara", "Dora", "Elise", "Fern", "Greta", "Hazel", "Iris",
           "Joan", "Kira", "Lena", "Mira", "Nora", "Opal", "Paula", "Ruth", "Selma",
           "Tessa", "Una", "Vera", "Willa"]
TOWNS = ["Tarrow", "Ebbfleet", "Marlow Cross", "Dunmere", "Quarry Hill", "Westholt",
         "Ashby Vale", "Pennock", "Larkfield", "Stonebridge", "Calder", "Wrenbury"]

# provider type: (count, name pattern)
PTYPES = [
    ("MD", 110, "{s} FAMILY PRACTICE"), ("SP", 40, "{s} SPECIALTY CLINIC"),
    ("HO", 25, "{t} GENERAL HOSPITAL"), ("LB", 15, "{t} CLINICAL LABORATORY"),
    ("RD", 15, "{t} IMAGING CENTRE"), ("DC", 15, "{s} CHIROPRACTIC"),
    ("PT", 15, "{s} PHYSICAL THERAPY"), ("DD", 30, "{s} DENTAL CARE"),
    ("OD", 15, "{s} EYE CARE"), ("MH", 12, "{s} COUNSELLING"),
    ("DE", 8, "{t} MEDICAL SUPPLY"),
]


def q(v):
    if v is None:
        return "NULL"
    if isinstance(v, int):
        return str(v)
    if isinstance(v, date):
        return "'" + v.isoformat() + "'"
    return "'" + str(v).replace("'", "''") + "'"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="db/seed.sql")
    args = ap.parse_args()
    rnd = random.Random(SEED)
    out = []
    tables = {}

    def ins(table, *vals):
        tables.setdefault(table, []).append("INSERT INTO %s VALUES (%s);" % (table, ", ".join(q(v) for v in vals)))

    def surname():
        return (rnd.choice(SUR_A) + rnd.choice(SUR_B)).upper()

    ins("sysctl", "NEXT_CHECK_NO", "500001")
    ins("sysctl", "SEED_VERSION", "2024.10")
    ins("sysctl", "TEST_REGION", "Y")

    # ---- groups ------------------------------------------------------
    sizes = [49, 50, 12, 8, 25, 38, 51, 75, 120, 300, 5, 18, 44, 60, 210]
    groups = []
    for i in range(1, 41):
        lives = sizes[i - 1] if i <= len(sizes) else rnd.choice([6, 15, 30, 45, 49, 50, 80, 150, 400])
        gid = "G%05d" % i
        groups.append(gid)
        ins("grp", gid, "%s %s" % (surname(), rnd.choice(["HOLDINGS", "AND SONS", "LOGISTICS", "MILLS", "FOODS", "SUPPLY CO", "PARTNERS"])),
            lives, date(rnd.randint(2004, 2018), rnd.randint(1, 12), 1))

    for p in PLANS:
        ins("plan", *p)
    for pid, classes in BNFT.items():
        for cls, b in classes.items():
            ins("bnft", pid, cls, *b)
    for p in PROCS:
        ins("proccode", *p)
    for d in DIAGS:
        ins("diagcode", *d)

    # ---- providers ---------------------------------------------------
    provs = []
    n = 0
    for ptype, cnt, pat in PTYPES:
        for _ in range(cnt):
            n += 1
            pid = "P%06d" % n
            net = "I" if rnd.random() < 0.80 else "O"
            pct = rnd.choice([85, 90, 95, 100, 100, 105, 110]) if net == "I" else 100
            status, sdate = "A", None
            r = rnd.random()
            if r < 0.02:
                status, sdate = "S", date(2024, rnd.randint(6, 9), rnd.randint(1, 28))
            elif r < 0.045:
                status = "X"
                sdate = date(2024, rnd.randint(3, 9), rnd.randint(1, 28)) if rnd.random() < 0.7 else date(2022, 5, 1)
            lic = date(rnd.randint(2025, 2027), rnd.randint(1, 12), 28)
            if rnd.random() < 0.04:
                lic = date(2024, rnd.randint(4, 9), 30)
            carry = 0
            r = rnd.random()
            if r < 0.12:
                carry = rnd.randint(50, 950)
            elif r < 0.16:
                carry = -rnd.randint(2000, 60000)
            name = pat.format(s=surname(), t=rnd.choice(TOWNS).upper())
            provs.append((pid, ptype, net, status))
            ins("provider", pid, name, ptype, net, pct, status, sdate, lic, carry)

    # ---- members -----------------------------------------------------
    plan_ids = [p[0] for p in PLANS]
    plan_by = {p[0]: p for p in PLANS}
    members = []          # (member_id, sub_id, plan, tier, dob, sex, rel, eff, term)
    fam_tot = {}

    def first_of_month(y1, m1, y2, m2):
        k = rnd.randint(y1 * 12 + m1 - 1, y2 * 12 + m2 - 1)
        return date(k // 12, k % 12 + 1, 1)

    for i in range(1, 1001):
        sub = "%07d" % (1000000 + i)
        plan = rnd.choices(plan_ids, PLAN_WEIGHTS)[0]
        grp = rnd.choice(groups)
        tier = "F" if rnd.random() < 0.65 else "S"
        last = surname()
        r = rnd.random()
        if r < 0.70:
            eff = first_of_month(2012, 1, 2023, 6)
        elif r < 0.85:
            eff = first_of_month(2023, 7, 2024, 1)
        elif r < 0.95:
            eff = first_of_month(2024, 2, 2024, 9)
        elif r < 0.98:
            eff = date(2024, 10, 1)
        else:
            eff = rnd.choice([date(2024, 11, 1), date(2025, 1, 1)])
        term, rsn = None, ""
        if rnd.random() < 0.06 and eff < date(2024, 4, 1):
            term = rnd.choice([date(2024, 5, 31), date(2024, 6, 30), date(2024, 7, 31), date(2024, 8, 31),
                               date(2024, 9, 14), date(2024, 9, 30), date(2024, 10, 5)])
            rsn = rnd.choices(["NP", "VL", "EM"], [50, 25, 25])[0]
        people = []
        s_sex = rnd.choice("MF")
        s_dob = date(rnd.randint(1956, 2002), rnd.randint(1, 12), rnd.randint(1, 28))
        if rnd.random() < 0.03:
            s_dob = date(rnd.randint(1950, 1959), 10, rnd.randint(10, 20))
        people.append(("00", "S", s_sex, s_dob, "N"))
        if tier == "F":
            if rnd.random() < 0.75:
                p_sex = ("F" if s_sex == "M" else "M") if rnd.random() < 0.92 else s_sex
                p_dob = date(min(2003, max(1950, s_dob.year + rnd.randint(-6, 6))), rnd.randint(1, 12), rnd.randint(1, 28))
                people.append(("01", "P", p_sex, p_dob, "N"))
            for k in range(rnd.choice([0, 1, 1, 2, 2, 3])):
                age = rnd.choice(list(range(0, 18)) + [18, 18, 19, 19, 19, 20, 21, 22, 23, 23, 24, 24, 25])
                c_dob = date(2024 - age, rnd.randint(1, 12), rnd.randint(1, 28))
                if age in (19, 24) and rnd.random() < 0.5:
                    c_dob = date(2024 - age, rnd.choice([9, 10, 11]), rnd.choice([1, 14, 15, 16, 28]))
                flag = "N"
                if age >= 19:
                    flag = rnd.choices(["Y", "N", "D"], [50, 42, 8])[0]
                people.append(("%02d" % (len(people) + 1 if len(people) > 1 else 2 + k), "C", rnd.choice("MF"), c_dob, flag))
        # make person suffixes unique and ordered
        fixed = []
        nxt = 2
        for suf, rel, sex, dob, flag in people:
            if rel == "C":
                suf = "%02d" % nxt
                nxt += 1
            fixed.append((suf, rel, sex, dob, flag))
        for suf, rel, sex, dob, flag in fixed:
            mid = sub + suf
            m_eff = eff
            if rel == "C" and dob > eff:
                m_eff = dob
            first = rnd.choice(FIRST_M if sex == "M" else FIRST_F).upper()
            oth = "Y" if rnd.random() < 0.06 else "N"
            life_max = plan_by[plan][9]
            r = rnd.random()
            if r < 0.006:
                life = life_max
            elif r < 0.016:
                life = life_max - rnd.randint(1000, 300000)
            else:
                life = rnd.randint(0, 5000000) if rnd.random() < 0.7 else 0
            ins("member", mid, sub, last, first, dob, sex, rel, grp, plan, tier, m_eff, term, rsn, flag, oth, life)
            members.append((mid, sub, plan, tier, dob, sex, rel, m_eff, term))
            # 2024 accumulators
            if rnd.random() < 0.60:
                p = plan_by[plan]
                ded = 0
                if p[2] > 0:
                    ded = p[2] if rnd.random() < 0.20 else rnd.randint(0, p[2])
                oop = ded + rnd.randint(0, max(0, (p[4] - ded) // 4))
                if rnd.random() < 0.03:
                    oop = p[4]
                elif rnd.random() < 0.03:
                    oop = p[4] - rnd.randint(1, 5000)
                oop = min(oop, p[4])
                ins("accum", mid, 2024, ded, oop)
                if tier == "F":
                    t = fam_tot.setdefault(sub, [plan, 0, 0])
                    t[1] += ded
                    t[2] += oop
            # benefit maximum accumulators
            if rnd.random() < 0.15:
                maxed = [(c, b[6]) for c, b in BNFT[plan].items() if b[6] > 0]
                if maxed:
                    cls, mx = rnd.choice(maxed)
                    r = rnd.random()
                    used = mx if r < 0.12 else (mx - rnd.randint(1, 5000) if r < 0.30 else rnd.randint(0, mx))
                    ins("bnaccum", mid, 2024, cls, used)
    for sub in sorted(fam_tot):
        plan, ded, oop = fam_tot[sub]
        p = plan_by[plan]
        ins("famaccum", sub, 2024, min(ded, p[3]), min(oop, p[5]))

    # ---- pre-authorisations -----------------------------------------
    pa_procs = [p[0] for p in PROCS if p[4] == "Y"]
    big_procs = ["35010", "20020", "50020", "72020", "45030", "72010"]
    active = [m for m in members if m[8] is None and m[7] < date(2024, 6, 1)]
    for i in range(1, 501):
        m = rnd.choice(active)
        proc = rnd.choice(pa_procs) if rnd.random() < 0.65 else rnd.choice(big_procs)
        r = rnd.random()
        if r < 0.80:
            frm = date(2024, rnd.randint(4, 9), rnd.choice([1, 10, 20]))
            to = frm + timedelta(days=rnd.choice([60, 90, 120, 180]))
        else:
            frm = date(2024, rnd.randint(1, 3), 1)
            to = frm + timedelta(days=rnd.choice([30, 60, 90]))
        status = "A" if rnd.random() < 0.92 else "C"
        ins("preauth", "PA%08d" % (20240000 + i), m[0], proc, frm, to, status)

    # ---- claim history ----------------------------------------------
    proc_by_class = {}
    for p in PROCS:
        proc_by_class.setdefault(p[2], []).append(p)
    type_classes = {"MD": ["OV", "OV", "PV", "LB"], "SP": ["SP"], "HO": ["ER", "RD", "OS"], "LB": ["LB"],
                    "RD": ["RD"], "DC": ["CH"], "PT": ["PT"], "DD": ["DN"], "OD": ["VS"], "MH": ["MH"],
                    "DE": ["DE"]}
    diag_for = {"PV": "W9000", "CH": "M4000", "PT": "M4020", "DN": "D8000", "VS": "V8500", "MH": "N7000"}
    good_provs = [p for p in provs if p[3] == "A"]
    for i in range(1, 901):
        m = rnd.choice(active)
        pv = rnd.choice(good_provs)
        cls = rnd.choice(type_classes[pv[1]])
        if cls not in BNFT[m[2]] or BNFT[m[2]][cls][0] != "Y":
            cls = "OV"
        cands = [p for p in proc_by_class[cls] if p[4] == "N" and p[5] == "" and p[6] == 0 and p[7] == 999]
        pr = rnd.choice(cands)
        svc = date(2024, 6, 1) + timedelta(days=rnd.randint(0, 130))
        rcv = min(svc + timedelta(days=rnd.randint(3, 20)), SNAP - timedelta(days=1))
        billed = pr[3] * rnd.choice([110, 125, 150, 180]) // 100
        st = rnd.choices(["A", "D", "P", "V"], [80, 12, 4, 4])[0]
        b = BNFT[m[2]][cls]
        allowed = copay = pay = 0
        reason = ""
        if st in ("A", "V"):
            allowed = pr[3]
            copay = min(b[1], allowed)
            pay = (allowed - copay) * b[3] // 100
        elif st == "D":
            reason = rnd.choice(["401", "605", "801", "303"])
        else:
            reason = "901"
        ins("claim", 0, "H%s%05d" % (rcv.strftime("%y%m%d"), i), 1, m[0], pv[0], svc.strftime("%Y%m%d"),
            rcv.strftime("%Y%m%d"), pr[0], diag_for.get(cls, "G1000"), 1, billed, allowed, copay, 0,
            allowed - copay - pay, pay, 0, st, reason, "H")

    order = ["sysctl", "grp", "plan", "bnft", "proccode", "diagcode", "provider", "member", "accum",
             "famaccum", "bnaccum", "preauth", "claim"]
    out.append("-- CLAIMSYS test-region snapshot.  Generated by tools/gen_seed.py (seed %d)." % SEED)
    out.append("-- Synthetic data only.  Loaded by run/RUNADJUD.sh STEP010 after schema.sql.")
    for t in order:
        out.append("")
        out.append("LOCK TABLES %s WRITE;" % t)
        out.extend(tables[t])
        out.append("UNLOCK TABLES;")
    with open(args.out, "w", newline="\n") as f:
        f.write("\n".join(out) + "\n")
    print("wrote %s: %s" % (args.out, ", ".join("%s=%d" % (t, len(tables[t])) for t in order)))


if __name__ == "__main__":
    main()
