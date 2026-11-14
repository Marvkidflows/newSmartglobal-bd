<?php
// LOCATION: app/Models/RegistrationAlert.php
// Dedupe ledger for internal Registration Team notifications: the unique dedupe_key guarantees one alert per event.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationAlert extends Model
{
    public $timestamps = false;
    protected $fillable = ['dedupe_key', 'alert_type', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
