# Investigation of the three result files that shipped in the original backend ZIP

**Runtime verification of everything below is still OUTSTANDING.** No PHP, MySQL or PHPUnit run was possible while preparing this package. Nothing here says the issue is resolved; it says what the code and the test do, what the recorded failure most likely means, and what must be run to confirm.

## 1. The three files
| File | Time stored in the ZIP | What it is | Relates to the integrated project? |
|---|---|---|---|
| `rank-test-results.txt` | 2026-10-31 22:10 | PHPUnit/Pest console output saved from PowerShell (UTF-16): **430 passed, 53 failed** (484 tests) | **No.** It predates the Rank progress-notice, proposed-criteria and multi-currency test edits (test files dated 1 and 5 Nov) and all of Tasks 2–5. `RANKS_7_FINAL_VERIFICATION_REPORT.md` records a later run with 0 failures, so this is an older run |
| `mysql-results.txt` | 2026-11-09 02:15 | MySQL concurrency suite: **14 passed, 1 failed**, total ≈ 11,787 s | **Partly** — the failing test is in the ORIGINAL code base (unchanged since 5 Nov). The integrated project changes withdrawal approval, so it must be re-run |
| `routes.json` | 2026-10-21 | `route:list --json` snapshot | **No.** Predates Rank 7 and Tasks 2–5 |
All three were removed from the delivery ZIPs (generated output, not source). No source, test, documentation or SQL script was deleted.

## 2. The recorded MySQL failure
The full analysis (locking behaviour, why the recorded `[422, 200]` matches the safe outcome, the counting error and its correction, what the tests now distinguish, and the exact run that closes the issue) is in **`MYSQL_CONCURRENCY_INVESTIGATION.md`**. **The issue is not proven resolved until those MySQL suites are run.**

## 3. Run time
See `MYSQL_CONCURRENCY_INVESTIGATION.md` §5.
