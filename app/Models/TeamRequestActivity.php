<?php
// LOCATION: app/Models/TeamRequestActivity.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamRequestActivity extends Model
{
    use HasFactory;

    // FIXED — the migration creates a table named `team_request_activity`
    // (singular), but Eloquent's default naming convention pluralizes
    // the class name automatically and would look for
    // `team_request_activities` instead. Without this, every write to
    // this model (on request creation, reply, status/priority change)
    // failed with "Base table or view not found" — this is what was
    // causing "server error" on every attempt to submit a dev request.
    protected $table = 'team_request_activity';

    protected $fillable = [
        'team_request_id',
        'actor_id',
        'action',
        'from_value',
        'to_value',
        'note',
    ];

    public function teamRequest()
    {
        return $this->belongsTo(TeamRequest::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}