# CLAIMSYS: legacy health-insurance claims adjudication (PHP + MariaDB)

A **"before"** system for TransitionX 2026: the claims intake and nightly adjudication system of a fictional mid-size health insurer, written as procedural PHP on a MySQL database around 2004 to 2008 and patched ever since.

> **This is a synthetic legacy system.** It was written for TransitionX 2026 as a test subject for modernization tooling. The insurer ("Tarrow Valley Mutual Health"), the product, the people, the providers and every code and amount are invented; there is no real personal data and no real secret in this repository (the database password in the sources belongs to the throw-away database inside the container). Its problems are deliberate: one very large batch program, global work areas shared by every routine, SQL built by string concatenation, HTML mixed with logic, validation copied between the web form and the batch, reference data and magic numbers in the source, rules split between PHP, benefit tables and SQL views, no tests, and no documentation of the rules. These are the problems a modernization project has to deal with. It is legacy, not broken: the batch is stable and fully deterministic.

## What it does

```
                           +--------------------------- MariaDB "claimsys" ---+
                           | members  plans  benefits  providers  codes       |
                           | pre-auths  accumulators  claim history           |
                           +-----------^----------------------+---------------+
                                       |                      |
CLAIMS.DAT ──► CLMJOB (run/RUNADJUD.sh)|                      |
 fixed-width    STEP010 reload database snapshot              v
 120-byte       STEP020 CLMADJ01 edit · price · post ──► PAYMENTS.DAT   approved lines, what was paid and why
 claim lines            cheque run · ledger          ──► DENIALS.DAT    denied lines with reason code and text
                                                     ──► PENDED.DAT     lines held for manual review
                                                     ──► CHECKS.DAT     provider cheque register / carried balances
                                                     ──► GLEXTRACT.DAT  ledger postings
                                                     ──► AUDIT.LOG      one event line per record
                STEP030 CLMEXP01 unload accumulators ──► ACCUM.DAT      end-of-run state from the database
                STEP040 CLMRPT01 report              ──► REMIT.RPT      remittance summary (print file)
```

Clerks key paper claims on the intranet pages; those lines wait in a spool file and are added to the next run. Everything else arrives as the day's claims file.

## Layout

| Path | Contents |
|---|---|
| `batch/CLMADJ01.php` | The adjudication program: record edits, claim edits, pricing, accumulator posting, cheque run, ledger extract |
| `batch/CLMEXP01.php`, `batch/CLMRPT01.php` | Accumulator export and the remittance report |
| `inc/` | Site configuration and the routines shared by batch and web (database, dates, money, reason texts) |
| `db/schema.sql`, `db/seed.sql` | Tables and views; the test-region snapshot the job reloads on every run |
| `db/bootstrap.sql`, `db/claimsys.cnf` | One-off database/account creation and server settings |
| `run/RUNADJUD.sh` | Job script (plays the role of the scheduler entry). `run/NEWDAY.sh` makes a new day and runs it, `run/DBUP.sh` starts the database, `run/ENTRYPOINT.sh` is the container start |
| `ctl/BUSDATE.CTL` | Run control card with the business date |
| `web/` | Intranet pages (PHP, table layout, mid-2000s) |
| `tools/gen_claims.py` | Generates synthetic claims files against the snapshot |
| `tools/gen_seed.py` | Regenerates `db/seed.sql` (fixed random seed, always the same file) |
| `data/CLAIMS.DAT` | Sample input: 10,000 claim lines (`gen_claims.py --count 10000 --seed 42`) |
| `spool/`, `output/` | Keyed claims waiting for the batch; one folder per batch run (both created at run time) |

## Run it

With Docker (any OS):

```sh
make docker-build        # image claimsys-legacy
make docker-run          # runs the job once on data/CLAIMS.DAT, output in ./output/<timestamp>/
make docker-web          # intranet pages on http://localhost:8080
```

On Ubuntu 24.04 without Docker (`sudo apt install php-cli php-mysql mariadb-server python3`):

```sh
make dbinit              # once: creates database "claimsys" and the batch account
make build               # syntax check of all sources
make run                 # ./run/RUNADJUD.sh data/CLAIMS.DAT output/<timestamp>
```

The job itself:

```sh
./run/RUNADJUD.sh <claims-file> <output-dir>
```

Generate a different input file:

