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
}
