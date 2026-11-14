<?php
// LOCATION: app/Models/Deposit.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'investment_plan_id',
        'amount',
        'payment_method',
        'transaction_reference',
        'proof_image',
        'status',
        'admin_notes',
        'processed_at',
        'processed_by',
        'held_at',
        // Actual-payment record (written only by DepositPaymentService / the investor's declaration at confirm).
        'paid_amount', 'paid_currency', 'fx_rate', 'rate_source', 'rate_date', 'approved_amount',
        'external_reference', 'funding_channel', 'payment_note', 'payment_verified_by', 'payment_verified_at', 'payment_date', 'amount_difference_acknowledged',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
        'held_at'      => 'datetime',
        'paid_amount'  => 'decimal:2',
        'fx_rate'      => 'decimal:10',
        'approved_amount' => 'decimal:2',
        'rate_date'    => 'date',
        'payment_date' => 'date',
        'amount_difference_acknowledged' => 'boolean',
        'payment_verified_at' => 'datetime',
    ];

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function investmentPlan()
    {
        return $this->belongsTo(InvestmentPlan::class);
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

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}