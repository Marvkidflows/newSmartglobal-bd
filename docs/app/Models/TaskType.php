<?php
// LOCATION: app/Models/TaskType.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskType extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'description',
        'requires_amount',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'requires_amount' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
