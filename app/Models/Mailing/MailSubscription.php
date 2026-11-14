<?php
// TASK 3 — CURRENT consent state per (email, purpose). History lives in MailConsent.
namespace App\Models\Mailing;

use Illuminate\Database\Eloquent\Model;

class MailSubscription extends Model
{
    protected $table = 'mail_subscriptions';
    protected $fillable = ['email', 'contact_id', 'user_id', 'purpose', 'status', 'changed_at'];
    protected $casts = ['changed_at' => 'datetime'];

    public function contact() { return $this->belongsTo(MailContact::class, 'contact_id'); }
}
