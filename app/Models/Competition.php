<?php
// LOCATION: app/Models/Competition.php
//
// Gaming & Prediction — admin-controlled list of which top
// leagues/competitions are active. Replaces the old fixed
// Fixture::LEAGUES constant as the source of truth for "which
// competitions exist" and "which ones are currently enabled".

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    protected $fillable = ['name', 'provider_code', 'is_enabled', 'sort_order'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
