<?php
// TASK 3 — small helper for provider-supplied strings stored in the database.
namespace App\Services\Mailing;

class SafeText
{
    public static function reason($v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        return MessageSender::safeError(strip_tags($v));
    }
}
