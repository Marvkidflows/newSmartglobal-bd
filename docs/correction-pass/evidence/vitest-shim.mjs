// MINIMAL vitest stand-in used ONLY to execute the pure-logic test files offline. It is NOT vitest (no jsdom, no DOM, no snapshots).
import assert from 'node:assert/strict';
const tests = []; let prefix = [];
export const describe = (n, fn) => { prefix.push(n); fn(); prefix.pop(); };
export const it = (n, fn) => tests.push([[...prefix, n].join(' › '), fn]); export const test = it;
export const vi = { fn: (impl) => { const f = (...a) => { f.mock.calls.push(a); return impl ? impl(...a) : undefined; }; f.mock = { calls: [] }; return f; } };
const matchers = (v, neg) => {
  const chk = (ok, msg) => { if (neg ? ok : !ok) assert.fail((neg ? 'NOT ' : '') + msg); };
  return {
    toBe: (e) => chk(Object.is(v, e), `expected ${JSON.stringify(v)} toBe ${JSON.stringify(e)}`),
    toEqual: (e) => { try { assert.deepStrictEqual(JSON.parse(JSON.stringify(v)), JSON.parse(JSON.stringify(e))); chk(true); } catch (x) { chk(false, 'toEqual ' + x.message.split('\n')[0]); } },
    toContain: (e) => chk(typeof v === 'string' ? v.includes(e) : v.includes(e), `expected ${JSON.stringify(v)} toContain ${JSON.stringify(e)}`),
    toBeNull: () => chk(v === null, `expected null got ${JSON.stringify(v)}`),
    toBeTruthy: () => chk(!!v, 'truthy'), toBeFalsy: () => chk(!v, 'falsy'),
    toHaveBeenCalled: () => chk(v.mock.calls.length > 0, 'expected a call'),
    toHaveBeenCalledWith: (...a) => chk(v.mock.calls.some(c => JSON.stringify(c) === JSON.stringify(a)), `expected call with ${JSON.stringify(a)}; calls=${JSON.stringify(v.mock.calls)}`),
    toMatch: (re) => chk(re.test(v), `expected ${v} toMatch ${re}`),
  };
};
export const expect = (v) => ({ ...matchers(v, false), not: matchers(v, true) });
export async function run() {
  let pass = 0, fail = 0;
  for (const [n, fn] of tests) { try { await fn(); pass++; console.log('PASS', n); } catch (e) { fail++; console.log('FAIL', n, '\n     ', e.message.split('\n')[0]); } }
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
}
