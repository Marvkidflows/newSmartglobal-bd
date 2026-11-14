<?php
// TASK 3 — explicit staff permission grant (admins hold every permission implicitly).
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailPermission extends Model
{
    protected $table = 'mail_permissions';
    protected $fillable = ['user_id', 'permission', 'granted_by'];

    public function user() { return $this->belongsTo(User::class); }
}
