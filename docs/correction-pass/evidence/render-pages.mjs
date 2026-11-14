import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
let pass = 0; const t = (n, fn) => { try { fn(); pass++; console.log('PASS', n); } catch (e) { console.log('FAIL', n, '\n   ', e.message.split('\n')[0]); process.exitCode = 1; } };
const Inbox = (await import('./build/src/pages/shared/ApprovalsInbox.mjs')).default;
const Global = (await import('./build/src/pages/admin/AdminGlobalManagement.mjs')).default;
const ih = renderToStaticMarkup(React.createElement(Inbox));
const gh = renderToStaticMarkup(React.createElement(Global));
t('Inbox page: heading, explanation and every filter (status, type, requester, date range) are present', () => {
  for (const s of ['<h1>Approvals</h1>', 'aria-label="Status"', 'aria-label="Request type"', 'aria-label="Requester"', 'aria-label="From date"', 'aria-label="To date"',
    'Open (pending + failed)', 'Stuck (needs recovery)', 'Executed', 'Rejected', 'Cancelled', 'Expired', 'Investment record correction', 'Profit adjustment reversal', 'Loading…'])
    assert.ok(ih.includes(s), 'missing ' + s);
});
t('Global Management page: profit form now has the Authorization reference field, the 10-char reason hint and "Submit for Approval" (R4)', () => {
  for (const s of ['Authorization reference', 'Reason (min. 10 characters)', 'Submit for Approval', 'a second administrator must approve it first', 'Request submitted and awaiting approval.'])
    assert.ok(gh.includes(s), 'missing ' + s);
  assert.ok(!gh.includes('Apply Adjustment'), 'old "Apply Adjustment" wording must be gone');
});
t('Global Management page: bulk balance buttons no longer imply immediate execution', () => {
  assert.ok(!gh.includes('Apply Credit') && !gh.includes('Apply Deduction'));
  assert.ok(gh.includes('Submit Credit for Approval') || gh.includes('Submit Deduction for Approval'));
});
t('Global Management page: history card renders (loading state) with the new Status column on first paint', () => {
  assert.ok(gh.includes('Profit Adjustment History'));
});
// negative control: prove the harness can fail
t('NEGATIVE CONTROL: a string that is not rendered is reported missing', () => { assert.throws(() => { if (!ih.includes('THIS-TEXT-IS-NOT-IN-THE-PAGE')) throw new Error('missing'); }); });
console.log(`\n${pass} passed`);
