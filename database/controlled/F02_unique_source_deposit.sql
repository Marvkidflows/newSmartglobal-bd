-- F-02 CONTROLLED SCHEMA STEP — NOT a Laravel migration, NOT run automatically, NOT run on production without written Management + Financial Team approval.
-- Purpose: make a second investment for the same source deposit impossible at database level (the application already refuses it under a row lock).
-- Step 1 (read-only): MUST return zero rows. If it returns rows, STOP: those are existing duplicate positions that need human review; do not delete them.
SELECT source_deposit_id, COUNT(*) AS positions, GROUP_CONCAT(id) AS investment_ids
FROM investment_accounts
WHERE source_deposit_id IS NOT NULL
GROUP BY source_deposit_id
HAVING COUNT(*) > 1;

-- Step 2 (only after step 1 returned zero rows and approval is recorded). Reversible.
ALTER TABLE investment_accounts ADD UNIQUE INDEX uq_investment_accounts_source_deposit (source_deposit_id);
-- Rollback:
-- ALTER TABLE investment_accounts DROP INDEX uq_investment_accounts_source_deposit;
