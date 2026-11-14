<?php
// LOCATION: app/Models/LoanApplicationEvent.php  — append-only status timeline of a loan application.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LoanApplicationEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['loan_application_id', 'event', 'actor_name', 'actor_role', 'note', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Loan application events are immutable.'));
        static::deleting(fn () => throw new LogicException('Loan application events cannot be deleted.'));
    }
}
