<?php
// LOCATION: app/Support/Decimal.php
//
// Exact decimal arithmetic on NON-NEGATIVE decimal strings, used for USD posting (Financial Team §4D): keep full internal precision
// (the exact product of amount × rate) and apply decimal HALF-UP rounding ONCE, at final posting, to two decimals. No floats are
// involved in the multiplication or the rounding, so a value such as 1.005 posts as 1.01 deterministically.

namespace App\Support;

use InvalidArgumentException;

final class Decimal
{
    /** Any int/float/numeric string → a plain non-negative decimal string (no exponent). */
    public static function norm(string|int|float $v): string
    {
        if (is_float($v)) $v = sprintf('%.12F', $v);
        $v = trim((string) $v);
        if (str_contains($v, '.')) $v = rtrim(rtrim($v, '0'), '.');
        return ($v === '' || $v === '.') ? '0' : $v;
    }

    /** @return array{0: string, 1: int} [integer digits without leading zeros, number of decimals] */
    private static function split(string $v): array
    {
        $v = ltrim(trim($v), '+');
        if ($v === '' || !preg_match('/^\d*(\.\d+)?$/', $v)) {
            throw new InvalidArgumentException("Not a non-negative decimal: '{$v}'");
        }
        [$int, $frac] = array_pad(explode('.', $v, 2), 2, '');
        $digits = ltrim($int . $frac, '0');
        return [$digits === '' ? '0' : $digits, strlen($frac)];
    }

