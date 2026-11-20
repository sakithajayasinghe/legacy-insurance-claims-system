#!/usr/bin/env python3
"""Generate a synthetic day of claims (CLAIMS.DAT) for the CLAIMSYS batch.

Claims are built against the members, providers, plans, procedures and
pre-authorisations in db/seed.sql, so the file only makes sense together
with that snapshot.  Most claims are ordinary payable ones; the rest are
spread over every reject, denial and pend situation, boundary dates and
amounts, duplicates, corrected claims, and a few families that are driven
through their deductible and out-of-pocket maximum during the run.

Fixed-width, 120 bytes per record plus newline:
   1-2   record type 'CL'        49-53  procedure code
   3-14  claim number            54-58  diagnosis code
  15-16  line number             59-61  units
  17-25  member id               62-70  billed amount (cents)
  26-32  provider id             71     submit type O / R (corrected)
  33-40  service date YYYYMMDD   72-83  original claim number
  41-48  received date YYYYMMDD  84-93  pre-authorisation number
                                 94     emergency Y/N
                                 95     other insurance Y/N
                                 96-104 other insurance paid (cents)
                                 105    source E (EDI) / P (paper)
                                 106-120 filler

usage: gen_claims.py --count N --seed S --out FILE [--busdate YYYYMMDD]
Deterministic for a given seed, count and business date.
"""
import argparse
import ast
import os
import random
import re
from datetime import date, timedelta

HERE = os.path.dirname(os.path.abspath(__file__))

TYPE_CLASSES = {
    "MD": ["OV", "OV", "OV", "PV", "LB", "MT"], "SP": ["SP", "SP", "OS"],
    "HO": ["ER", "ER", "RD", "OS", "MT", "LB"], "LB": ["LB"], "RD": ["RD"], "DC": ["CH"],
    "PT": ["PT"], "DD": ["DN", "DN", "DM"], "OD": ["VS"], "MH": ["MH"], "DE": ["DE"],
}
TYPE_WEIGHTS = {"MD": 34, "SP": 10, "HO": 14, "LB": 8, "RD": 6, "DC": 5, "PT": 5, "DD": 9, "OD": 4, "MH": 4, "DE": 2}
DX = {"MT": ["P6000", "P6010", "P6020"], "DN": ["D8000", "D8010", "D8020"], "DM": ["D8000", "D8020", "D8030"],
      "VS": ["V8500", "V8510"], "MH": ["N7000", "N7010", "N7020"], "PV": ["W9000", "W9010", "W9020"],
      "CH": ["M4000", "M4010", "I5010"], "PT": ["M4020", "M4030", "I5000", "I5010"]}
CLASS_TYPE = {"OV": "MD", "SP": "SP", "PV": "MD", "ER": "HO", "IP": "HO", "OS": "HO", "LB": "LB", "RD": "RD",
              "MT": "MD", "MH": "MH", "CH": "DC", "PT": "PT", "DN": "DD", "DM": "DD", "VS": "OD", "DE": "DE"}
DX_ANY = ["G1000", "G1010", "G1020", "G1030", "G1040", "R2000", "R2010", "R2020", "R2030", "C3000",
          "C3010", "C3020", "E3500", "E3510", "M4000", "M4020", "I5000", "I5020", "I5030"]


def ymd(d):
    return d.strftime("%Y%m%d")


def pdate(s):
    return None if s is None else date(int(s[0:4]), int(s[5:7]), int(s[8:10]))


def load_seed(path):
    t = {}
    pat = re.compile(r"^INSERT INTO (\w+) VALUES \((.*)\);$")
    with open(path) as f:
        for line in f:
            m = pat.match(line.rstrip("\n"))
            if m:
                row = ast.literal_eval("(" + re.sub(r"\bNULL\b", "None", m.group(2)) + ",)")
                t.setdefault(m.group(1), []).append(row)
    return t


def age_on(dob, d):
    return d.year - dob.year - ((d.month, d.day) < (dob.month, dob.day))


