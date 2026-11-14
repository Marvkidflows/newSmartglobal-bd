# Baseline report — SSI remediation (2 Nov 2026 audit → 3 Nov 2026 implementation)

## Source versions (SHA-256 verified against the audit's package table)
| Role | File | SHA-256 |
|---|---|---|
| BASE backend (MarvFlow) | smartInvest-backend-FULL__10_.zip | `1b7ca42aa6ec85f4fce9509f21a245332e78dbd6a1bab36762861865611a7edc` |
| BASE frontend | smartInvest-frontend-RANKS_7__6_.zip | `e034411f7ec0de14ea82cdd9c675cb5cea9bf3aedf110fb0c5820025cc993a00` |
| **Working base — backend (candidate: base + patches 02, 03, 04)** | smartInvest-backend-PHASE-B-clean.zip | `e0dee9a73f1d95ff49d158233f723a52f24da89ffe5d972d0c6cb540c9315dd2` |
| **Working base — frontend (candidate: base + 01, 02 + UTF-8 .gitignore)** | smartInvest-frontend-PHASE-E-clean.zip | `874b725926fe4bd55bbb242ab64a00343588e20e8333e546647ba30c69cc1ffa` |
| Optional patch applied | backend-05-OPTIONAL-benefit-fulfilment.patch | `15412a6dad9a7ac9d5a31b6c74ca1a119e02af05ab55986b489080c761324515` |
| Audit | SSI_INDEPENDENT_AUDIT_REPORT.md | `b2abde20d16269d4c44983a9a8ad74e6f023f8d25924312e8e85fce1d1976d37` |
| Benefit reconciliation (input) | AUDIT_64_BENEFIT_RECONCILIATION.csv | `224628b3c16a4132b8ab73863d0231c8cd7460855e30eb0e07868dfc336f0ea4` |

Candidate and base were never mixed: all remediation is a diff on the candidate packages (backend and frontend are separate trees). Originals were not modified; work was done on extracted copies.
`marvflow-rev5-security-review.zip` (SECURITY_COMPAT_REPORT + two delta patches) was read as reference only; its patches were **not** applied.

## Environment limits (affect every claim below)
PHP, Composer and MySQL are **not installed** in the working environment, and the network is disabled. Node 22 exists, but the supplied `node_modules` carry a non-Linux Rollup binary and no Vitest, so the frontend build and tests could not run. Therefore **no PHP code, migration, PHPUnit test or frontend test was executed.** What *was* executed is listed in TEST_REPORT.md.

## Files modified / added
See `FILE_INVENTORY.txt` in the package and the two `.patch` files. Summary of touched areas:
modified — `InvestorInvestmentController`, `AdminUserController::adjustBalance`, `AdminGlobalManagementController` (`bulkBalance`, `adjustProfit`, + new `reverseProfit`, `profitAdjustmentItems`), `FinancialInvestorController::adjustBalance`, `InvestmentSettlementService`, `DepositPaymentService`, `LoginController`, `Admin/Financial/Marvflow(+Lead)Middleware`, `InvestorEmailController`, `RegisterController`, `User` (fillable), `Profit/BalanceAdjustment` models, `routes/api.php`, `docker-entrypoint.sh`, two seeders, two existing tests;
new — `Services/Financial/*` (3), `FinancialApprovalController`, `Support/StaffAccountGuard`, `Concerns/ReadsIdempotencyKey`, `ReconcileLegacyDeposits` command, `config/financial_controls.php`, one additive migration, 2 models, `database/controlled/F02_unique_source_deposit.sql`, tests (see TEST_REPORT), `phpunit.mysql.xml`, `scripts/test-entrypoint.sh`, docs; patch‑05 files (4) applied unchanged;
removed — `app/Http/Controllers/Investor/InvestorEmailController copy.php` (duplicate class, IDE artefact; its sanitising line was moved into the real controller);
frontend — `src/utils/safeHtml.js(+test)`, 4 components/pages wired to it.
