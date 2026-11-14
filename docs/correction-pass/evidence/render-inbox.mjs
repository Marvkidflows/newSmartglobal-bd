// Executes the REAL ApprovalsInbox presentational components (server-side render via react-dom/server) against fixtures shaped like the backend's
// FinancialApprovalController::present() output. Verifies what each role/state would SEE. Not a browser: no events, effects or network.
import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
const { ApprovalDetailView, ApprovalsTable } = await import('./build/src/pages/shared/ApprovalsInbox.mjs');

const base = (o = {}) => ({ id: 41, operation: 'investment_correction', status: 'pending', is_expired: false, is_stuck: false, reason: 'Correction requested after record review.',
  authorization_reference: 'CASE-2026-114', requested_by: { id: 5, name: 'Fin Requester', role: 'financial' }, decided_by: null,
  target: { id: 9, name: 'Ada Investor', email: 'ada@example.com' },
  change: { kind: 'investment', investment_id: 7, before: { amount: '1000.00', profit_percentage: '10.00', expected_profit: '100.00', total_return: '1100.00' }, proposed: { amount: '2000.00', profit_percentage: '10.00' }, after: null },
  decision_note: null, last_error: null, execution_attempts: 0, result: null, created_at: '2026-11-04 09:00:00', decided_at: null, executed_at: null, expires_at: null,
  cancelled_at: null, cancel_reason: null, can_decide: true, can_retry: false, can_cancel: false, is_own: false,
  events: [{ id: 1, event: 'submitted', from_status: null, to_status: 'pending', note: null, actor: { id: 5, name: 'Fin Requester', role: 'financial' }, created_at: '2026-11-04 09:00:00' }], ...o });
const noop = () => {};
const view = (req) => renderToStaticMarkup(React.createElement(ApprovalDetailView, { req, note: '', onNote: noop, busy: false, onClose: noop, onApprove: noop, onReject: noop, onRetry: noop, onCancel: noop }));
const has = (html, s, msg) => assert.ok(html.includes(s), `${msg || 'expected'}: ${s}\n--- html ---\n${html.slice(0, 600)}`);
const hasNot = (html, s, msg) => assert.ok(!html.includes(s), `${msg || 'unexpected'}: ${s}`);
let pass = 0; const t = (name, fn) => { try { fn(); pass++; console.log('PASS', name); } catch (e) { console.log('FAIL', name, '\n   ', e.message.split('\n')[0]); process.exitCode = 1; } };

