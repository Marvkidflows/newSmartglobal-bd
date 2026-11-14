<?php
// TASK 3 — an official SSI department / team that owns mailboxes and communication.
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailDepartment extends Model
{
    protected $table = 'mail_departments';
    protected $fillable = ['slug', 'name', 'environment', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function mailboxes() { return $this->hasMany(MailMailbox::class, 'department_id'); }
    public function members()   { return $this->belongsToMany(User::class, 'mail_department_members', 'department_id', 'user_id')->withPivot('role')->withTimestamps(); }
}
