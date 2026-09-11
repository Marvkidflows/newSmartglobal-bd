<?php
// LOCATION: app/Models/PredictionRound.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class PredictionRound extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'open', 'closed', 'resolved', 'cancelled'];

    protected $fillable = [
        'asset_symbol', 'question', 'status', 'opens_at', 'closes_at', 'resolves_at',
        'reference_price', 'resolution_price', 'outcome',
        'points_stake', 'points_payout', 'created_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'opens_at'         => 'datetime',
        'closes_at'        => 'datetime',
        'resolves_at'      => 'datetime',
        'resolved_at'      => 'datetime',
        'reference_price'  => 'decimal:8',
        'resolution_price' => 'decimal:8',
    ];

    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
    public function entries()  { return $this->hasMany(PredictionEntry::class); }

    /**
     * Lazily flips 'open' -> 'closed' once closes_at has passed — same
     * enforce-on-read pattern used by the Task system. Idempotent.
     */
    public function syncClose(): bool
    {
        if ($this->status !== 'open') return false;
        if (Carbon::now()->lt($this->closes_at)) return false;

        $this->status = 'closed';
        $this->save();
        return true;
    }

    public function getIsOpenForEntryAttribute(): bool
    {
        return $this->status === 'open' && Carbon::now()->lt($this->closes_at);
    }
}
