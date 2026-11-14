<?php
// LOCATION: app/Models/Fixture.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fixture extends Model
{
    use SoftDeletes;

    // "Top leagues only" — previously a fixed PHP constant. Now backed
    // by the `competitions` table (see Competition model) so an admin
    // can control the active set without a code change. leagues() below
    // returns every competition in the catalog (enabled or not) — the
    // full set manual fixture entry may choose from, since manual entry
    // isn't gated by the API-fetch enable/disable toggle. Use
    // Competition::enabled() directly wherever the distinction matters
    // (e.g. FootballDataService only fetches enabled competitions).
    public static function leagues(): array
    {
        return Competition::orderBy('sort_order')->pluck('name')->all();
    }

    // Full fixture lifecycle. `status` was originally a DB enum limited
    // to scheduled/live/finished/cancelled; the column is now a plain
    // string (see the 2026_09_14 migration) so this constant — not a
    // schema change — is the single source of truth for valid values.
    // 'postponed' was added to support provider-driven sync (a match
    // the provider marks POSTPONED/SUSPENDED lands here rather than
    // being force-fit into 'cancelled', which is reserved for matches
    // that are truly off and will not be replayed).
    public const STATUSES = ['scheduled', 'live', 'postponed', 'finished', 'cancelled'];

    protected $fillable = [
        'league', 'home_team', 'away_team', 'kickoff_at', 'status',
        'home_score', 'away_score', 'created_by', 'resolved_by', 'resolved_at',
        'source', 'is_published', 'external_id', 'provider_status', 'last_synced_at',
    ];

    protected $casts = [
        'kickoff_at'      => 'datetime',
        'resolved_at'     => 'datetime',
        'is_published'    => 'boolean',
        'last_synced_at'  => 'datetime',
    ];

    // Only fixtures investors are ever allowed to see. Manual fixtures are
    // published=true the moment they're created (unchanged behavior);
    // API-fetched ones stay false until an admin explicitly publishes them.
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    // API-fetched fixtures sitting unpublished — what the admin "Pending
    // Review" queue shows. Manual fixtures never appear here since they
    // publish immediately on creation.
    public function scopePendingReview($query)
    {
        return $query->where('is_published', false);
    }

    // Whether this fixture can still be edited/postponed/cancelled/have
    // markets touched — i.e. it hasn't been finally resolved yet.
    public function getIsEditableAttribute(): bool
    {
        return !in_array($this->status, ['finished', 'cancelled']);
    }

    public function markets() { return $this->hasMany(FixtureMarket::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function resolver(){ return $this->belongsTo(User::class, 'resolved_by'); }

    // Every prediction submitted against any of this fixture's markets —
    // lets the admin side query/count "predictions taken" per fixture
    // (e.g. withCount('predictions')) without a manual join, since a
    // prediction belongs to a market, not directly to a fixture.
    public function predictions()
    {
        return $this->hasManyThrough(FixturePrediction::class, FixtureMarket::class, 'fixture_id', 'fixture_market_id');
    }

    /**
     * Given the final score, work out the actual 1X2 result. Every other
     * market type derives from this same single source of truth rather
     * than duplicating the comparison.
     */
    public function actualResult(): ?string
    {
        if ($this->home_score === null || $this->away_score === null) {
            return null;
        }
        return match (true) {
            $this->home_score > $this->away_score => 'home',
            $this->home_score < $this->away_score => 'away',
            default => 'draw',
        };
    }

    /**
     * Whether a given (market_type, line, selection) was correct, given
     * this fixture's final score. Pure function of the four allowed
     * market types only — no other market types are ever evaluated.
     */
    public function isSelectionCorrect(string $marketType, ?float $line, string $selection): ?bool
    {
        $result = $this->actualResult();
        if ($result === null) return null;

        return match ($marketType) {
            'one_x_two' => $selection === $result,

            'double_chance' => match ($selection) {
                'home_or_draw' => in_array($result, ['home', 'draw']),
                'draw_or_away' => in_array($result, ['draw', 'away']),
                'home_or_away' => in_array($result, ['home', 'away']),
                default => false,
            },

            'over_under' => (function () use ($selection, $line) {
                $total = $this->home_score + $this->away_score;
                if ($line === null) return false;
                return $selection === 'over' ? $total > $line : $total < $line;
            })(),

            // Handicap applies the line to the home team's score. A
            // half-integer line (e.g. -1.5) is required at creation time
            // specifically so there is never a push/tie to resolve.
            'handicap' => (function () use ($selection, $line) {
                if ($line === null) return false;
                $adjustedHome = $this->home_score + $line;
                return $selection === 'home'
                    ? $adjustedHome > $this->away_score
                    : $adjustedHome < $this->away_score;
            })(),

            default => false,
        };
    }
}
