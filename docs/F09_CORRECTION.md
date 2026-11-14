# F-09 correction — which changes affect LIVE (flags-OFF) behaviour

**The earlier statement "only patch 03 affects flags-OFF behaviour" was wrong.** Corrected statement:

| Change | Affects production with all financial flags OFF? | How |
|---|---|---|
| Patch 02 — `RankInvestmentLedger` | **YES** | The live rank engine reads investments through it; derived cycle/principal states drive rank evaluation. |
| Patch 02 — `LedgerReaders` | **YES** | Shared readers used by ledger-aware code paths and reports. |
| Patch 02 — `InvestorInvestmentController::store` pre-checks | **YES** | Run on every investment request, flags OFF or ON. |
| Patch 03 | YES | (as previously stated) |
| Patch 04 | see its own report | not re-reviewed here |
| **This remediation (new)** | **YES — see APPLY.md §"Behaviour changes"** | F-01 atomic legacy investing; F-03/F-04 admin tools now return 202 + need a second approver by default; F-12 fillable; F-13 decimal settlement/FX arithmetic; F-15 staff suspension now enforced everywhere; F-05 web no longer migrates; InvestorEmail read path sanitises. |

Documents that must carry this correction: `APPLY.md`, `RELEASE_READINESS_REPORT.md`, `DECIMAL_MIGRATION_REPORT.md`, deployment docs.
**Those three originals were not in the supplied archives**, so they could not be edited in place; this notice and the new `APPLY.md` supersede them. Insert the table above into the originals, or discard the originals.
Required test before any use of patch 02: full Rank suite plus a before/after rank comparison over a staging copy (audit F-09). **Not done here (no PHP).**
