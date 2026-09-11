<?php
// LOCATION: app/Models/SentEmail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'admin_id',
        'investor_id',
        'recipient_name',
        'recipient_email',
        'subject',
        'body_html',
        'attachment_path',
        'attachment_name',
        'status',
        'error_message',
        'sent_at',
        'read_by_investor',
        'read_at',
    ];

    protected $casts = [
        'sent_at'          => 'datetime',
        'read_at'          => 'datetime',
        'read_by_investor' => 'boolean',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function investor()
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function scopeUnreadByInvestor($query)
    {
        return $query->where('read_by_investor', false);
    }
}