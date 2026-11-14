# Task 5 - Financial permission matrix (enforced server-side)
Gates are default-deny (`role` must be `financial` or `admin`). Route group middleware (`FinancialMiddleware`, suspended-staff refusal) is a first layer; each controller action re-checks its Gate; services re-check at execution time.

| Action | Super Admin / Admin | Financial | Registration Team* / MarvFlow | Investor | Guest |
|---|---|---|---|---|---|
| View referral dashboard/ledger/history/export (`financial.view-referrals`) | Yes | Yes | No (403) | No (403) | 401 |
| Evaluate, approve, reject, credit, flag/unflag (`financial.manage-referrals`) | Yes | Yes (not for own reward) | No | No | 401 |
| Draft referral amount/criteria version (`financial.configure-referral-policy`) | Yes | Yes | No | No | 401 |
| **Declare referral policy Management-approved** | **Yes** (reference required) | No | No | No | - |
| **Reverse a credited reward** (`financial.reverse-referral-reward`) | **Yes** | No | No | No | - |
| View fee policy/report/reconciliation (`financial.view-withdrawal-fees`) | Yes | Yes | No | No | 401 |
| Draft fee policy version (`financial.configure-withdrawal-fee`) | Yes | Yes | No | No | 401 |
| **Declare fee policy Management-approved** | **Yes** (reference required) | No | No | No | - |
| Reverse / waive / flag a collected fee (`financial.adjust-withdrawal-fee`) | Yes | Yes | No | No | 401 |
| ...with refund to wallet | needs existing `financial.adjust-balance` | same | No | No | - |
| Approve/reject/hold withdrawals (existing, unchanged) | Yes | Yes | No | No | - |
| View own referrals/rewards; quote and submit own withdrawal | n/a | n/a | n/a | Yes (own data only) | - |

*The Task 2 Registration Team role does not exist in this codebase yet; every Task 5 gate denies any role other than `financial`/`admin`, so it will be denied automatically. Re-verify when Task 2 is merged.
Investors cannot: change `referred_by`/`referral_code` (no endpoint accepts it; profile validation omits it), set a reward status, set any fee/net/status/reference/policy field on a withdrawal (server-built via `forceFill`; request values are ignored), or submit a fee different from the one the server recalculates (422 `FEE_CONFIRMATION_REQUIRED`).
