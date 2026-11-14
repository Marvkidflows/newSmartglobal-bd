# Phase 4: investor-rank CSV export

FINDING: a full export already existed (GET /api/admin/ranks/export, GET /api/financial/rank-export). It streams in chunks, requires ranks.export,
is throttled (10/min), and neutralises spreadsheet-formula injection (leading = + - @ tab CR). Its test passed in your suite.

GAPS CLOSED (implemented, NOT EXECUTED until you run the tests):
* Every export (investors, history, and the benefits/consents export) is now recorded in the append-only `rank_benefit_audit` table as subject `rank_export`, action `exported`, with actor, role, type, filters and file name. Written before any data is streamed; a refused request writes nothing.
* The "Rank changed" column was always empty; it now carries users.rank_changed_at (UTC).
* Four columns appended at the END (earlier columns keep their positions): Next rank, Progress to next rank (%), Currency records awaiting verification or review, Calculated at (UTC).
* Progress = the lower of the mandatory money and cycle conditions from the dashboard's own calculation; blank when the next rank has no active usable rule or the highest rank is held.

NOT CHANGED: no sensitive authentication data is exported (name, email, account id and rank figures only, as before); permissions and throttle are unchanged.
Tests: tests/Feature/Rank/RankExportAuditTest.php
