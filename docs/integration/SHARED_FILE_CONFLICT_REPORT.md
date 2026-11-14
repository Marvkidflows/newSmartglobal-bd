# Shared-file conflict resolution report

Method: every task package was compared file-by-file with the original. Findings that shaped the merge:

* **Task 5 shipped a whole project copy built on an OLDER snapshot of the original.** Taking its files would have reverted newer original work. Only Task 5's genuine changes were merged (see table). Task 5 files that were stale reversions and were deliberately **NOT** taken:
  `RankBenefitController`, `RankStandingController` (lost export audit logging + extra CSV columns), `RankBenefitService` (lost next-rank notices), `RankEvaluationService` (lost review-episode logic), `RankNotifier`, `RankPresenter` (lost valuation notes), `config/ranks.php` (lost progress_notices), `LoadRankDemoData`, `routes/console.php` (lost `ranks:progress-notices`), migration `2026_11_03_100001_create_controlled_financial_operations` (rewritten), and 7 frontend Rank/investor pages (`AdminRankInvestor`, `CurrencyReviewTab`, `PositionsPanel`, `RankAuditTab`, `RankReviewsTab`, `InvestorInvestments`, `InvestorSectors`) which contain no Task 5 change.
* **Task 5's `RegisterController` also reverted the original's account-takeover fix** (staff/non-investor accounts could be "resumed"). Dropped; Task 2's stricter `resume->eligible()` guard is retained.
* **Task 2's `routes/console.php` was also stale** (dropped `ranks:progress-notices`). Rebuilt from the original.
* Tasks 3 and 4 are clean overlays (new files + one `require` line each).

| File | Task 2 | Task 3 | Task 4 | Task 5 | Resolution |
|---|---|---|---|---|---|
| `routes/api.php` | registration routes, `/register/resume/verify`, tracking routes | `require mailing.php` | `require news.php` | `/referrals/visit`, `withdrawals/quote`, throttle on withdrawal store, financial referral + fee routes | All hunks combined; both `require` lines appended once at end (outside any group, so paths get the `/api` prefix) |
| `routes/console.php` | `registration:maintain` hourly | `mailing:dispatch-scheduled` | `news:publish-scheduled` | `referrals:scan --no-backfill` hourly at :17 (added in the gap-closure round) | Rebuilt from ORIGINAL (keeps `ranks:progress-notices`) + 3 schedules |
| `app/Models/User.php` | registration relations | – | – | – | Task 2 only |
| `AppServiceProvider.php` | `registration.review/decide` gates | – | – | `financial.*` referral/fee gates (financial+admin only; reversal admin only) | Both gate sets present; registration roles are in no financial gate |
| `bootstrap/app.php` | `registration.team` alias + priority | – | – | – | Task 2 only. All aliases used by Task 3/4/5 routes exist |
| `RegisterController` | resume-by-code security, tracking | – | – | referral code + beacon + pending reward at completion | Task 5 hunks applied on Task 2 version; Task 5's takeover-guard reversion dropped |
| `StaffAccountGuard`, `LoginController` | registration roles in staff list | – | – | – | Task 2 only |
| `0001_..._create_users_table` | role enum lists all roles | – | – | – | Task 2 (SQLite-test requirement; no effect on an already-migrated MySQL DB) |
| `Withdrawal`, `AdminWithdrawalController`, `WalletLedger` | – | – | – | additive fee fields/hooks/classifications | Task 5 (verified: no original lines removed) |
| `InvestorWithdrawalController` | – | – | – | **rewrites `store()`** (server fee quote, row lock, idempotency) | Taken as the task intends. Preserves PIN, balance, one-pending rule, ledger entry, notifications. **This is a change to an existing financial flow — see Unresolved #1** |
| `InvestorReferralController` | – | – | – | richer, read-only investor view | Task 5, plus integration fix: the programme reward amount is shown only when the policy is approved |
| `MailingPermissions`, `NewsPermissions` | – | STAFF_ROLES lacked registration roles | same | – | **Integration fix**: registration roles added; registration roles can NEVER hold `investor.*` mailing permissions |
| `src/App.jsx` | registration routes/guard | mailing routes | public + investor + admin news routes | referral/fee financial routes | All combined; investor news de-duplicated (below) |
| `AdminLayout`, `InvestorLayout`, `FinancialLayout` | – | 2 nav items | 1 admin + 1 investor item | 2 financial items | Combined; Investor nav has ONE "News & Updates" entry |
| `src/services/api.js` | registration APIs | – (own `mailingApi.js`) | – (own `newsApi.js`) | referral/fee/quote APIs | Task 5 patch applied on Task 2 version |
| `RegisterPage.jsx` | resume-code flow, tracking notice | – | – | referral-visit beacon | Combined; beacon effect moved below the `searchParams` hook |
| `InvestorReferrals.jsx`, `InvestorWithdrawals.jsx` | – | – | – | replaced/extended | Task 5 |
| `InvestorAnnouncements.jsx` | – | – | – | – | Integration: links now stay under `/investor/announcements/...` |

## Investor news: one experience
* `/investor/news` and `/investor/news/:slug` → Task 4 News & Updates (new `InvestorNewsHub.jsx` wrapper). A slug the new module does not know (an old announcement) redirects to `/investor/announcements/:slug`.
* `/investor/announcements[/:slug]` → original page kept (dashboard link, old items still work). Linked from the bottom of the new feed as "announcements archive".
* `/investor/updates[/:slug]` (Task 4's own path) → redirected to `/investor/news`.
* Investor menu: a single "News & Updates" item. Backend APIs do not collide (`/api/investor/announcements` vs `/api/investor/news`).
* Old announcements were **not** migrated into the new module (needs your approval).
