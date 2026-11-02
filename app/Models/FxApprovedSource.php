<?php
// LOCATION: app/Models/FxApprovedSource.php — Approved exchange-rate register entry. Empty until the Financial Team documents an approval.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FxApprovedSource extends Model
{
    protected $table = 'fx_approved_sources';
    public $timestamps = false;
    protected $fillable = ['currency', 'reference_currency', 'direction', 'primary_source', 'fallback_source', 'rate_type', 'max_age_hours', 'approval_reference', 'approved_by', 'approved_by_name', 'effective_from', 'effective_to', 'created_at'];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('FxApprovedSource records are immutable; record a new row (an auditable adjustment) instead.'));
        static::deleting(fn () => throw new LogicException('FxApprovedSource records cannot be deleted.'));
    }
}
