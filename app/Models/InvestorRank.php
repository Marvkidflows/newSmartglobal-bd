<?php
// LOCATION: app/Models/InvestorRank.php
// Rank DEFINITION (Bronze … Elite). Investors reference it via users.investor_rank_id.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestorRank extends Model
{
    /**
     * Icon names the frontend badge (src/components/RankBadge.jsx → ICONS) can draw. Every rank must use a
     * different one so ranks are distinguishable by shape as well as colour.
     */
    public const SUPPORTED_ICONS = ['shield', 'shield-check', 'award', 'star', 'diamond', 'crown'];

    protected $fillable = ['key', 'name', 'level', 'color', 'icon', 'description'];

    protected $casts = ['level' => 'integer'];

    public function criteria()
    {
        return $this->hasMany(RankCriterion::class);
    }

    public function investors()
    {
        return $this->hasMany(User::class, 'investor_rank_id');
    }

    /** Compact shape embedded in every API response that shows an investor. */
    public function badge(): array
    {
        return [
            'id'    => $this->id,
            'key'   => $this->key,
            'name'  => $this->name,
            'level' => $this->level,
            'color' => $this->color,
            'icon'  => $this->icon,
        ];
    }
}
