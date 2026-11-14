> **SUPERSEDED (4–5 Nov 2026):** the correction passes changed behaviour described below. Use `docs/correction-pass/` as the current source of truth.

# Staging release checklist (tick only with evidence attached)
- [ ] Credentials rotated; staging DB isolated from production
- [ ] `php -l` clean on all changed files
- [ ] `php artisan test` (SQLite) — counts recorded: ____ passed / ____ failed
- [ ] MySQL concurrency suite — 4/4 pass
- [ ] Rank suite before/after comparison on staging copy (patch 02, F-09)
- [ ] `migrate --pretend` SQL reviewed; migrate role exit 0; `migrate:status` clean
- [ ] Failed-migration drill: pipeline stops, web not started
- [ ] Reconciliation CSV produced and handed to Finance (no data changed)
- [ ] Approval flows smoke-tested with two different users (no self-approval)
- [ ] Suspended staff drill (login + live token)
- [ ] XSS payloads rendered in browser: no script executes
- [ ] `npm ci && npm test && npm run build` green; lockfile committed
- [ ] ROI / lending / separate-deposit / ledger flags confirmed OFF
- [ ] Management decisions D1–D12 recorded; no production deploy without separate production review
