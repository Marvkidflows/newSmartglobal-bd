<?php
// LOCATION: app/Services/Rank/CurrencyCatalog.php
//
// The list of currency codes a person may state when verifying an investment / deposit amount.
// Source: the ISO-4217 data that ships with PHP's intl extension (already required by this project), plus the
// extra codes in config('ranks.extra_currencies') (e.g. stablecoins used on this platform). This exists so a
// typo ("NRG") cannot be recorded as a currency. It does NOT supply exchange rates — a rate is always typed in,
// with its source and date, by the person verifying the record.

namespace App\Services\Rank;

class CurrencyCatalog
{
    /** Used only if the ICU currency data is unavailable on the server. */
    protected const FALLBACK = [
        'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'NGN' => 'Nigerian Naira', 'GHS' => 'Ghanaian Cedi',
        'KES' => 'Kenyan Shilling', 'ZAR' => 'South African Rand', 'XOF' => 'West African CFA Franc', 'XAF' => 'Central African CFA Franc',
        'CAD' => 'Canadian Dollar', 'AUD' => 'Australian Dollar', 'CHF' => 'Swiss Franc', 'JPY' => 'Japanese Yen', 'CNY' => 'Chinese Yuan',
        'INR' => 'Indian Rupee', 'AED' => 'UAE Dirham', 'SAR' => 'Saudi Riyal', 'EGP' => 'Egyptian Pound', 'MAD' => 'Moroccan Dirham',
        'TZS' => 'Tanzanian Shilling', 'UGX' => 'Ugandan Shilling', 'RWF' => 'Rwandan Franc', 'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso',
        'SEK' => 'Swedish Krona', 'NOK' => 'Norwegian Krone', 'DKK' => 'Danish Krone', 'PLN' => 'Polish Zloty', 'TRY' => 'Turkish Lira',
    ];

    /** Pseudo-codes ICU lists that are never a real currency of an amount. */
    protected const EXCLUDED = ['XXX', 'XTS', 'XAU', 'XAG', 'XPT', 'XPD', 'XDR', 'XBA', 'XBB', 'XBC', 'XBD', 'XSU', 'XUA', 'XRE', 'XFU'];

    protected static ?array $cache = null;

    /** @return array<string, string> code => name, sorted by code */
    public function all(): array
    {
        if (static::$cache !== null) return static::$cache;

        $list = [];
        try {
            if (class_exists(\ResourceBundle::class)) {
                $bundle = \ResourceBundle::create('en', 'ICUDATA-curr');
                $cur    = $bundle ? $bundle->get('Currencies') : null;
                if ($cur) {
                    foreach ($cur as $code => $row) {
                        if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && !in_array($code, self::EXCLUDED, true)) {
                            $list[$code] = (string) ($row[1] ?? $code);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $list = [];
        }
        if (!$list) $list = self::FALLBACK;

        foreach ((array) config('ranks.extra_currencies', []) as $code => $name) {
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{3,8}$/', $code)) $list[$code] = (string) $name;
        }
        // The ranking currency must always be selectable.
        $list[config('ranks.ranking_currency', 'USD')] ??= 'US Dollar';

        ksort($list);
        return static::$cache = $list;
    }

    public function isSupported(string $code): bool
    {
        return array_key_exists(strtoupper(trim($code)), $this->all());
    }

    public function name(string $code): ?string
    {
        return $this->all()[strtoupper(trim($code))] ?? null;
    }

    /** Test helper: forget the cached list (config may have changed). */
    public static function flush(): void
    {
        static::$cache = null;
    }
}
