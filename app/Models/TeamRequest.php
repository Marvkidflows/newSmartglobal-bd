<?php
// LOCATION: app/Models/TeamRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'subject',
        'message',
        'priority',
        'status',
        'attachment_url',
        'assigned_to',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    // ── RELATIONSHIPS ────────────────────────────────────────────────

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(TeamRequestMessage::class)->orderBy('created_at', 'asc');
    }

    public function activity()
    {
        return $this->hasMany(TeamRequestActivity::class)->orderBy('created_at', 'desc');
    }

    // ── SCOPES ───────────────────────────────────────────────────────

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['resolved', 'closed']);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'closed'], true);
    }

    public function displayId(): string
    {
        return '#' . $this->id;
    }
}
