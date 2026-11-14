# OUTSTANDING TESTS — precise list, everything NOT yet executed
**Rule:** a test counts as passed only if it was executed and its log is attached. This file lists what has *not* been. For each environment, the exact command is given. `scripts/verify-all.sh` and `scripts/verify-frontend.sh` run all of it, log every step, and exit non-zero unless every step ran and passed.

## 0. Summary
| Environment | What is outstanding | Command |
|---|---|---|
| PHP 8.4 (no DB) | `php -l` on every file (≈ 480 PHP files); unit tests | `bash scripts/verify-all.sh` step 02 / `php artisan test --testsuite=Unit` |
| PHP 8.4 + SQLite | **Entire default suite (979 test methods / 77 classes — not run against the corrected code)**, incl. everything in §A | `php artisan test` (step 04) |
| PHP 8.4 + SQLite, strict | Whole suite with `SSI_STRICT_MASS_ASSIGNMENT=1` to enumerate remaining silent mass-assignment discards (R2) | step 05 |
| PHP 8.4 + MySQL 8 | All of §B; `migrate:fresh`; `ssi:schema-check`; migration `down()`/`up()` | steps 07–09 (needs `DB_*` for a database named `*test*`) |
| Docker | Build `Dockerfile` (non-root user can write `storage/`); build `Dockerfile.verify` | `docker build .` and `docker compose -f docker-compose.verify.yml …` |
| Node 20/22 + **network** | Regenerate `package-lock.json` (F10), clean `npm ci`, **vitest** (all 6 frontend test files incl. jsdom/DOM ones), `vite build` | `LOCK_REGEN=1 bash scripts/verify-frontend.sh` then `bash scripts/verify-frontend.sh` |
| Browser | All 27 rows of `BROWSER_TEST_SCRIPT.md` | manual |

# OUTSTANDING TESTS — exact list (generated from the code by `gen_outstanding.py`; nothing here has been executed against PHP / MySQL / a browser)

Whole backend suite: **979 test methods**, in 77 classes. **None of them has been run against the corrected code: this pass had no PHP, and the second independent audit states it had none either.** (Whether the first remediation ran any is not documented in the material supplied.) So the entire suite is outstanding for this release; the tables below single out what *this correction pass* added or touched, because those are the likeliest to contain authoring mistakes.

Added in this pass: **51 test methods in new files + 8 new methods in modified files**.

## A. PHP / SQLite — `php artisan test` (default suite)

### `tests/Feature/AdminTaskTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_completing_twice_credits_exactly_once_and_is_audited`
File also changed in the setup/other methods; 25 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/FinancialTeamTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
File also changed in the setup/other methods; 16 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/FinancialTransactionsAndCommunicationsTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_authorized_correction_waits_for_second_approval_then_updates_recomputes_and_audits`
- `test_a_correction_cannot_execute_after_the_record_changed_since_the_request`
File also changed in the setup/other methods; 32 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Rank/RankFoundationTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
File also changed in the setup/other methods; 7 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Rank/RankProposedCriteriaTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
File also changed in the setup/other methods; 34 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Remediation/ApprovalWorkflowHardeningTest.php` — NEW FILE — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_an_excessive_deduction_is_rejected_immediately_on_both_endpoints_and_no_request_exists`
- `test_a_valid_large_deduction_is_queued_without_touching_the_balance_and_revalidated_at_execution`
- `test_nobody_can_adjust_their_own_account`
- `test_splitting_a_large_adjustment_into_small_ones_is_caught_by_the_cumulative_window`
- `test_an_idempotency_key_cannot_return_a_request_for_a_different_payload`
- `test_an_identical_pending_request_is_not_duplicated`
- `test_the_payload_hash_survives_a_json_round_trip_with_reordered_nested_keys`
- `test_the_full_history_of_a_request_is_recorded_in_order`
- `test_the_events_table_is_append_only`
- `test_requester_can_cancel_a_pending_request_and_it_can_no_longer_be_approved`
- `test_an_expired_request_cannot_be_approved`
- `test_expiry_is_disabled_by_default`
- `test_a_suspended_approver_or_requester_cannot_complete_a_request`
- `test_a_failed_request_can_be_retried_once_the_blocker_is_resolved`
- `test_recovery_reconciles_legacy_approved_rows_without_guessing_about_money`
- `test_no_alternate_route_changes_an_investment_amount_or_rate_without_approval`
- `test_the_profit_cap_when_configured_is_enforced_and_absent_by_default`
- `test_frozen_staff_and_every_non_active_status_are_refused_on_ordinary_authenticated_endpoints`
- `test_unfreeze_cannot_reactivate_a_suspended_account`
- `test_kyc_state_cannot_be_mass_assigned`
- `test_applySigned_is_exactly_once_audited_and_keeps_the_documented_negative_loss_rule`
- `test_resubmitting_a_decided_request_reports_its_real_state_not_pending`
- `test_profit_history_exposes_status_and_a_pending_reversal`
- `test_an_undeterminable_interrupted_request_can_never_be_retried`
- `test_an_administrator_cannot_suspend_freeze_or_deactivate_their_own_account`

### `tests/Feature/Remediation/DepositAccountingTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_the_reconciliation_report_never_overwrites_without_force_and_is_owner_only`
File also changed in the setup/other methods; 5 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Remediation/DepositValuationModeTest.php` — NEW FILE — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_default_mode_is_the_legacy_float_calculation_exactly`
- `test_exact_decimal_mode_is_half_up_on_the_exact_product`

### `tests/Feature/Remediation/FinancialSnapshotTest.php` — NEW FILE — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_unchanged_data_passes_and_the_snapshot_is_owner_only`
- `test_it_never_overwrites_an_existing_snapshot_without_force`
- `test_activity_after_the_snapshot_does_not_fail_a_live_comparison`
- `test_tampering_with_historical_immutable_rows_is_detected`
- `test_a_hash_difference_is_caught_even_when_sums_are_unchanged`
- `test_a_deleted_historical_row_is_detected`
- `test_the_command_is_read_only`
- `test_a_non_snapshot_file_is_refused`

