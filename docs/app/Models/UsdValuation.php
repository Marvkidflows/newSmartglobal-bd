<?php
// LOCATION: app/Models/UsdValuation.php
// Append-only: a correction is a NEW row. See the migration for why this lives outside the financial tables.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class UsdValuation extends Model
{
    public const TYPES = ['investment', 'deposit'];

    public $timestamps = false;

    protected $fillable = [
        'valuable_type', 'valuable_id', 'investor_id', 'currency', 'original_amount', 'fx_rate', 'usd_amount',
        'rate_source', 'rate_date', 'distinct_confirmed', 'note', 'verified_by', 'verified_by_name', 'created_at',
    ];

    protected $casts = [
        'original_amount'    => 'decimal:2',
        'usd_amount'         => 'decimal:2',
        'fx_rate'            => 'decimal:10',
        'distinct_confirmed' => 'boolean',
        'rate_date'          => 'date',
        'created_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('USD valuations are append-only; record a new valuation instead.'));
        static::deleting(fn () => throw new LogicException('USD valuations cannot be deleted.'));
    }
}
