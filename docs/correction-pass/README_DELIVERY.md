# SSI correction release — delivery index (second pass, 4 Nov 2026) — **STATUS: NOT READY** (see FINAL_CORRECTION_REPORT.md)
| File | What |
|---|---|
| `smartInvest-backend-CORRECTED.zip` | Corrected backend source (no `.env`, `vendor`, `.git`). Includes `docs/correction-pass/`, `scripts/verify-all.sh`, `Dockerfile.verify`, `docker-compose.verify.yml`, `FILE_INVENTORY.txt` |
| `smartInvest-frontend-CORRECTED.zip` | Corrected frontend source incl. Approvals inbox and profit-reversal UI; `scripts/verify-frontend.sh`; `scripts/render-harness/`. **`package-lock.json` unchanged — still lacks test packages (F-10 BLOCKED, no network)** |
| `backend-correction.patch`, `frontend-correction.patch` | Cumulative deltas against the delivered `…-REMEDIATION.zip` packages: `cd` into an unpacked REMEDIATION package, then `patch -p1 < ../backend-correction.patch`. Verified: applying each to a pristine REMEDIATION package reproduces the corrected package byte-for-byte (excluding `FILE_INVENTORY.txt`, which is in the ZIP only). Patches do not carry file modes: run `chmod 0755 docker-entrypoint.sh scripts/*.sh` after patching |
| `SHA256SUMS.txt` | `sha256sum -c SHA256SUMS.txt` |
| `FINAL_VERIFICATION_REPORT.md` | **Exact results** of everything executed, everything not, live-site rollout sequence |
| `MANAGEMENT_DEFAULTS_REGISTER.md` | The 12 defaults in force, classification, confirmation required?, switches — **none approved by Management** |
| `FINAL_CORRECTION_REPORT.md` | Master report: baseline, R1–R13, F-01–F-16, API-change register, blockers, **§8 response to the 9 review items** |
| `VERIFIED_VS_UNEXECUTED.md` | **Item 7** — three tiers: executed / statically checked / written-not-executed |
| `OUTSTANDING_TESTS.md` | **Item 6** — every outstanding PHP, MySQL, frontend and browser test, with commands |
| `BROWSER_TEST_SCRIPT.md` | 27 manual browser rows (none executed) |
| `R8_APPROVAL_SERVICE_REVIEW.md`, `R9_MIDDLEWARE_REVIEW.md`, `R12_TEST_REVIEW.md` | Items 4, 5, 2 |
| `TEST_EXECUTION_REPORT.md`, `REMAINING_MANAGEMENT_DECISIONS.md`, `SECURITY_REVIEW.md`, `DATABASE_MIGRATION_AND_ROLLBACK.md`, `DEPLOYMENT_CHECKLIST.md`, `CHANGELOG.md`, `R6_ROUNDING_COMPARISON.md` | Required documentation |
| `evidence/` | Scripts and captured output of everything that was actually executed (read `VERIFIED_VS_UNEXECUTED.md` first: several are stand-ins or stub-only and say so) |
**Nothing was run against PHP, MySQL, Docker, npm/Vite or a browser** — none was available (re-checked). On a suitable machine: `bash scripts/verify-all.sh` (backend) and `LOCK_REGEN=1 bash scripts/verify-frontend.sh` (frontend), then `BROWSER_TEST_SCRIPT.md`.
