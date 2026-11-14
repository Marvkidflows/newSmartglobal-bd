<?php
// TASK 3 — a public (non-investor) contact. Email is always stored lower-case.
namespace App\Models\Mailing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MailContact extends Model
{
    protected $table = 'mail_contacts';
    protected $fillable = ['public_id', 'email', 'name', 'source', 'user_id', 'status'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->public_id = $c->public_id ?: (string) Str::uuid();
            $c->email = strtolower(trim($c->email));
        });
    }

    public function subscriptions() { return $this->hasMany(MailSubscription::class, 'contact_id'); }
    public function user()          { return $this->belongsTo(User::class); }
}
