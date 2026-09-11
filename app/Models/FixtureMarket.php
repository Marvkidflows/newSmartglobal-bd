<?php
// LOCATION: app/Models/FixtureMarket.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureMarket extends Model
{
    // Exactly these four — never expand without a corresponding change
    // to Fixture::isSelectionCorrect() and explicit sign-off, per the
    // "do not add extra prediction markets" requirement.
    public const TYPES = ['one_x_two', 'double_chance', 'over_under', 'handicap'];

    public const SELECTIONS = [
        'one_x_two'     => ['home', 'draw', 'away'],
        'double_chance' => ['home_or_draw', 'draw_or_away', 'home_or_away'],
        'over_under'    => ['over', 'under'],
        'handicap'      => ['home', 'away'],
    ];

    protected $fillable = ['fixture_id', 'market_type', 'line', 'status'];

    protected $casts = ['line' => 'decimal:1'];

    public function fixture()    { return $this->belongsTo(Fixture::class); }
    public function predictions(){ return $this->hasMany(FixturePrediction::class); }
}