t('eligible approver sees Approve and Reject, the before/after table, reference, requester, reason and audit history', () => {
  const h = view(base());
  has(h, 'Request #41'); has(h, 'Investment record correction'); has(h, 'CASE-2026-114'); has(h, 'Fin Requester (financial)'); has(h, 'Correction requested after record review.');
  has(h, 'Ada Investor (ada@example.com)'); has(h, '$1,000.00'); has(h, '$2,000.00'); has(h, 'recalculated on approval'); has(h, 'After (proposed)');
  has(h, 'data-action="approve"'); has(h, 'data-action="reject"'); hasNot(h, 'data-action="retry"'); hasNot(h, 'data-action="cancel"'); has(h, 'Audit history'); has(h, 'submitted');
});
t('SELF-APPROVAL/REJECTION: the requester sees no Approve/Reject, sees the explanation, and can Cancel', () => {
  const h = view(base({ can_decide: false, is_own: true, can_cancel: true }));
  hasNot(h, 'data-action="approve"'); hasNot(h, 'data-action="reject"'); has(h, 'data-action="cancel"'); has(h, 'You submitted this request'); has(h, 'data-testid="blocker"');
});
t('UNAUTHORISED ROLE: no decision buttons and the role explanation is shown', () => {
  const h = view(base({ can_decide: false, is_own: false }));
  hasNot(h, 'data-action="approve"'); hasNot(h, 'data-action="reject"'); hasNot(h, 'data-action="cancel"'); has(h, 'Your role cannot decide this type of request.');
  hasNot(h, 'Note / reason');
});
t('FAILED execution: red banner with the server reason, attempts, Retry shown, Approve hidden', () => {
  const h = view(base({ status: 'failed', can_decide: false, can_retry: true, last_error: "The investment's amount changed after this request was made.", execution_attempts: 2 }));
  has(h, 'Execution failed — nothing was changed.'); has(h, 'amount changed after this request'); has(h, 'Attempts: 2'); has(h, 'failed (attempt 2)'); has(h, 'badge-danger');
  has(h, 'data-action="retry"'); hasNot(h, 'data-action="approve"'); has(h, 'role="alert"');
});
t('FAILED request viewed by its own requester: no Retry (self), explanation shown', () => {
  const h = view(base({ status: 'failed', can_decide: false, can_retry: false, is_own: true, can_cancel: true, last_error: 'x', execution_attempts: 1 }));
  hasNot(h, 'data-action="retry"'); has(h, 'You submitted this request'); has(h, 'data-action="cancel"');
});
t('STUCK (legacy approved / interrupted): banner + recovery instruction, no action buttons', () => {
  const h = view(base({ status: 'approved', is_stuck: true, can_decide: false, last_error: null }));
  has(h, 'Execution did not complete.'); has(h, 'ssi:approvals-maintain'); has(h, 'stuck — needs recovery'); hasNot(h, 'data-action="approve"'); hasNot(h, 'data-action="retry"');
});
t('MANUAL-VERIFICATION request: no Retry, explicit explanation, banner with the recovery text', () => {
  const h = view(base({ status: 'failed', can_decide: false, can_retry: false, needs_manual_verification: true, last_error: 'Interrupted; execution state could not be determined automatically. MANUAL VERIFICATION REQUIRED before any retry.', execution_attempts: 1, can_cancel: true }));
  hasNot(h, 'data-action="retry"'); has(h, 'cannot be retried'); has(h, 'MANUAL VERIFICATION REQUIRED'); has(h, 'data-action="cancel"');
});
t('EXECUTED: shows executed after-values from the result, no action buttons, no "(proposed)"', () => {
  const h = view(base({ status: 'executed', can_decide: false, executed_at: '2026-11-04 10:00:00', decided_by: { id: 6, name: 'Admin Two', role: 'admin' }, decided_at: '2026-11-04 10:00:00',
    change: { ...base().change, after: { amount: '2000.00', profit_percentage: '10.00', expected_profit: '200.00', total_return: '2200.00' } } }));
  has(h, '$200.00'); has(h, '$2,200.00'); has(h, 'Executed'); has(h, 'Admin Two (admin)'); hasNot(h, '(proposed)'); hasNot(h, 'data-action="approve"'); hasNot(h, 'recalculated on approval'); has(h, 'badge-success');
});
t('CANCELLED shows the cancellation reason; REJECTED shows the decision note', () => {
  has(view(base({ status: 'cancelled', can_decide: false, cancel_reason: 'raised in error' })), 'Cancelled: raised in error');
  has(view(base({ status: 'rejected', can_decide: false, decision_note: 'Not supported by evidence' })), 'Decision note: Not supported by evidence');
});
t('BALANCE adjustment shows live and projected wallet balance', () => {
  const h = view(base({ operation: 'balance_adjust', change: { kind: 'balance', type: 'deduct', amount: '15000.00', current_balance: '20000.00', projected_balance: '5000.00', note: 'Current balance is read live; the balance is re-validated again when the request executes.' } }));
  has(h, 'Business'.slice(0, 0) + 'Balance adjustment'); has(h, 'Deduction'); has(h, '$15,000.00'); has(h, '$20,000.00'); has(h, '$5,000.00'); has(h, 're-validated again when the request executes');
});
t('EXPIRED pending request is shown as expired with no decision buttons', () => {
  const h = view(base({ status: 'expired', is_expired: true, can_decide: false, expires_at: '2026-11-05 09:00:00' }));
  hasNot(h, 'data-action="approve"'); has(h, 'expired'); has(h, 'Expires');
});
t('LIST: rows show type, change summary, requester, "you" marker, and Review vs View per permission', () => {
  const rows = [base(), base({ id: 42, can_decide: false, is_own: true }), base({ id: 43, status: 'failed', can_decide: false, can_retry: true, execution_attempts: 1 })];
  const h = renderToStaticMarkup(React.createElement(ApprovalsTable, { rows, onOpen: noop }));
  has(h, 'Investment record correction'); has(h, '$1,000.00 → $2,000.00'); has(h, '· you'); has(h, 'Review'); has(h, 'View'); has(h, 'failed (attempt 1)'); has(h, 'badge-warning');
});
t('LIST: empty state', () => { has(renderToStaticMarkup(React.createElement(ApprovalsTable, { rows: [], onOpen: noop })), 'No approval requests match these filters.'); });
console.log(`\n${pass} passed${process.exitCode ? ', SOME FAILED' : ', 0 failed'}`);
