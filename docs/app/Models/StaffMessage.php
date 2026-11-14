<?php
// LOCATION: app/Models/StaffMessage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffMessage extends Model
{
    use HasFactory;

    protected $fillable = ['sender_id', 'subject', 'body', 'body_html'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
