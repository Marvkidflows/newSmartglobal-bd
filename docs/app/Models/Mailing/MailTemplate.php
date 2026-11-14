<?php
// TASK 3 — reusable message template, bound to ONE environment (public | investor).
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $table = 'mail_templates';
    protected $fillable = ['environment', 'name', 'subject', 'body_html', 'is_active', 'created_by', 'updated_by'];
    protected $casts = ['is_active' => 'boolean'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
