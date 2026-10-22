#!/usr/bin/env node
// scripts/concurrent-approval-check.mjs
//
// LOCAL TEST TOOL. Fires N approval requests for ONE deposit at the same instant and reports what the server did.
// It proves nothing by itself about the database - run the SQL checks in RANKS_7_FINAL_VERIFICATION_REPORT.md after it.
//
// Safety rails (it exits before sending anything if any is not met):
//   * BASE_URL must be localhost / 127.0.0.1 / ::1 - it will never talk to a remote host.
//   * CONFIRM_LOCAL_TEST_DB=yes - you are asserting that you verified the app points at the dedicated local test database.
//   * It approves a deposit, i.e. it credits a balance and opens an investment. Use a synthetic deposit only.
//
// Environment (names only; nothing is printed back):
//   FIN_EMAIL, FIN_PASSWORD   a synthetic Financial Team (or Admin) account on the local app
//   DEPOSIT_ID                a pending deposit whose payment has ALREADY been recorded/verified by staff
//   BASE_URL                  default http://localhost:8000
//   PARALLEL                  default 2 (max 10)
//   APPROVE_PATH_PREFIX       default /api/financial   (use /api/admin for an Admin account)
//
// Usage (PowerShell):
//   $env:CONFIRM_LOCAL_TEST_DB="yes"; $env:FIN_EMAIL="finance@demo.invalid"; $env:FIN_PASSWORD="<local password>"; $env:DEPOSIT_ID="12"
//   node scripts/concurrent-approval-check.mjs

const BASE = (process.env.BASE_URL || 'http://localhost:8000').replace(/\/+$/, '');
const PARALLEL = Math.min(Math.max(parseInt(process.env.PARALLEL || '2', 10) || 2, 2), 10);
const PREFIX = process.env.APPROVE_PATH_PREFIX || '/api/financial';
const { FIN_EMAIL, FIN_PASSWORD, DEPOSIT_ID, CONFIRM_LOCAL_TEST_DB } = process.env;

function fail(msg) { console.error('REFUSING TO RUN: ' + msg); process.exit(2); }

let host;
try { host = new URL(BASE).hostname; } catch { fail('BASE_URL is not a valid URL.'); }
if (!['localhost', '127.0.0.1', '::1', '[::1]'].includes(host)) fail(`BASE_URL host "${host}" is not local. This tool only runs against localhost.`);
if (CONFIRM_LOCAL_TEST_DB !== 'yes') fail('Set CONFIRM_LOCAL_TEST_DB=yes only after confirming the app uses the dedicated local test database.');
if (!FIN_EMAIL || !FIN_PASSWORD || !DEPOSIT_ID) fail('FIN_EMAIL, FIN_PASSWORD and DEPOSIT_ID are required.');
if (!/^\d+$/.test(DEPOSIT_ID)) fail('DEPOSIT_ID must be a number.');

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

async function call(method, path, token, body) {
  const res = await fetch(BASE + path, {
    method,
    headers: { ...JSON_HEADERS, ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON body */ }
  return { status: res.status, data };
}

const depositStatus = (d) => d?.status ?? d?.deposit?.status ?? d?.data?.status ?? '(unknown)';

const login = await call('POST', '/api/login', null, { email: FIN_EMAIL, password: FIN_PASSWORD });
const token = login.data?.token;
if (login.status !== 200 || !token) fail(`login failed (HTTP ${login.status}). Check the synthetic account and that the server is running.`);

const before = await call('GET', `${PREFIX}/deposits/${DEPOSIT_ID}`, token);
if (before.status !== 200) fail(`could not read deposit ${DEPOSIT_ID} (HTTP ${before.status}).`);
console.log(`Deposit ${DEPOSIT_ID} status BEFORE: ${depositStatus(before.data)}`);
if (!['pending', 'hold'].includes(String(depositStatus(before.data)).toLowerCase())) {
  fail('the deposit is not pending/hold, so this would not test a first approval. Use a fresh pending deposit.');
}

console.log(`Sending ${PARALLEL} simultaneous approve requests to ${BASE}${PREFIX}/deposits/${DEPOSIT_ID}/approve ...`);
// Build every request first, then release them together so they genuinely overlap.
const fire = Array.from({ length: PARALLEL }, () => () => call('POST', `${PREFIX}/deposits/${DEPOSIT_ID}/approve`, token));
const results = await Promise.all(fire.map((f) => f()));

results.forEach((r, i) => console.log(`  request ${i + 1}: HTTP ${r.status}  code=${r.data?.code ?? '-'}  message=${String(r.data?.message ?? '').slice(0, 110)}`));

const after = await call('GET', `${PREFIX}/deposits/${DEPOSIT_ID}`, token);
console.log(`Deposit ${DEPOSIT_ID} status AFTER: ${depositStatus(after.data)}`);

const ok = results.filter((r) => r.status >= 200 && r.status < 300).length;
const serverErrors = results.filter((r) => r.status >= 500).length;
console.log(`\nSummary: ${ok} succeeded, ${results.length - ok - serverErrors} refused (4xx), ${serverErrors} server errors.`);

if (ok === 1 && serverErrors === 0) {
  console.log('RESULT: as expected - exactly one approval succeeded. NOW run the SQL checks to confirm one credit, one ledger row and one investment.');
  process.exit(0);
}
console.log(serverErrors > 0 ? 'RESULT: server errors occurred - check storage/logs/laravel.log before drawing any conclusion.'
          : ok > 1 ? 'RESULT: *** MORE THAN ONE request succeeded - possible double approval. Stop and inspect balance/ledger/investments. ***'
                   : 'RESULT: NO request succeeded - the deposit may not have been ready (payment not recorded?). Inspect the messages above.');
process.exit(1);