### `tests/Feature/Remediation/InvestorInvestmentAtomicityTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
File also changed in the setup/other methods; 8 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Remediation/ManagementDefaultsTest.php` — NEW FILE — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_every_default_is_in_force_with_a_clean_configuration`
- `test_the_register_never_claims_management_approval`
- `test_the_concrete_values_match_the_instruction`
- `test_cumulative_limit_is_disabled_when_nothing_is_configured_and_never_invented`
- `test_a_deviation_is_detected_and_fails_the_check`

### `tests/Feature/Remediation/MassAssignmentTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_strict_mode_makes_a_protected_mass_assignment_loud_not_silent`
File also changed in the setup/other methods; 4 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Feature/Remediation/StaffAccountStatusTest.php` — MODIFIED — PHP + SQLite in-memory (default suite)
New methods (all **NOT EXECUTED**):
- `test_allowlist_blocks_inactive_and_unknown_statuses`
File also changed in the setup/other methods; 5 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Unit/EmailImageEmbedderTest.php` — MODIFIED — PHP (no DB)
New methods (all **NOT EXECUTED**):
- `test_external_https_images_are_dropped_by_default_and_data_images_still_embed`
File also changed in the setup/other methods; 3 pre-existing method(s) in the file must be re-run (not listed individually).

### `tests/Unit/RichTextSanitizerProfilesTest.php` — MODIFIED — PHP (no DB)
New methods (all **NOT EXECUTED**):
- `test_positioning_and_overlay_css_is_removed_on_both_profiles`
File also changed in the setup/other methods; 9 pre-existing method(s) in the file must be re-run (not listed individually).

## B. PHP / **MySQL 8** — `php artisan test --configuration=phpunit.mysql.xml --testsuite=MySQL`

### `tests/MySql/MySqlApprovalConcurrencyTest.php` — NEW FILE
- `test_two_approvers_deciding_the_same_request_execute_it_exactly_once` — **NOT EXECUTED**
- `test_parallel_identical_submissions_create_exactly_one_pending_request` — **NOT EXECUTED**
- `test_one_idempotency_key_with_two_different_payloads_admits_exactly_one` — **NOT EXECUTED**
- `test_json_payload_hash_round_trips_through_mysql_json_with_nested_keys` — **NOT EXECUTED**
- `test_a_deposit_approved_twice_at_once_credits_once` — **NOT EXECUTED**
- `test_withdrawal_and_investment_cannot_both_spend_the_same_cash` — **NOT EXECUTED**
- `test_a_balance_deduction_and_a_withdrawal_approval_cannot_overdraw` — **NOT EXECUTED**
- `test_two_simultaneous_task_completions_credit_the_wallet_once` — **NOT EXECUTED**
- `test_an_investment_correction_racing_settlement_is_all_or_nothing` — **NOT EXECUTED**
- `test_an_investment_correction_racing_a_profit_adjustment_leaves_a_coherent_record` — **NOT EXECUTED**
- `test_a_profit_reversal_racing_settlement_never_alters_a_settled_record` — **NOT EXECUTED**

### `tests/MySql/MySqlConcurrencyTest.php` — MODIFIED

Also required on MySQL: `php artisan migrate:fresh` on a *_test database, `php artisan ssi:schema-check`, migration `down()`/`up()` round trip (`scripts/verify-all.sh` steps 08–09), and the earlier MySQL tests that pre-date this pass (`MySqlConcurrencyTest`).

## C. Frontend (vitest + jsdom) — `npm test`
| File | Tests | Status |
|---|---|---|
| `src/utils/approvalResponse.test.js` (NEW) | 10 | pure logic — executed under a **stand-in** (see Verified list); **real vitest run outstanding** |
| `src/utils/approvalView.test.js` (NEW) | 10 | same |
| `src/utils/safeStyle.test.js` (NEW) | 4 | same |
| `src/utils/sessionNotice.test.js` (NEW) | 3 | same |
| `src/utils/safeHtml.test.js` (MODIFIED: +2 `position:fixed` DOM tests, plus the 6 pre-existing) | 8 | needs **jsdom** — **NOT EXECUTED** |
| `src/components/RankBadge.test.jsx` (unchanged) | 6 | needs jsdom + testing-library — **NOT EXECUTED** |
Also outstanding: `npm run build` (Vite production build) — only a Babel parse + import/export resolution has been done, and the approvals/reversal screens were server-rendered, not built.

## D. Browser — 27 scripted rows (`BROWSER_TEST_SCRIPT.md`)
Profit adjustment submit/pending/approve/reject/reverse; balance adjustment 422 vs 202; failed → retry; correction; cumulative; cancel; self-lockout; session end for restricted staff; role gating of routes; filters; stuck/manual-verification; mobile width. **None executed.**

## E. What `php -l` / PHPUnit would catch that no executed check here could
Undefined methods/classes, wrong argument types, return-type mismatches, typos in Eloquent column names, Blade/route-cache errors, Laravel container resolution of the new services (`FinancialApprovalService` ↔ `InvestmentCorrectionService`), middleware alias registration, migration syntax on MySQL. These are the highest-probability first-run failures.
