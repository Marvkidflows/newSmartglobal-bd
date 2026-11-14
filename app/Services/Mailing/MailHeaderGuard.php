<?php
// TASK 3 — e-mail header-injection protection. Anything that can end up in a header (subject, names, addresses) passes through here.
namespace App\Services\Mailing;

class MailHeaderGuard
{
    /** True if the value tries to break out of a header (CR/LF, NULs, URL-encoded CR/LF, or an embedded header name after a break). */
    public static function hasInjection(?string $v): bool
    {
        if ($v === null || $v === '') return false;
        return (bool) preg_match('/[\r\n\0]|%0[ad]/i', $v) || (bool) preg_match('/[\x{2028}\x{2029}]/u', $v);
    }

    /** Single-line, control-character-free text suitable for a header. */
    public static function text(?string $v, int $max = 190): string
    {
        $v = (string) $v;
        $v = preg_replace('/%0[ad]/i', ' ', $v) ?? $v;
        $clean = preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $v);
        if ($clean === null) $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? '';
        $clean = trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);
        return mb_substr($clean, 0, $max);
    }

    /** Lower-cased, validated, injection-free e-mail address, or null. */
    public static function address(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || strlen($email) > 190 || self::hasInjection($email)) return null;
        if (preg_match('/[\s,;<>"\']/', $email)) return null;                      // exactly one plain address — no lists, no display-name tricks
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
