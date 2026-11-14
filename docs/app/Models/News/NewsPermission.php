<?php
// TASK 4 — explicit staff grant for the news module (admins hold all implicitly).
namespace App\Models\News;

use Illuminate\Database\Eloquent\Model;

class NewsPermission extends Model
{
    protected $table = 'news_permissions';
    protected $fillable = ['user_id', 'permission', 'granted_by'];
}
