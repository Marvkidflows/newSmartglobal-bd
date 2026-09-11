<?php
// LOCATION: app/Models/Withdrawal.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'method',
        'account_details',
        'status',
        'admin_notes',
        'processed_at',
        'processed_by',
        'held_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
        'held_at'      => 'datetime',
    ];

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ── SCOPES ─────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeOnHold($query)
    {
        return $query->where('status', 'hold');
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    // account_details is stored as JSON; this decodes it back into an array
    // for display (e.g. ['wallet_address' => '...'] or ['bank_name' => '...', 'account_number' => '...']).
    public function getAccountDetailsArrayAttribute(): array
    {
        if (!$this->account_details) {
            return [];
        }
        $decoded = json_decode($this->account_details, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}