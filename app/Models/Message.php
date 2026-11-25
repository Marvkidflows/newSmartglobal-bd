<?php
// LOCATION: app/Models/Message.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'investor_id',
        'subject',
        'body',
        'body_html',
        'initiated_by',
        'department',
        'sender_label',
        'kind',
        'broadcast_id',
        'read_by_admin',
        'read_by_investor',
        'read_by_financial',
        'topic',
        'deposit_id',
        'client_token',
    ];

    protected $casts = [
        'read_by_admin'    => 'boolean',
        'read_by_investor' => 'boolean',
        'read_by_financial'=> 'boolean',
    ];

    public const TOPIC_GENERAL = 'general';
    public const TOPIC_DEPOSIT = 'deposit';

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

    // Who sent this message
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Who receives this message
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    // The investor this conversation belongs to
    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    // The deposit a deposit-enquiry message is about (read-only link; no foreign key by design)
    public function deposit()
    {
        return $this->belongsTo(Deposit::class, 'deposit_id');
    }

    // ── SCOPES ─────────────────────────────────────────────────────────────

    // All messages in a conversation between admin and a specific investor
    public function scopeConversation($query, $investorId)
    {
        // created_at has one-second resolution, so a deposit enquiry and the staff reply written in the same second tie.
        // `id` is the tie-breaker: it makes the order (and "who wrote last") deterministic on every database.
        return $query->where('investor_id', $investorId)
                     ->orderBy('created_at', 'asc')
                     ->orderBy('id', 'asc');
    }

    // Messages admin has not read yet
    public function scopeUnreadByAdmin($query)
    {
        return $query->where('read_by_admin', false)
                     ->where('initiated_by', 'investor');
    }

    // Messages investor has not read yet
    public function scopeUnreadByInvestor($query)
    {
        return $query->where('read_by_investor', false)
                     ->where('initiated_by', 'admin');
    }

    // Messages in the Financial Team's mailbox (either direction)
    public function scopeFinancial($query)
    {
        return $query->where('department', 'financial');
    }

    // Investor-initiated messages the Financial Team has not read yet
    public function scopeUnreadByFinancial($query)
    {
        return $query->where('department', 'financial')
                     ->where('initiated_by', 'investor')
                     ->where('read_by_financial', false);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    public function isFromAdmin(): bool
    {
        return $this->initiated_by === 'admin';
    }

    public const FINANCIAL_SENDER_LABEL = 'Smart System Investment — Financial Team';

    // Display identity for the sender of this row. Staff rows use the label
    // stored at send time, so history is stable across renames/removals.
    public function displaySender(): string
    {
        if ($this->initiated_by === 'investor') {
            return 'Investor';
        }
        if ($this->sender_label) {
            return $this->sender_label;
        }
        return $this->department === 'financial' ? self::FINANCIAL_SENDER_LABEL : 'Smart System Investment — Support Team';
    }

    public function isFromInvestor(): bool
    {
        return $this->initiated_by === 'investor';
    }
}
