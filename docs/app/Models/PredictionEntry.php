<?php
// LOCATION: app/Models/PredictionEntry.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionEntry extends Model
{
    protected $fillable = [
        'prediction_round_id', 'user_id', 'choice', 'points_staked',
        'is_correct', 'points_awarded', 'submitted_at',
    ];

    protected $casts = [
        'is_correct'   => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function round() { return $this->belongsTo(PredictionRound::class, 'prediction_round_id'); }
    public function user()  { return $this->belongsTo(User::class); }
}
