# Wording corrections: excluded records are NOT counted as zero (8 November 2026)

**Scope:** text only. No financial calculation, rank evaluation, principal, investment, withdrawal, deposit, FX or cycle logic was changed. No permission, security control, API, database structure or migration was changed. No feature or policy was added.
**Tests:** none were executed. The two JSX files were syntax-checked only (they parse); that is not a test run. No test covers these texts.

## Corrections
| # | File | Before | After |
|---|---|---|---|
| 1 | `frontend/src/pages/admin/ranks/CurrencyReviewTab.jsx` (intro banner) | "Anything unknown, changed since it was verified, or that looks like a duplicate is **left out (counts as $0)**" | Records with an unknown or stale valuation are excluded from the qualifying rank figures until Financial verifies their valuations; they are **not treated as zero** and **no exchange rate is assumed**. Suspected duplicates are also excluded while under review; Financial must confirm whether a suspected duplicate is a separate valid record before it can count |
| 2 | `frontend/src/pages/admin/ranks/AdminRankInvestor.jsx` (exclusion banner) | one sentence saying all excluded records wait "until their valuations are verified" | Two separate sentences, each shown only when it applies: **valuation verification** for unknown or stale records (and deposits), and **duplicate verification** for suspected duplicates. Replaces the earlier correction, which implied every excluded record was waiting for a valuation |
| 3 | `backend/RANK_MODULE.md` lines 27, 277, 292, 350, 380, 400 | "counts as **$0**", "add $0", "→ $0 for ranking" | "excluded from the qualifying figures (not treated as zero)" wording; line 292 also states no rate is assumed |
| 4 | `backend/RANKS_7_FINAL_VERIFICATION_REPORT.md` line 160 | "unverified currency counting as zero" | "unverified currency being excluded from the figures (never valued as USD, never as zero, never guessed)" |
| 5 | `backend/app/Console/Commands/LoadRankDemoData.php` line 70 | demo scenario label `Unverified currency counts as zero` | `Unverified currency is excluded, not zero` (a string only: demo investors are keyed by e-mail, not by this label, and no test refers to it; investors already created in a dev database keep their old name) |

## Searched and found no other contradictory wording
Searched: all frontend source, backend `app/`, `resources/`, `config/`, `routes/`, `database/`, every markdown file in the backend and frontend projects, and the tests, for "counts as", "counted as", "treated as", "valued at", "as zero", "$0", "worth nothing" and the underscore forms. Everything not listed above is either already correct (negations such as "not counted as zero" in `RankValuationNote.php`) or a different rule.

## Reviewed and deliberately NOT changed
* `RANK_MODULE.md` line 325 ("in dev ... the totals will be $0"): it says the qualifying TOTAL is $0 when nothing is verified; it does not assign a value to a record.
* `LoadRankDemoData.php` lines 39 and 50 ("completed = $0", "current eligible principal is $0"): a different, correct rule: principal in COMPLETED positions is no longer counted, so it adds $0 to current principal. Not about excluded records.
* `tests/Feature/Rank/RankMultiCurrencyTest.php`, method `test_an_unverified_currency_counts_as_zero_never_as_usd`: the test NAME still uses the old phrase. Its content is correct (unverified currency adds nothing and is never read as USD). Not renamed, because renaming changes the test's identity in `--filter` runs and in the results you have already recorded. Say so if you want it renamed.

## Files in this zip
`frontend/src/pages/admin/ranks/CurrencyReviewTab.jsx`, `frontend/src/pages/admin/ranks/AdminRankInvestor.jsx` (supersedes the copy in `rank-policy-clarifications-corrected.zip`), `backend/RANK_MODULE.md` (supersedes the copies in the clarifications and wrap-up zips: it is their superset), `backend/RANKS_7_FINAL_VERIFICATION_REPORT.md`, `backend/app/Console/Commands/LoadRankDemoData.php`, `backend/docs/WORDING_CORRECTIONS_REPORT.md`.
Apply after `rank-policy-clarifications-corrected.zip` and `rank-wrapup-phase6-8-9.zip`.