class Gen:
    def __init__(self, seed_tables, rnd, bus):
        self.rnd = rnd
        self.bus = bus
        t = seed_tables
        self.plans = {r[0]: r for r in t["plan"]}
        self.bn = {(r[0], r[1]): r for r in t["bnft"]}
        self.procs = {r[0]: r for r in t["proccode"]}
        self.by_class = {}
        for r in t["proccode"]:
            self.by_class.setdefault(r[2], []).append(r)
        self.grp = {r[0]: r[2] for r in t["grp"]}
        self.members = []
        for r in t["member"]:
            self.members.append({"id": r[0], "sub": r[1], "dob": pdate(r[4]), "sex": r[5], "rel": r[6],
                                 "grp": r[7], "plan": r[8], "tier": r[9], "eff": pdate(r[10]),
                                 "term": pdate(r[11]), "rsn": r[12], "stud": r[13], "oth": r[14],
                                 "life": r[15]})
        self.mby = {m["id"]: m for m in self.members}
        self.provs = []
        for r in t["provider"]:
            self.provs.append({"id": r[0], "type": r[2], "net": r[3], "status": r[5],
                               "sdate": pdate(r[6]), "lic": pdate(r[7])})
        self.preauth = [{"no": r[0], "member": r[1], "proc": r[2], "frm": pdate(r[3]), "to": pdate(r[4]),
                         "status": r[5]} for r in t["preauth"]]
        self.hist = [{"claim": r[1], "member": r[3], "prov": r[4], "svc": r[5], "rcv": r[6], "proc": r[7],
                      "diag": r[8], "billed": r[10], "status": r[17]} for r in t["claim"]]
        self.bnacc = [(r[0], r[2], r[3]) for r in t["bnaccum"]]

        cutoff = bus - timedelta(days=75)
        self.good = [m for m in self.members if m["term"] is None and m["eff"] <= cutoff
                     and self.child_ok(m, bus) and m["life"] < self.plans[m["plan"]][9] - 5000000]
        self.good_ids = {m["id"] for m in self.good}
        self.good_prov = [p for p in self.provs if p["status"] == "A" and p["lic"] >= bus]
        self.prov_by_type = {}
        for i, p in enumerate(self.good_prov):
            # the last fifth of the provider file is rarely used: small cheques
            w = 1 if int(p["id"][1:]) % 5 else 0.04
            self.prov_by_type.setdefault(p["type"], []).append((p, w))
        subs = sorted({m["sub"] for m in self.good if m["tier"] == "F"})
        self.hot = set(rnd.sample(subs, min(40, len(subs))))
        self.hot_members = [m for m in self.good if m["sub"] in self.hot]
        self.cache = {}
        self.claim_seq = 0
        self.emitted = []      # earlier ordinary records, for duplicates
        self.later = []        # (due record count, record) corrected claims waiting

    # ---- helpers -----------------------------------------------------
    def memo(self, name, fn):
        if name not in self.cache:
            self.cache[name] = fn()
        return self.cache[name]

    def child_ok(self, m, d):
        if m["rel"] != "C" or m["stud"] == "D":
            return True
        if m["stud"] == "Y":
            return age_on(m["dob"], d) < 24
        return (d.year, d.month) <= (m["dob"].year + 19, m["dob"].month)

    def wait_cands(self):
        cands = []
        for m in self.members:
            if m["term"] is not None or m["eff"] > self.bus - timedelta(days=30) or not self.child_ok(m, self.bus):
                continue
            for cls in ("MT", "DM"):
                b = self.bn.get((m["plan"], cls))
                if b and b[2] == "Y" and b[7] > 0 and (self.bus - m["eff"]).days < b[7] + 120:
                    if cls == "MT" and (m["sex"] != "F" or not 12 <= age_on(m["dob"], self.bus) <= 55):
                        continue
                    cands.append((m, cls, b[7]))
        return cands

    def new_claim_no(self):
        self.claim_seq += 1
        return "E%s%06d" % (self.bus.strftime("%y%j"), self.claim_seq)

    def pick_prov(self, ptype=None, net=None):
        rnd = self.rnd
        for _ in range(50):
            pt = ptype or rnd.choices(list(TYPE_WEIGHTS), list(TYPE_WEIGHTS.values()))[0]
            cands = self.prov_by_type.get(pt, [])
            if net:
                cands = [c for c in cands if c[0]["net"] == net]
            if cands:
                return rnd.choices([c[0] for c in cands], [c[1] for c in cands])[0]
        return self.good_prov[0]

    def pick_proc(self, m, cls, svc, allow_pa=False):
        age = age_on(m["dob"], svc)
        cands = [p for p in self.by_class.get(cls, []) if (p[5] == "" or p[5] == m["sex"])
                 and p[6] <= age <= p[7] and (allow_pa or p[4] == "N")]
        return self.rnd.choice(cands) if cands else None

    def covered(self, m, cls, svc, prov):
        b = self.bn.get((m["plan"], cls))
        if not b or b[2] != "Y":
            return False
        if prov["net"] != "I" and b[6] == 0:
            return False
        if b[7] > 0 and self.grp.get(m["grp"], 0) < 50 and (svc - m["eff"]).days < b[7]:
            return False
        return True

    def base(self, m=None, prov=None, cls=None, svc=None, proc=None):
        """An ordinary, normally payable claim line as a dict of field strings."""
        rnd = self.rnd
        pr = None
        for _ in range(200):
            mm = m or rnd.choice(self.good)
            want_net = "I" if (mm["plan"] == "HM1" or rnd.random() < 0.86) else None
            pv = prov or self.pick_prov(CLASS_TYPE.get(cls), want_net)
            sv = svc or self.bus - timedelta(days=rnd.randint(1, 40))
            if sv < mm["eff"]:
                sv = mm["eff"]
            if proc:
                pr = proc
                break
            cl = cls or rnd.choice(TYPE_CLASSES[pv["type"]])
            if not self.covered(mm, cl, sv, pv):
                continue
            pr = self.pick_proc(mm, cl, sv)
            if pr:
                break
        if pr is None:
            pr = self.procs["10020"]            # plain office visit
        cl = pr[2]
        units = 1
        if cl == "LB":
            units = rnd.choice([1, 1, 1, 2, 3])
        f = rnd.uniform(0.6, 0.95) if rnd.random() < 0.08 else rnd.uniform(1.0, 2.2)
        billed = max(100, int(pr[3] * units * f))
        if billed > 500000 and pr[4] == "N":
            billed = rnd.randint(420000, 500000)
        rcv = min(sv + timedelta(days=rnd.randint(0, 12)), self.bus)
        if svc is None and rnd.random() < 0.06:
            # backlog: received over a month before the run date
            rcv2 = self.bus - timedelta(days=rnd.randint(31, 100))
            sv2 = rcv2 - timedelta(days=rnd.randint(0, 20))
            if sv2 >= mm["eff"] and self.child_ok(mm, sv2):
                sv, rcv = sv2, rcv2
        diag = rnd.choice(DX.get(cl, DX_ANY))
        if cl == "CS":
            diag = "K9500"
        c = {"type": "CL", "claim": self.new_claim_no(), "line": "01", "member": mm["id"], "prov": pv["id"],
             "svc": ymd(sv), "rcv": ymd(rcv), "proc": pr[0], "diag": diag, "units": "%03d" % units,
             "billed": "%09d" % billed, "sub": "O", "orig": "", "auth": "",
             "emerg": "Y" if (cl == "ER" and rnd.random() < 0.8) else "N", "cob": "N", "cobpaid": "000000000",
             "src": "E" if rnd.random() < 0.9 else "P", "_m": mm, "_p": pv, "_svc": sv, "_cls": cl, "_fee": pr[3]}
        if mm["oth"] == "Y" or rnd.random() < 0.02:
            c["cob"] = "Y"
            c["cobpaid"] = "%09d" % int(pr[3] * units * rnd.choice([0, 0.2, 0.5, 0.8, 1.0, 1.3]))
        return c

    def set_dates(self, c, svc, rcv=None):
        c["svc"] = ymd(svc)
        c["_svc"] = svc
        if rcv is None:
            rcv = min(svc + timedelta(days=self.rnd.randint(0, 8)), self.bus)
        c["rcv"] = ymd(rcv)

    def with_auth(self, big=False):
        """A line that needs a pre-authorisation and quotes a good one."""
        rnd = self.rnd
        for _ in range(300):
            pa = rnd.choice(self.preauth)
            m = self.mby[pa["member"]]
            pr = self.procs[pa["proc"]]
            if pa["status"] != "A" or m["id"] not in self.good_ids:
                continue
            lo = max(pa["frm"], self.bus - timedelta(days=150), m["eff"])
            hi = min(pa["to"], self.bus - timedelta(days=1))
            if lo > hi:
                continue
            svc = lo + timedelta(days=rnd.randint(0, (hi - lo).days))
            pv = self.pick_prov("HO", "I")
            if not self.covered(m, pr[2], svc, pv):
                continue
            age = age_on(m["dob"], svc)
            if (pr[5] and pr[5] != m["sex"]) or not (pr[6] <= age <= pr[7]):
                continue
            c = self.base(m=m, prov=pv, svc=svc, proc=pr)
            self.set_dates(c, svc)
            units = 1
            if pr[2] == "IP":
                units = rnd.choice([4, 5, 6]) if big else rnd.choice([1, 1, 2])
            c["units"] = "%03d" % units
            c["billed"] = "%09d" % int(pr[3] * units * rnd.uniform(1.4, 1.9))
            if pr[4] == "N":
                c["billed"] = "%09d" % rnd.randint(500001, 900000)
            c["auth"] = pa["no"]
            c["emerg"] = "N"
            c["_pa"] = pa
            return c
        return self.base()

    # ---- scenarios ---------------------------------------------------
    def scenario(self, name):
        rnd = self.rnd
        bus = self.bus
        one = timedelta(days=1)

        if name == "normal":
            return self.base()
        if name == "hot":
            m = rnd.choice(self.hot_members)
            cls = rnd.choice(["OS", "RD", "ER", "SP", "OV", "LB", "RD", "OS"])
            return self.base(m=m, cls=cls, prov=self.pick_prov("HO" if cls in ("OS", "RD", "ER") else CLASS_TYPE[cls], "I"))
        if name == "auth_ok":
            return self.with_auth()
        if name == "hidollar":
            return self.with_auth(big=True)
        if name == "units0":
            c = self.base()
            c["units"] = "000"
            return c
        if name == "year2023":
            m = rnd.choice(self.memo("y23", lambda: [x for x in self.good if x["eff"] < date(bus.year - 1, 10, 1) and x["plan"] != "HM1"]))
            svc = date(bus.year - 1, 12, 31) - timedelta(days=rnd.randint(0, 45))
            if (bus - svc).days > 360:
                svc = bus - timedelta(days=360)
            c = self.base(m=m, prov=self.pick_prov("MD", "O"), svc=svc)
            self.set_dates(c, svc, bus - timedelta(days=rnd.randint(0, 3)))
            return c

        # ---- record format rejects ----
        if name == "f101":
            c = self.base()
            k = rnd.choice(["short", "long", "type", "claim", "blank"])
            if k == "type":
                c["type"] = rnd.choice(["XX", "HD", "cl", "  "])
            elif k == "claim":
                c["claim"] = rnd.choice(["", "E24 0001"])
            else:
                c["_raw"] = k
            return c
        if name == "f103":
            c = self.base()
            c["line"] = rnd.choice(["00", "13", "99", "A1", " 1"])
            return c
        if name == "f104":
            c = self.base()
            k = rnd.choice(["units", "billed", "cobpaid"])
            c[k] = rnd.choice(["ABC", "1 2", "-01", "1.5"]).ljust(len(c[k]), "0")[:len(c[k])]
            return c
        if name == "f105":
            c = self.base()
            c["svc"] = rnd.choice(["20240230", "20241301", "20230229", "2024101A", "00000000", "20240631", "        "])
            return c
        if name == "f106":
            c = self.base()
            c["rcv"] = rnd.choice(["20240931", "20241032", "ABCDEFGH", "20240000", "        "])
            return c
        if name == "f107":
            c = self.base()
            svc = bus + timedelta(days=rnd.choice([1, 1, 2, 30]))
            c["svc"] = ymd(svc)
            c["rcv"] = ymd(svc)
            return c
        if name == "f108":
            c = self.base()
            if rnd.random() < 0.5:
                c["rcv"] = ymd(c["_svc"] - timedelta(days=rnd.choice([1, 1, 7])))
            else:
                c["rcv"] = ymd(bus + timedelta(days=rnd.choice([1, 2])))
            return c
        if name == "f109":
            c = self.base()
            c["billed"] = "000000000"
            return c
        if name == "edge_dates":
            # service and received on the run date, or a leap day service
            c = self.base()
            if rnd.random() < 0.6:
                self.set_dates(c, bus, bus)
                return c
            if bus.year % 4 == 0:
                leap = date(bus.year, 2, 29)
                cands = [m for m in self.good if m["eff"] <= leap and m["plan"] != "HM1" and self.child_ok(m, leap)]
                if cands and 0 < (bus - leap).days <= 360:
                    c = self.base(m=rnd.choice(cands), prov=self.pick_prov("MD", "O"), svc=leap, cls="OV")
                    self.set_dates(c, leap, bus - timedelta(days=rnd.randint(0, 2)))
            return c

        # ---- member / eligibility ----
        if name == "d201":
            c = self.base()
            c["member"] = rnd.choice(["1999%05d" % rnd.randint(0, 99999), c["member"][:7] + "39", "M" + c["member"][1:],
                                      "         "])
            return c
        if name == "d202":
            cands = self.memo("d202", lambda: [m for m in self.members if m["eff"] > bus - timedelta(days=200) and m["term"] is None])
            m = rnd.choice(cands)
            c = self.base(m=m, svc=min(m["eff"], bus))
            svc = m["eff"] - timedelta(days=rnd.choice([1, 1, 2, 15, 40]))
            if rnd.random() < 0.25 and m["eff"] <= bus:
                svc = m["eff"]                      # first covered day: accepted
            if svc > bus:
                svc = bus - timedelta(days=rnd.randint(0, 20))
            self.set_dates(c, svc)
            return c
        if name == "term":
            cands = self.memo("term", lambda: [m for m in self.members if m["term"] is not None])
            m = rnd.choice(cands)
            c = self.base(m=m, svc=m["term"])
            if m["rsn"] == "NP":
                off = rnd.choice([0, 1, 5, 15, 30, 31, 32, 33, 45])
            else:
                off = rnd.choice([0, 0, 1, 1, 2, 10, 31])
            svc = m["term"] + timedelta(days=off)
            if svc > bus:
                svc = bus
            self.set_dates(c, svc)
            return c
        if name == "d204":
            kids = self.memo("kids", lambda: [m for m in self.members if m["rel"] == "C" and m["term"] is None
                                              and m["eff"] < bus - timedelta(days=200) and age_on(m["dob"], bus) >= 18])
            m = rnd.choice(kids)
            c = self.base(m=m, cls="OV")
            lim_y = m["dob"].year + (24 if m["stud"] == "Y" else 19)
            k = rnd.random()
            if m["stud"] == "Y":
                bday = date(lim_y, m["dob"].month, m["dob"].day)
                svc = bday - one if k < 0.4 else (bday if k < 0.8 else bus - timedelta(days=rnd.randint(1, 30)))
            else:
                first_next = date(lim_y + (m["dob"].month == 12), m["dob"].month % 12 + 1, 1)
                svc = first_next - one if k < 0.4 else (first_next if k < 0.8 else bus - timedelta(days=rnd.randint(1, 30)))
            if svc > bus or (bus - svc).days > 170:
                svc = bus - timedelta(days=rnd.randint(1, 30))
            self.set_dates(c, svc)
            c["proc"], c["diag"] = "10020", "G1000"
            return c

        # ---- provider ----
        if name == "d301":
            c = self.base()
            c["prov"] = rnd.choice(["P9%05d" % rnd.randint(0, 99999), "X000001", "P00001 ", "       "])
            return c
        if name == "d302":
            pv = rnd.choice([p for p in self.provs if p["status"] == "X"])
            c = self.base(prov=pv)
            k = rnd.random()
            svc = pv["sdate"] if k < 0.3 else (pv["sdate"] - one if k < 0.6 else bus - timedelta(days=rnd.randint(1, 30)))
            if svc > bus or (bus - svc).days > 170 or svc < c["_m"]["eff"]:
                svc = bus - timedelta(days=rnd.randint(1, 30))
            self.set_dates(c, svc)
            return c
        if name == "d303":
            pv = rnd.choice([p for p in self.provs if p["lic"] < bus and p["status"] == "A"])
            c = self.base(prov=pv)
            k = rnd.random()
            svc = pv["lic"] if k < 0.3 else (pv["lic"] + one if k < 0.6 else bus - timedelta(days=rnd.randint(1, 30)))
            if (bus - svc).days > 170 or svc < c["_m"]["eff"]:
                svc = bus - timedelta(days=rnd.randint(1, 30))
            self.set_dates(c, svc)
            return c
        if name == "p901":
            pv = rnd.choice([p for p in self.provs if p["status"] == "S"])
            return self.base(prov=pv)

        # ---- codes ----
        if name == "d601":
            c = self.base()
            c["proc"] = rnd.choice(["99999", "ABCDE", "1001 ", "10011", "     "])
            return c
        if name == "d602":
            c = self.base()
            c["diag"] = rnd.choice(["Z0000", "G9999", "g1000", "     ", "12345"])
            return c

        # ---- duplicates and corrected claims ----
        if name == "dup_hist":
            h = rnd.choice(self.hist)
            k = rnd.random()
            if k < 0.45:
                h = rnd.choice([x for x in self.hist if x["status"] == "D"])
            elif k < 0.55:
                h = rnd.choice([x for x in self.hist if x["status"] == "V"])
            c = self.base()
            c.update(member=h["member"], prov=h["prov"], svc=h["svc"], proc=h["proc"], diag=h["diag"],
                     billed="%09d" % h["billed"], units="001")
            c["rcv"] = ymd(min(pdate("%s-%s-%s" % (h["svc"][:4], h["svc"][4:6], h["svc"][6:])) + timedelta(days=rnd.randint(5, 60)), bus))
            k = rnd.random()
            if h["status"] == "D" and k < 0.45:
                c["sub"], c["orig"] = "R", h["claim"]      # corrected claim for a denied line
            elif h["status"] == "D" and k < 0.60:
                c["sub"], c["orig"] = "R", "H00000000000"  # quotes the wrong original
            elif k > 0.92:
                c["sub"], c["orig"] = "R", h["claim"]
            m = self.mby[h["member"]]
            if m["oth"] == "Y":
                c["cob"] = "Y"
            return c
        if name == "same_visit_hist":
            h = rnd.choice([x for x in self.hist if x["status"] == "A"])
            m = self.mby[h["member"]]
            pv = [p for p in self.provs if p["id"] == h["prov"]][0]
            svc = pdate("%s-%s-%s" % (h["svc"][:4], h["svc"][4:6], h["svc"][6:]))
            c = self.base(m=m, prov=pv, svc=svc)
            self.set_dates(c, svc, min(svc + timedelta(days=rnd.randint(5, 40)), bus))
            return c
        if name == "dup_run":
            if not self.emitted:
                return self.base()
            e = rnd.choice(self.emitted[-400:])
            c = dict(e)
            c["claim"] = self.new_claim_no()
            if rnd.random() < 0.15:
                c["sub"], c["orig"] = "R", e["claim"]
            return c
        if name == "r_nomatch":
            c = self.base()
            c["sub"] = "R"
            c["orig"] = rnd.choice(["", "E99999999999", self.hist[0]["claim"]])
            return c
        if name == "deny_then_fix":
            # first a line that is denied (filed too late), later the corrected claim
            c = self.base(prov=self.pick_prov(None, "I"))
            m = c["_m"]
            svc = bus - timedelta(days=rnd.randint(190, 300))
            if svc < m["eff"]:
                return c
            if not self.covered(m, c["_cls"], svc, c["_p"]) or not self.child_ok(m, svc) \
                    or self.pick_proc(m, c["_cls"], svc) is None:
                return c
            self.set_dates(c, svc, bus - timedelta(days=rnd.randint(0, 4)))
            fix = dict(c)
            if rnd.random() < 0.75:
                fix["sub"], fix["orig"] = "R", c["claim"]   # else sent again as an original
            fix["_fix"] = True
            self.later.append([rnd.randint(3, 60), fix])
            return c

        # ---- timely filing ----
        if name == "d401":
            net = "I" if rnd.random() < 0.6 else "O"
            lim = 180 if net == "I" else 365
            cands = self.memo("d401" + net, lambda: [m for m in self.good if m["eff"] < bus - timedelta(days=lim + 40)
                     and self.child_ok(m, bus - timedelta(days=lim + 40)) and (net == "I" or m["plan"] != "HM1")])
            m = rnd.choice(cands)
            lag = rnd.choice([lim - 1, lim, lim, lim + 1, lim + 1, lim + 2, lim + 20])
            rcv = bus - timedelta(days=rnd.randint(0, 6))
            svc = rcv - timedelta(days=lag)
            c = self.base(m=m, prov=self.pick_prov("MD", net), svc=svc, cls="OV")
            self.set_dates(c, svc, rcv)
            return c

        # ---- clinical ----
        if name == "d603":
            c = self.base()
            m = c["_m"]
            want = [p for p in self.procs.values() if p[5] not in ("", m["sex"])]
            pr = rnd.choice(want)
            c["proc"] = pr[0]
            c["diag"] = rnd.choice(DX.get(pr[2], DX_ANY))
            c["billed"] = "%09d" % int(pr[3] * 1.3)
            return c
        if name == "d604":
            c = self.base()
            m = c["_m"]
            age = age_on(m["dob"], c["_svc"])
            want = [p for p in self.procs.values() if p[5] in ("", m["sex"]) and not (p[6] <= age <= p[7])]
            if not want:
                return c
            pr = rnd.choice(want)
            c["proc"] = pr[0]
            c["diag"] = rnd.choice(DX.get(pr[2], DX_ANY))
            c["billed"] = "%09d" % min(480000, int(pr[3] * 1.3))
            return c
        if name == "age_edge":
            # someone exactly on a procedure age limit on the date of service
            pr = rnd.choice([p for p in self.procs.values() if (p[6] > 0 or p[7] < 999) and p[4] == "N"])
            lim = pr[6] if (pr[6] > 0 and (pr[7] == 999 or rnd.random() < 0.5)) else pr[7] + 1
            cands = [m for m in self.good if (pr[5] in ("", m["sex"])) and m["eff"] < bus - timedelta(days=60)
                     and m["dob"].year == bus.year - lim and (m["dob"].month, m["dob"].day) != (2, 29)]
            if not cands:
                return self.base()
            m = rnd.choice(cands)
            bday = date(bus.year, m["dob"].month, m["dob"].day)
            svc = bday - timedelta(days=rnd.choice([0, 1]))
            if svc > bus or (bus - svc).days > 170:
                svc = bus - timedelta(days=rnd.randint(1, 30))
            if svc < m["eff"]:
                return self.base()
            c = self.base(m=m, svc=svc, prov=self.pick_prov("MD", "I"), proc=pr)
            self.set_dates(c, svc)
            c["billed"] = "%09d" % min(480000, int(pr[3] * 1.2))
            return c
        if name == "d605":
            c = self.base(cls=rnd.choice(["MT", "DN", "VS", "MH", "PV", "CH", "PT"]))
            c["diag"] = rnd.choice(["G1000", "R2000", "C3010", "E3500"])
            return c

        # ---- coverage ----
        if name == "d607":
            k = rnd.random()
            if k < 0.4:
                c = self.base()
                c["proc"], c["diag"] = "90010", "K9500"
                c["billed"] = "%09d" % rnd.randint(200000, 480000)
                return c
            plan, cls, ptype = rnd.choice([("BZ1", "CH", "DC"), ("BZ1", "DN", "DD"), ("HD1", "DN", "DD"),
                                           ("HD1", "VS", "OD"), ("BZ1", "VS", "OD"), ("HD1", "DM", "DD")])
            m = rnd.choice([x for x in self.good if x["plan"] == plan])
            c = self.base(m=m)
            pr = [p for p in self.by_class[cls] if p[7] == 999][0]
            c["prov"] = self.pick_prov(ptype, "I")["id"]
            c["proc"], c["diag"] = pr[0], DX[cls][0]
            c["billed"] = "%09d" % int(pr[3] * 1.4)
            return c
        if name == "d608":
            m = rnd.choice(self.memo("hm1", lambda: [x for x in self.good if x["plan"] == "HM1"]))
            pv = self.pick_prov(rnd.choice(["MD", "HO", "SP"]), "O")
            c = self.base(m=m)
            c["prov"] = pv["id"]
            if pv["type"] == "HO":
                c["proc"], c["diag"] = "20010", "I5020"
                c["emerg"] = "Y" if rnd.random() < 0.5 else "N"   # emergencies are covered
                c["billed"] = "%09d" % rnd.randint(50000, 120000)
            else:
                c["proc"], c["diag"], c["emerg"] = "10020", "G1000", "N"
                c["billed"] = "%09d" % rnd.randint(8000, 20000)
            return c
        if name == "d701":
            cands = self.memo("d701", self.wait_cands)
            if not cands:
                return self.base()
            m, cls, wait = rnd.choice(cands)
            ok_day = m["eff"] + timedelta(days=wait)
            k = rnd.random()
            svc = ok_day - one if k < 0.35 else (ok_day if k < 0.6 else bus - timedelta(days=rnd.randint(1, 25)))
            if svc > bus or svc < m["eff"] or (bus - svc).days > 170:
                svc = bus - timedelta(days=rnd.randint(1, 25))
            pr = self.procs["50010" if cls == "MT" else "72020"]
            c = self.base(m=m)
            c["prov"] = self.pick_prov("MD" if cls == "MT" else "DD", "I")["id"]
            self.set_dates(c, svc)
            c["proc"], c["diag"] = pr[0], DX[cls][0]
            c["billed"] = "%09d" % int(pr[3] * 1.3)
            return c

        # ---- pre-authorisation ----
        if name == "d702":
            k = rnd.random()
            if k < 0.45:
                c = self.base(prov=self.pick_prov("HO", "I"))
                pr = rnd.choice([p for p in self.procs.values() if p[4] == "Y"
                                 and p[6] <= age_on(c["_m"]["dob"], c["_svc"])])
                c["proc"], c["diag"] = pr[0], "G1020"
                c["billed"] = "%09d" % int(pr[3] * 1.5)
                c["emerg"] = "Y" if rnd.random() < 0.15 else "N"    # emergency: no pre-auth needed
            else:
                c = self.base(prov=self.pick_prov("HO", "I"))
                c["proc"], c["diag"], c["units"] = "35010", "G1020", "001"
                c["emerg"] = "N"
                c["billed"] = "%09d" % rnd.choice([500000, 500000, 500001, 500001, 499999, 650000])
            return c
        if name == "d703":
            c = self.with_auth()
            if "_pa" not in c:
                return c
            pa = c["_pa"]
            k = rnd.choice(["unknown", "member", "proc", "cancel", "after", "lastday", "before"])
            if k == "unknown":
                c["auth"] = "PA%08d" % rnd.randint(30000000, 39999999)
            elif k == "member":
                oth = rnd.choice([p for p in self.preauth if p["member"] != pa["member"] and p["proc"] == pa["proc"]] or self.preauth)
                c["auth"] = oth["no"]
            elif k == "proc":
                oth = [p for p in self.procs.values() if p[4] == "Y" and p[0] != pa["proc"] and p[2] != "DE"]
                c["proc"] = rnd.choice(oth)[0]
            elif k == "cancel":
                bad = [p for p in self.preauth if p["status"] == "C" and self.mby[p["member"]] in self.good]
                if not bad:
                    return c
                p2 = rnd.choice(bad)
                m = self.mby[p2["member"]]
                svc = min(max(p2["frm"], m["eff"], bus - timedelta(days=150)), bus)
                c = self.base(m=m, prov=self.pick_prov("HO", "I"), svc=svc, proc=self.procs[p2["proc"]])
                self.set_dates(c, svc)
                c["auth"] = p2["no"]
                c["emerg"] = "N"
                c["billed"] = "%09d" % rnd.randint(500001, 800000)
            else:
                svc = {"after": pa["to"] + one, "lastday": pa["to"], "before": pa["frm"] - one}[k]
                if svc > bus or (bus - svc).days > 170 or svc < c["_m"]["eff"]:
                    c["auth"] = "PA00000000"
                else:
                    self.set_dates(c, svc)
            return c

        # ---- other insurance ----
        if name == "d801":
            m = rnd.choice(self.memo("oth", lambda: [x for x in self.good if x["oth"] == "Y"]))
            c = self.base(m=m)
            c["cob"] = rnd.choice(["N", "N", " ", "y"])
            return c
        if name == "cob":
            c = self.base()
            fee = c["_fee"]
            c["cob"] = "Y"
            c["cobpaid"] = "%09d" % rnd.choice([0, 1, fee // 2, fee, fee * 2, int(c["billed"])])
            return c

        # ---- benefit maximums ----
        if name == "annmax":
            for _ in range(100):
                mid, cls, used = rnd.choice(self.bnacc)
                m = self.mby[mid]
                b = self.bn.get((m["plan"], cls))
                if m in self.good and b and b[8] - used < 20000:
                    break
            ptype = {"CH": "DC", "DN": "DD", "DM": "DD", "VS": "OD", "DE": "DE"}[cls]
            c = self.base(m=m, cls=cls, prov=self.pick_prov(ptype, "I"))
            return c
        if name == "lifemax":
            cands = self.memo("life", lambda: [m for m in self.members if m["term"] is None and m["eff"] < bus - timedelta(days=75)
                                               and self.child_ok(m, bus) and self.plans[m["plan"]][9] - m["life"] <= 300000])
            m = rnd.choice(cands)
            return self.base(m=m, cls=rnd.choice(["OV", "SP", "RD", "OS"]), prov=self.pick_prov("HO", "I"))
        if name == "small":
            # a tiny claim at a rarely used provider: cheque at or under the minimum
            quiet = self.memo("quiet", lambda: [p for p in self.good_prov if int(p["id"][1:]) % 5 == 0 and p["net"] == "I"
                                                and p["type"] in ("MD", "LB")])
            pv = rnd.choice(quiet)
            m = rnd.choice(self.memo("pg1", lambda: [x for x in self.good if x["plan"] in ("PG1", "HM1") and x["oth"] == "N"]))
            c = self.base(m=m, prov=pv, cls="PV" if pv["type"] == "MD" else "LB")
            c["billed"] = "%09d" % rnd.choice([400, 950, 999, 999, 1000, 1000, 1001, 1500])
            c["units"] = "001"
            c["cob"], c["cobpaid"] = "N", "000000000"
            return c
        if name == "hd_edge":
            # network-only plan, inpatient stay billed so that the payment lands on the review limit
            cands = self.memo("hd", lambda: [p for p in self.preauth if p["status"] == "A" and p["proc"] in ("30010", "30020")
                                             and self.mby[p["member"]]["plan"] == "HM1" and p["member"] in self.good_ids
                                             and max(p["frm"], bus - timedelta(days=150)) <= min(p["to"], bus - timedelta(days=1))])
            if not cands:
                return self.with_auth(big=True)
            pa = rnd.choice(cands)
            m = self.mby[pa["member"]]
            lo = max(pa["frm"], bus - timedelta(days=150), m["eff"])
            hi = min(pa["to"], bus - timedelta(days=1))
            svc = lo + timedelta(days=rnd.randint(0, max(0, (hi - lo).days)))
            c = self.base(m=m, prov=self.pick_prov("HO", "I"), svc=svc, proc=self.procs[pa["proc"]])
            self.set_dates(c, svc)
            c["units"] = "003"
            c["billed"] = "%09d" % rnd.choice([1050000, 1050000, 1050001, 1049999])
            c["auth"], c["emerg"], c["cob"], c["cobpaid"] = pa["no"], "N", ("Y" if m["oth"] == "Y" else "N"), "000000000"
            return c
        return self.base()

    def fmt(self, c):
        s = (c["type"].ljust(2)[:2] + c["claim"].ljust(12)[:12] + c["line"].rjust(2)[:2] + c["member"].ljust(9)[:9]
             + c["prov"].ljust(7)[:7] + c["svc"].ljust(8)[:8] + c["rcv"].ljust(8)[:8] + c["proc"].ljust(5)[:5]
             + c["diag"].ljust(5)[:5] + c["units"].rjust(3, "0")[:3] + c["billed"].rjust(9, "0")[:9] + c["sub"][:1]
             + c["orig"].ljust(12)[:12] + c["auth"].ljust(10)[:10] + c["emerg"][:1] + c["cob"][:1]
             + c["cobpaid"].rjust(9, "0")[:9] + c["src"][:1]).ljust(120)
        raw = c.get("_raw")
        if raw == "short":
            s = s[:self.rnd.choice([40, 104, 119])]
        elif raw == "long":
            s = s + self.rnd.choice(["X", "  EXTRA DATA"])
        elif raw == "blank":
            s = ""
        return s


SCENARIOS = [
    ("normal", 600), ("hot", 95), ("auth_ok", 28), ("hidollar", 8), ("units0", 6), ("year2023", 8),
    ("edge_dates", 6), ("small", 8),
    ("f101", 5), ("f103", 5), ("f104", 5), ("f105", 6), ("f106", 5), ("f107", 5), ("f108", 6), ("f109", 5),
    ("d201", 8), ("d202", 9), ("term", 14), ("d204", 10), ("d301", 7), ("d302", 8), ("d303", 8), ("p901", 7),
    ("d601", 7), ("d602", 7), ("dup_hist", 14), ("same_visit_hist", 6), ("dup_run", 7), ("r_nomatch", 5),
    ("deny_then_fix", 9), ("d401", 14), ("d603", 7), ("d604", 7), ("age_edge", 6), ("d605", 8), ("d607", 9),
    ("d608", 8), ("d701", 10), ("d702", 12), ("d703", 10), ("d801", 7), ("cob", 8), ("annmax", 10),
    ("lifemax", 6), ("hd_edge", 4),
]


def main():
    ap = argparse.ArgumentParser(description="Generate a CLAIMS.DAT file for the CLAIMSYS batch")
    ap.add_argument("--count", type=int, default=10000, help="number of claim lines (records)")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--out", default="data/CLAIMS.DAT")
    ap.add_argument("--busdate", default=None, help="business date YYYYMMDD (default: ctl/BUSDATE.CTL)")
    ap.add_argument("--seed-sql", default=os.path.join(HERE, "..", "db", "seed.sql"))
    ap.add_argument("--trace", default=None, help="also write record number and scenario name per record")
    args = ap.parse_args()

    busdate = args.busdate
    if not busdate:
        busdate = "20241015"
        card = os.path.join(HERE, "..", "ctl", "BUSDATE.CTL")
        if os.path.exists(card):
            for line in open(card):
                if line.startswith("BUSDATE="):
                    busdate = line.strip()[8:16]
    bus = date(int(busdate[0:4]), int(busdate[4:6]), int(busdate[6:8]))

    rnd = random.Random(args.seed)
    g = Gen(load_seed(args.seed_sql), rnd, bus)
    names = [s[0] for s in SCENARIOS]
    weights = [s[1] for s in SCENARIOS]

    lines = []
    trace = []
    while len(lines) < args.count:
        due = [x for x in g.later if x[0] <= 0]
        if due:
            g.later.remove(due[0])
            c = due[0][1]
            c["claim"] = g.new_claim_no()
            name = "fix"
        else:
            name = rnd.choices(names, weights)[0]
            c = g.scenario(name)
        recs = [c]
        # some ordinary claims carry more lines for the same visit
        if name in ("normal", "hot") and rnd.random() < 0.28:
            for n in range(rnd.choice([1, 1, 2])):
                extra = g.base(m=c["_m"], prov=c["_p"], svc=c["_svc"])
                if extra["proc"] in [x["proc"] for x in recs]:
                    continue
                extra["claim"] = c["claim"]
                extra["line"] = "%02d" % (len(recs) + 1)
                extra["rcv"], extra["cob"], extra["cobpaid"] = c["rcv"], c["cob"], c["cobpaid"]
                recs.append(extra)
        for r in recs:
            if len(lines) >= args.count:
                break
            lines.append(g.fmt(r))
            trace.append(name)
            if name in ("normal", "hot"):
                g.emitted.append(r)
            for x in g.later:
                x[0] -= 1

    with open(args.out, "w", newline="\n") as f:
        for s in lines:
            f.write(s + "\n")
    if args.trace:
        with open(args.trace, "w", newline="\n") as f:
            for i, name in enumerate(trace):
                f.write("%07d %s\n" % (i + 1, name))
    print("wrote %d claim lines to %s (business date %s, seed %d)" % (len(lines), args.out, busdate, args.seed))


if __name__ == "__main__":
    main()
