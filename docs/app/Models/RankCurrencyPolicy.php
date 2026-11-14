<?php
// LOCATION: app/Models/RankCurrencyPolicy.php
// One VERSION of the currency policy. The reviewed rules, who reviewed/confirmed and when are immutable once written;
// only the lifecycle columns (status, confirmation, revocation) may change. Rows are never deleted.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RankCurrencyPolicy extends Model
{
    public const PENDING    = 'pending_confirmation';
    public const CONFIRMED  = 'confirmed';
    public const REVOKED    = 'revoked';
    public const SUPERSEDED = 'superseded';

    /** Columns that can never change after the row is created. */
    private const IMMUTABLE = [
        'version', 'ranking_currency', 'rules', 'rules_hash',
        'reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at', 'review_note', 'review_snapshot',
    ];

    protected $fillable = [
        'version', 'status', 'ranking_currency', 'rules', 'rules_hash',
        'reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at', 'review_note', 'review_snapshot',
        'confirmed_by', 'confirmed_by_name', 'confirmed_by_role', 'confirmed_at', 'confirmation_note',
        'unverified_handling', 'accepted_review_count',
        'revoked_by', 'revoked_by_name', 'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'rules'                 => 'array',
        'review_snapshot'       => 'array',
        'reviewed_at'           => 'datetime',
        'confirmed_at'          => 'datetime',
        'revoked_at'            => 'datetime',
        'accepted_review_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            foreach (self::IMMUTABLE as $field) {
                if ($m->isDirty($field)) {
                    throw new LogicException("A currency policy's reviewed rules and review record cannot be edited ({$field}); submit a new version.");
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Currency policy records cannot be deleted.'));
    }
}
