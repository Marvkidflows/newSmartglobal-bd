#!/usr/bin/env python3
"""R6 evidence: previous deposit USD valuation  round(round(paid,2) * fx, 2)  [float, PHP round()]  vs  new exact-decimal half-up.
PHP round() is emulated two ways because behaviour depends on the PHP version (Dockerfile uses 8.4; <=8.3 pre-rounds to 15 significant digits):
  A = 'naive'  : floor(x*100 + 0.5)/100  (no pre-rounding)      B = 'preround' : PHP <= 8.3 _php_math_round
This is a SIMULATION of PHP in Python, not a PHP run. It proves the comparison logic and magnitude; it must be re-run on real data in staging."""
import math, random
from decimal import Decimal, ROUND_HALF_UP, getcontext
getcontext().prec = 60
def helper(v): return math.floor(v + 0.5) if v >= 0 else math.ceil(v - 0.5)
def round_naive(x, places=2):
    if x == 0 or not math.isfinite(x): return x
    f = 10 ** places; t = x * f
    if abs(t) >= 1e15: return x
    return helper(t) / f
def round_pre(x, places=2):
    if x == 0 or not math.isfinite(x): return x
    precision_places = 14 - int(math.floor(math.log10(abs(x))))
    f1 = 10 ** abs(places)
    if precision_places > places and precision_places - 15 < places:
        use = max(precision_places, -4 * 15)
        basic = x * 10 ** abs(use) if use >= 0 else x / 10 ** abs(use)
        t = helper(basic)
        use2 = max(places - use, -4 * 15)
        t = t / 10 ** abs(use2) if use2 < 0 else t * 10 ** abs(use2)
    else:
        t = x * f1 if places >= 0 else x / f1
        if abs(t) >= 1e15: return x
    t = helper(t)
    return t / f1 if places > 0 else t * f1
def new(paid_cents, rate):
    paid = Decimal(paid_cents) / 100
    return (paid * Decimal(rate)).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)
random.seed(20261103)
N = 2_000_000; diffs = {'naive': [], 'preround': []}; ties = 0
for _ in range(N):
    cents = random.choice([random.randint(1, 10_000), random.randint(1, 5_000_000), random.randint(1, 500_000_000)])
    dp = random.choice([2, 3, 4, 5, 6, 8]); rate = f"{random.uniform(0.00005, 2.5):.{dp}f}"
    if Decimal(rate) == 0: continue
    n = new(cents, rate); ex = (Decimal(cents) / 100) * Decimal(rate)
    if (ex * 100) % 1 == Decimal('0.5'): ties += 1
    paid = round(cents / 100, 2); fx = float(rate)
    for name, fn in (('naive', round_naive), ('preround', round_pre)):
        o = Decimal(repr(fn(paid * fx)))
        if o.quantize(Decimal('0.01')) != n: diffs[name].append((cents, rate, str(o.quantize(Decimal('0.01'))), str(n)))
print(f"cases: {N:,}   exact half-cent ties: {ties:,}")
for k, v in diffs.items():
    mx = max((abs(Decimal(a[2]) - Decimal(a[3])) for a in v), default=Decimal(0))
    print(f"[{k}] cases where old != new: {len(v):,} ({100*len(v)/N:.4f}%)   max |difference| = {mx} USD")
    for a in v[:5]: print(f"    paid={a[0]/100:,.2f} rate={a[1]}  old={a[2]}  new(half-up)={a[3]}")
# deterministic worked tie examples
for cents, rate in [(10, '0.5'), (1, '0.5'), (1005, '0.01'), (12345, '0.0005'), (100, '1.005'), (1, '1.5')]:
    n = new(cents, rate); p = cents / 100
    print(f"tie-check paid={p} rate={rate}: exact={Decimal(cents)/100*Decimal(rate)}  old(naive)={round_naive(p*float(rate))}  old(preround)={round_pre(p*float(rate))}  new={n}")
