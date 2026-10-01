<?php
// LOCATION: app/Models/TeamRequestMessage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamRequestMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_request_id',
        'sender_id',
        'sender_side',
        'body',
        'attachment_url',
    ];

    public function teamRequest()
    {
        return $this->belongsTo(TeamRequest::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isFromMarvflow(): bool
    {
        return $this->sender_side === 'marvflow';
    }
}
