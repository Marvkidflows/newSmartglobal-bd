<?php
// LOCATION: app/Models/FxConversionSnapshot.php — Immutable currency-conversion snapshot. Corrections are new rows that supersede an earlier one.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FxConversionSnapshot extends Model
{
    protected $table = 'fx_conversion_snapshots';
    public $timestamps = false;
    protected $fillable = ['subject_type', 'subject_id', 'status', 'original_currency', 'original_amount', 'pair', 'rate', 'rate_source', 'rate_type', 'rate_timestamp', 'converted_at', 'conversion_method', 'internal_usd', 'posted_usd', 'rounding_method', 'supporting_reference', 'verified_by', 'verified_by_name', 'supersedes_id', 'previous_rate', 'adjustment_reason', 'reconciliation_item_id', 'created_at'];
    protected $casts = ['rate_timestamp' => 'datetime', 'converted_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('FxConversionSnapshot records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('FxConversionSnapshot records cannot be deleted.'));
    }
}
