<?php
// LOCATION: app/Models/TaskActivityLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskActivityLog extends Model
{
    public $timestamps = false; // only created_at, set via useCurrent() in the migration

    protected $fillable = [
        'task_id', 'task_assignment_id', 'actor_id', 'actor_type',
        'action', 'from_status', 'to_status', 'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function task() { return $this->belongsTo(Task::class); }
    public function assignment() { return $this->belongsTo(TaskAssignment::class, 'task_assignment_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