```sh
python3 tools/gen_claims.py --count 100000 --seed 7 --out /tmp/CLAIMS_100K.DAT
./run/RUNADJUD.sh /tmp/CLAIMS_100K.DAT /tmp/out100k
```

10,000 claim lines take about 10 seconds, 100,000 about two minutes.

### Business date

Every date rule uses the **business date**, never the clock. The job takes it from the `BUSDATE` environment variable (`YYYYMMDD`); if that is not set, from the control card `ctl/BUSDATE.CTL`; if there is no card, from the system date. The card in this repository is frozen at **2024-10-15** to match the database snapshot, and the generator reads the same card (or `--busdate`). The report heading and `AUDIT.LOG` print the date used.

The same input, snapshot and business date give byte-identical output files on every run:

```sh
./run/RUNADJUD.sh data/CLAIMS.DAT /tmp/a && ./run/RUNADJUD.sh data/CLAIMS.DAT /tmp/b
for f in /tmp/a/*; do cmp "$f" "/tmp/b/$(basename "$f")"; done
```

### Return codes

Printed on the last console line as `CLMJOB - ENDED ... RC=<n>`:

| RC | Meaning |
|---|---|
| `0` | Clean run, every line approved |
| `4` | Completed; some lines were denied or pended (the normal case) |
| `8` | Completed but the control totals do not balance (records vs dispositions, payment file vs claim table, ledger debits vs credits) |
| `16` | Abend: input file, business date, output directory or database problem |

The job script exits `0` for RC 0 and 4 and with the RC itself for 8 and 16.

## The data

All data lives in one MariaDB database, `claimsys`, on the same machine as the batch and the web pages (unix socket only, MyISAM tables, latin1). In the container the server's files are in `/var/lib/mysql`; `run/DBUP.sh` starts the server when it is not running and creates the database and the `clmbatch` account from `db/bootstrap.sql`.

**The job reloads the database at the start of every run.** STEP010 drops and recreates every table and view from `db/schema.sql` and loads `db/seed.sql`, so each run starts from the same snapshot and can be repeated. (This is how the test region was run. Production ran the same job without STEP010, carrying the state from night to night.) A consequence: what the intranet pages show is always "snapshot plus the last batch run", and claims from earlier runs are gone once the next run starts. The run's files stay in `output/<run>/`.

| Table / view | Kind | What it holds |
|---|---|---|
| `plan`, `bnft` | reference | Plans and the benefit table per plan and service class |
| `proccode`, `diagcode` | reference | Procedure and diagnosis code sets (house codes) with the fee schedule |
| `grp` | reference | Employer groups |
| `preauth` | reference | Pre-authorisations on file |
| `member` | reference + state | Members and eligibility; the lifetime amount used is updated by the batch |
| `provider` | reference + state | Providers, network and status; the carried balance is updated by the cheque run |
| `accum`, `famaccum`, `bnaccum` | state | Member, family and benefit accumulators per plan year, posted by the batch |
| `claim` | state | Claim lines: history from the snapshot (`run_ind = 'H'`) plus the lines of the current run (`'C'`) |
| `sysctl` | state | Control values such as the next cheque number |
| `v_elig`, `v_dupchk` | views | Eligibility and duplicate-check views used by the batch |

Only one job runs at a time (the script takes a lock). `ACCUM.DAT` is the unload of the state tables at the end of the run, in key order.

## Intranet pages (legacy UI)

`make docker-web` (or `docker run --rm -p 8080:8080 claimsys-legacy`), then open http://localhost:8080. The container starts MariaDB, runs the sample file once so there is something to show, and serves the pages with the PHP built-in web server.

| Page | Use |
|---|---|
| Member Lookup | Find a member by ID or name; eligibility, family, accumulators |
| Claim Entry | Key a paper claim line; it is checked and put in the spool for the next batch |
| Claim Status | Claim lines by claim number or member, with the denial or pend reason |
| Batch Report | `REMIT.RPT`, job log, ledger extract and audit log of any kept run |
| Denials & Pends | Lines denied or held in a run, by reason code |
| Payments | Cheque register of a run and the paid lines per provider |
| Run Nightly Batch | Generates a new day of claims, adds the keyed claims, runs the job and shows the job log |

## Known characteristics

- No automated tests and no written specification of the adjudication rules; the code and the tables are the specification.
- The claim-entry page carries its own copy of some batch edits.
- Not a secure system: do not expose the pages beyond a demo network.
