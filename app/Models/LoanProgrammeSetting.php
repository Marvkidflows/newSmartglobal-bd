<?php
// LOCATION: app/Models/LoanProgrammeSetting.php
// Single-row switchboard for the Diamond/Elite lending pathway. Defaults: NOT authorised, NOT active.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProgrammeSetting extends Model
{
    protected $fillable = ['is_authorized', 'is_active', 'policy_reference', 'eligible_rank_keys', 'notes', 'updated_by'];
    protected $casts = ['is_authorized' => 'boolean', 'is_active' => 'boolean', 'eligible_rank_keys' => 'array'];

    /** The one settings row, created closed (not authorised, not active) on first use. */
    public static function current(): self
    {
        return static::query()->first() ?? static::create(['is_authorized' => false, 'is_active' => false, 'eligible_rank_keys' => ['diamond', 'elite']]);
    }

    public function isOpen(): bool
    {
        return $this->is_authorized && $this->is_active;
    }
}
