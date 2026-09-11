<?php
// LOCATION: app/Models/FixturePrediction.php

namespace App\Models;

class FixturePrediction extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'fixture_market_id', 'user_id', 'selection',
        'is_correct', 'points_awarded', 'submitted_at',
    ];

    protected $casts = [
        'is_correct'   => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function market() { return $this->belongsTo(FixtureMarket::class, 'fixture_market_id'); }
    public function user()   { return $this->belongsTo(User::class); }
}
