# R6 — deposit USD valuation: previous vs exact-decimal rounding
**What changed in the first remediation:** `DepositPaymentService` replaced `round(round($paid,2) * $fx, 2)` (float) with exact-decimal `mulHalfUp(paid, rate, 2)`. Verified USD feeds rank thresholds (cent boundaries matter).
**What this pass did:** restored the **original calculation as the default** (`FINANCIAL_DEPOSIT_VALUATION_MODE=legacy_float`) and kept the exact version selectable (`exact_decimal`). No rank file was touched. No rate is ever defaulted to 1 (unchanged).

## Evidence (executed — a Python *emulation* of PHP `round()`, see `evidence/r6_rounding_comparison.py`)
2,000,000 random deposits (cents 0.01–5,000,000.00; rates 0.00005–2.5 with 2–8 decimals); 10,765 of them are exact half-cent ties.
| PHP behaviour emulated | Cases where old ≠ new | Max difference |
|---|---|---|
| ≤ 8.3 (pre-rounds to 15 significant digits) | **0** | — |
| no pre-rounding (naive `floor(x*100+0.5)`; the Dockerfile uses **PHP 8.4**) | **1,420 (0.071 %)** | **0.01 USD** |
Examples (naive emulation): 68.10 × 1.95 → old 132.79, exact 132.80; 96.46 × 1.75 → 168.80 vs 168.81; 17.74 × 0.25 → 4.43 vs 4.44; 1,087,364.50 × 2.11 → 2,294,339.09 vs 2,294,339.10.
**Caveat:** PHP 8.4's exact `round()` algorithm differs from my naive emulation in details; the true number must be measured on real PHP 8.4. The direction is clear: exact-decimal is the mathematically correct half-up of the recorded values, and the legacy float result is off by one cent in rare cases.

## Rank impact
A one-cent difference can only change rank qualification when a cumulative verified-USD total lands within 0.01 of a configured threshold. This cannot be quantified without production data. **Action for Finance:** on staging data run both modes over all verified deposits, list every investor whose total differs, and compare each against `config/ranks*.php` thresholds. Report any whose qualification differs. Switch to `exact_decimal` only on written approval (decision #5).

## Other float/decimal notes
- Investor investment `expected_profit`: the original wrote an unrounded float into a 2-dp column (the database rounded it); the first remediation computes it exactly. Differences only where float representation sits on a half-cent. Not gated; listed for Finance.
- `UsdValuationService` and rank-engine float maths (`round($principalUsd*100)`): **not modified**, by instruction.
- Converted in this pass to decimal arithmetic: balance adjustments (add/deduct/reset), investment corrections (expected profit/total return), task result credits. Settlement and profit adjustments were already decimal.
