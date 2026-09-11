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
        'initiated_by',
        'read_by_admin',
        'read_by_investor',
    ];

    protected $casts = [
        'read_by_admin'    => 'boolean',
        'read_by_investor' => 'boolean',
    ];

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

    // ── SCOPES ─────────────────────────────────────────────────────────────

    // All messages in a conversation between admin and a specific investor
    public function scopeConversation($query, $investorId)
    {
        return $query->where('investor_id', $investorId)
                     ->orderBy('created_at', 'asc');
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

    // ── HELPERS ────────────────────────────────────────────────────────────

    public function isFromAdmin(): bool
    {
        return $this->initiated_by === 'admin';
    }

    public function isFromInvestor(): bool
    {
        return $this->initiated_by === 'investor';
    }
}