    private static function place(string $digits, int $scale): string
    {
        if ($scale === 0) return $digits;
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    private static function mulInt(string $x, string $y): string
    {
        if ($x === '0' || $y === '0') return '0';
        $lx = strlen($x); $ly = strlen($y);
        $res = array_fill(0, $lx + $ly, 0);
        for ($i = $lx - 1; $i >= 0; $i--) {
            $carry = 0; $xi = (int) $x[$i];
            for ($j = $ly - 1; $j >= 0; $j--) {
                $sum = $res[$i + $j + 1] + $xi * (int) $y[$j] + $carry;
                $res[$i + $j + 1] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            $res[$i] += $carry;
        }
        $s = ltrim(implode('', $res), '0');
        return $s === '' ? '0' : $s;
    }

    private static function increment(string $digits): string
    {
        $chars = str_split($digits);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] === '9') { $chars[$i] = '0'; continue; }
            $chars[$i] = (string) ((int) $chars[$i] + 1);
            return implode('', $chars);
        }
        return '1' . implode('', $chars);
    }

    /** Exact product, no rounding. */
    public static function mul(string $a, string $b): string
    {
        [$da, $sa] = self::split($a);
        [$db, $sb] = self::split($b);
        return self::place(self::mulInt($da, $db), $sa + $sb);
    }

    /** Decimal half-up rounding to $scale decimals (the .5 case always rounds up). */
    public static function roundHalfUp(string $v, int $scale = 2): string
    {
        [$d, $s] = self::split($v);
        if ($s <= $scale) return self::place($d . str_repeat('0', $scale - $s), $scale);

        $drop = $s - $scale;
        $d = str_pad($d, $drop + 1, '0', STR_PAD_LEFT);
        $n = strlen($d);
        $keep = substr($d, 0, $n - $drop);
        $next = (int) $d[$n - $drop];
        if ($keep === '') $keep = '0';
        if ($next >= 5) $keep = self::increment($keep);
        return self::place($keep, $scale);
    }

    /** amount × rate at full precision, posted at $scale decimals with half-up rounding. */
    public static function mulHalfUp(string $amount, string $rate, int $scale = 2): string
    {
        return self::roundHalfUp(self::mul($amount, $rate), $scale);
    }

    /** A two-decimal posted amount as integer cents (for exact summation). */
    public static function toCents(string $posted): int
    {
        return (int) str_replace('.', '', self::roundHalfUp($posted, 2));
    }

    public static function fromCents(int $cents): string
    {
        return self::place((string) abs($cents), 2);
    }

    // ── Phase B additions: signed add / sub / compare / divide / round ─────────────────────────────────────────────────────────────────
    // Everything below is exact string arithmetic (no floats, no bcmath). The original non-negative helpers above are unchanged.
    // Results keep the larger scale of the operands (add/sub) so no digit is lost; call roundSigned() at final posting.

    /** Any user/request/DB value → a plain decimal string (never throws; non-numeric input → '0'). No rounding is applied. */
    public static function fromInput(mixed $v): string
    {
        if ($v === null || is_bool($v)) return '0';
        if (is_int($v)) return (string) $v;
        if (is_float($v)) {
            if (!is_finite($v)) return '0';
            $s = (string) json_encode($v);                       // shortest round-trip form, e.g. 50000000.01 (not 50000000.009999999776)
            if (stripos($s, 'e') !== false) $s = sprintf('%.12F', $v);
            return self::cleanPlain($s);
        }
        $s = trim((string) $v);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $s) || preg_match('/^[+-]?\.\d+$/', $s)) return self::cleanPlain($s);
        if (is_numeric($s)) return self::cleanPlain(sprintf('%.12F', (float) $s));   // exponent notation etc.
        return '0';
    }

    /** A money amount from any input: plain decimal, rounded half-up (away from zero) to 2 decimals. */
    public static function money(mixed $v): string
    {
        return self::roundSigned(self::fromInput($v), 2);
    }

    private static function cleanPlain(string $s): string
    {
        $s = ltrim(trim($s), '+');
        $neg = $s !== '' && $s[0] === '-';
        if ($neg) $s = substr($s, 1);
        if ($s !== '' && $s[0] === '.') $s = '0' . $s;
        if (str_contains($s, '.')) $s = rtrim(rtrim($s, '0'), '.');
        if ($s === '' || $s === '.') $s = '0';
        return ($neg && trim($s, '0.') !== '') ? '-' . $s : $s;
    }

    private static function strip(string $digits): string
    {
        $d = ltrim($digits, '0');
        return $d === '' ? '0' : $d;
    }

    /** @return array{0: bool, 1: string} [negative, magnitude string] */
    private static function sign(string $v): array
    {
        $v = trim($v);
        $neg = false;
        if ($v !== '' && $v[0] === '-') { $neg = true; $v = substr($v, 1); }
        return [$neg, $v];
    }

    private static function signed(bool $neg, string $digits, int $scale): string
    {
        $out = self::place($digits, $scale);
        return ($neg && self::strip($digits) !== '0') ? '-' . $out : $out;
    }

    /** @return array{0: string, 1: string, 2: int} both magnitudes as integer digit strings at a common scale */
    private static function align(string $a, string $b): array
    {
        [$da, $sa] = self::split($a);
        [$db, $sb] = self::split($b);
        $s = max($sa, $sb);
        return [$da . str_repeat('0', $s - $sa), $db . str_repeat('0', $s - $sb), $s];
    }

    private static function cmpInt(string $x, string $y): int
    {
        $x = self::strip($x); $y = self::strip($y);
        if (strlen($x) !== strlen($y)) return strlen($x) <=> strlen($y);
        return strcmp($x, $y) <=> 0;
    }

    private static function addInt(string $x, string $y): string
    {
        $x = strrev($x); $y = strrev($y);
        $n = max(strlen($x), strlen($y));
        $carry = 0; $out = '';
        for ($i = 0; $i < $n; $i++) {
            $sum = (int) ($x[$i] ?? 0) + (int) ($y[$i] ?? 0) + $carry;
            $out .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry) $out .= (string) $carry;
        return self::strip(strrev($out));
    }

    /** x − y for x ≥ y (integer digit strings). */
    private static function subInt(string $x, string $y): string
    {
        $x = strrev($x); $y = strrev($y);
        $borrow = 0; $out = '';
        for ($i = 0, $n = strlen($x); $i < $n; $i++) {
            $d = (int) $x[$i] - (int) ($y[$i] ?? 0) - $borrow;
            if ($d < 0) { $d += 10; $borrow = 1; } else { $borrow = 0; }
            $out .= (string) $d;
        }
        return self::strip(strrev($out));
    }

    /** @return array{0: string, 1: string} [quotient, remainder] of integer digit strings (long division). */
    private static function divModInt(string $n, string $d): array
    {
        $q = ''; $rem = '0';
        foreach (str_split($n) as $ch) {
            $rem = self::strip($rem . $ch);
            $digit = 0;
            while (self::cmpInt($rem, $d) >= 0) { $rem = self::subInt($rem, $d); $digit++; }
            $q .= (string) $digit;
        }
        return [self::strip($q), $rem];
    }

    public static function negate(string $v): string
    {
        $v = ltrim(trim($v), '+');
        return ($v !== '' && $v[0] === '-') ? substr($v, 1) : '-' . $v;
    }

    public static function abs(string $v): string
    {
        $v = trim($v);
        return ($v !== '' && $v[0] === '-') ? substr($v, 1) : ltrim($v, '+');
    }

    /** Exact a + b (either may be negative). Scale = the larger operand scale. */
    public static function add(string $a, string $b): string
    {
        [$na, $ma] = self::sign($a);
        [$nb, $mb] = self::sign($b);
        [$da, $db, $s] = self::align($ma, $mb);
        if ($na === $nb) return self::signed($na, self::addInt($da, $db), $s);
        $c = self::cmpInt($da, $db);
        if ($c === 0) return self::signed(false, '0', $s);
        return $c > 0 ? self::signed($na, self::subInt($da, $db), $s) : self::signed($nb, self::subInt($db, $da), $s);
    }

    /** Exact a − b. */
    public static function sub(string $a, string $b): string
    {
        return self::add($a, self::negate($b));
    }

    /** −1, 0 or 1. Exact; 1.10 equals 1.1 and −0 equals 0. */
    public static function cmp(string $a, string $b): int
    {
        $d = self::sub($a, $b);
        if ($d !== '' && $d[0] === '-') return -1;
        return trim(str_replace('.', '', $d), '0') === '' ? 0 : 1;
    }

    public static function eq(string $a, string $b): bool  { return self::cmp($a, $b) === 0; }
    public static function gt(string $a, string $b): bool  { return self::cmp($a, $b) > 0; }
    public static function gte(string $a, string $b): bool { return self::cmp($a, $b) >= 0; }
    public static function lt(string $a, string $b): bool  { return self::cmp($a, $b) < 0; }
    public static function lte(string $a, string $b): bool { return self::cmp($a, $b) <= 0; }
    public static function isNegative(string $v): bool     { return self::cmp($v, '0') < 0; }

    /** Larger / smaller of two values (exact). */
    public static function max(string $a, string $b): string { return self::cmp($a, $b) >= 0 ? $a : $b; }
    public static function min(string $a, string $b): string { return self::cmp($a, $b) <= 0 ? $a : $b; }

    /** Sum of many values, exact. */
    public static function sum(iterable $values): string
    {
        $t = '0';
        foreach ($values as $v) $t = self::add($t, self::fromInput($v));
        return $t;
    }

    /** Half-up rounding to $scale decimals; a negative value rounds half AWAY from zero (−1.005 → −1.01). Same result as roundHalfUp for ≥ 0. */
    public static function roundSigned(string $v, int $scale = 2): string
    {
        [$neg, $mag] = self::sign($v);
        $r = self::roundHalfUp($mag, $scale);
        return ($neg && trim(str_replace('.', '', $r), '0') !== '') ? '-' . $r : $r;
    }

    /** a ÷ b rounded half-up (away from zero) to $scale decimals. Exact long division; throws on a zero divisor. */
    public static function div(string $a, string $b, int $scale = 2): string
    {
        [$na, $ma] = self::sign($a);
        [$nb, $mb] = self::sign($b);
        [$da, $sa] = self::split($ma);
        [$db, $sb] = self::split($mb);
        if ($db === '0') throw new InvalidArgumentException('Division by zero.');

        $n = $da . str_repeat('0', $sb + $scale);
        $d = $db . str_repeat('0', $sa);
        [$q, $rem] = self::divModInt($n, $d);
        if (self::cmpInt(self::addInt($rem, $rem), $d) >= 0) $q = self::addInt($q, '1');   // remainder ≥ half → round up
        return self::signed($na !== $nb, $q, $scale);
    }

    /** amount × percent ÷ 100, exact, posted half-up at $scale (e.g. profit = percentOf('1000', '2.5') → '25.00'). */
    public static function percentOf(string $amount, string $percent, int $scale = 2): string
    {
        [$na, $ma] = self::sign($amount);
        [$np, $mp] = self::sign($percent);
        $exact = self::mul(self::mul($ma, $mp), '0.01');
        $r = self::roundHalfUp($exact, $scale);
        return ($na !== $np && trim(str_replace('.', '', $r), '0') !== '') ? '-' . $r : $r;
    }
}
