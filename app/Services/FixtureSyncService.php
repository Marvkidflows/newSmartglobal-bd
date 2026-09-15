<?php
// LOCATION: app/Services/FixtureSyncService.php
//
// Gaming & Prediction — orchestrates a full football-data.org sync in
// one pass:
//   1. Import: any fixture the provider has that we don't yet know
//      (matched by external_id) is created as unpublished, pending
//      admin review — never auto-published, never touching manual
//      fixtures.
//   2. Update: any fixture we already imported gets its status,
//      provider_status, kickoff time, and (once available) final score
//      refreshed from the provider. A finished match's markets and
//      predictions are settled the same way AdminFixtureController's
//      manual "Resolve" does — through Fixture::isSelectionCorrect() —
//      so automatic and manual resolution share one code path and one
//      set of rules.
//
// Both steps read from a single FootballDataService::fetchAllMatches()
// call, so a full sync never issues more than one HTTP request per
// enabled competition regardless of how many fixtures already exist.
//
// Concurrency: wrapped in a cache lock so the scheduled job and an
// admin's manual "Sync Now" can never run at the same time and race
// each other's writes. Every run — success, partial failure, or total
// failure — is recorded as a FixtureSyncLog row so the admin UI always
// has something accurate to show for "last sync".

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureMarket;
use App\Models\FixtureSyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixtureSyncService
{
    public const LOCK_KEY = 'gaming:fixture-sync-lock';

    protected int $pointsPerCorrectPrediction = 10;
    protected FootballDataService $footballData;

    public function __construct(FootballDataService $footballData)
    {
        $this->footballData = $footballData;
    }

    public function isRunning(): bool
    {
        // Try to acquire the same lock runFullSync() uses, without
        // holding it: if we can, nobody else is mid-sync; if we can't,
        // a run (scheduler or another admin's manual trigger) is
        // currently in progress.
        $lock = Cache::lock(self::LOCK_KEY, 10);
        if ($lock->get()) {
            $lock->release();
            return false;
        }
        return true;
    }

    /**
     * @param 'scheduler'|'manual' $source
     */
    public function runFullSync(string $source, ?int $triggeredBy = null): FixtureSyncLog
    {
        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (!$lock->get()) {
            return FixtureSyncLog::create([
                'source'       => $source,
                'status'       => 'failed',
                'message'      => null,
                'error'        => 'A synchronization is already in progress — try again shortly.',
                'triggered_by' => $triggeredBy,
                'started_at'   => now(),
                'finished_at'  => now(),
            ]);
        }

        $startedAt = now();

        try {
            $result = $this->footballData->fetchAllMatches();

            if (!$result['ok']) {
                return FixtureSyncLog::create([
                    'source'               => $source,
                    'status'               => 'failed',
                    'competitions_checked' => $result['competitions_checked'] ?? 0,
                    'error'                => $result['error'],
                    'triggered_by'         => $triggeredBy,
                    'started_at'           => $startedAt,
                    'finished_at'          => now(),
                ]);
            }

            $imported = $this->importNew($result['matches'], $triggeredBy);
            $updated  = $this->updateExisting($result['matches']);

            $hasWarning = !empty($result['error']);

            return FixtureSyncLog::create([
                'source'               => $source,
                'status'               => $hasWarning ? 'partial' : 'success',
                'fixtures_imported'    => $imported,
                'fixtures_updated'     => $updated,
                'competitions_checked' => $result['competitions_checked'],
                'message'              => $this->summarize($imported, $updated),
                'error'                => $result['error'],
                'triggered_by'         => $triggeredBy,
                'started_at'           => $startedAt,
                'finished_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FixtureSyncService run failed', ['error' => $e->getMessage()]);

            return FixtureSyncLog::create([
                'source'       => $source,
                'status'       => 'failed',
                'error'        => 'Unexpected error during synchronization: ' . $e->getMessage(),
                'triggered_by' => $triggeredBy,
                'started_at'   => $startedAt,
                'finished_at'  => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    protected function summarize(int $imported, int $updated): string
    {
        if ($imported === 0 && $updated === 0) {
            return 'No changes — everything already up to date.';
        }
        $parts = [];
        if ($imported > 0) $parts[] = "{$imported} new fixture(s) imported for review";
        if ($updated > 0) $parts[] = "{$updated} existing fixture(s) updated";
        return implode('; ', $parts) . '.';
    }

    /**
     * New fixtures only (dedup by external_id), landing unpublished —
     * identical behavior to the original AdminFixtureController::fetchFromApi().
     */
    protected function importNew(array $matches, ?int $triggeredBy): int
    {
        // Candidate matches worth importing at all — filtered before we
        // touch the database once, rather than per-row below.
        $candidates = collect($matches)->filter(
            fn ($m) => !empty($m['external_id']) && !empty($m['kickoff_at'])
                && in_array($m['provider_status'], ['SCHEDULED', 'TIMED'])
        )->unique('external_id')->values();

        if ($candidates->isEmpty()) return 0;

        // One query for every external_id we already know about (including
        // soft-deleted/discarded ones) instead of an exists() check per
        // candidate. On a fresh sync against a real season, a competition's
        // full match list can run into the hundreds — doing that as
        // hundreds of individual SELECT-then-INSERT round trips is what
        // pushed the very first live run past PHP's execution window.
        $known = Fixture::withTrashed()
            ->whereIn('external_id', $candidates->pluck('external_id'))
            ->pluck('external_id')
            ->flip();

        $now = now();
        $rows = $candidates
            ->reject(fn ($m) => $known->has($m['external_id']))
            ->map(fn ($m) => [
                'league'          => $m['league'],
                'home_team'       => $m['home_team'],
                'away_team'       => $m['away_team'],
                'kickoff_at'      => \Carbon\Carbon::parse($m['kickoff_at']),
                'status'          => 'scheduled',
                'source'          => 'api',
                'is_published'    => false, // pending review — never auto-exposed
                'external_id'     => $m['external_id'],
                'provider_status' => $m['provider_status'],
                'last_synced_at'  => $now,
                'created_by'      => $triggeredBy,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

        if ($rows->isEmpty()) return 0;

        // Bulk insert() bypasses Eloquent events/mutators — confirmed no
        // observers or model events are registered on Fixture, so this is
        // safe. Chunked to stay well under any single-query placeholder
        // limit on databases with lower defaults.
        foreach ($rows->chunk(200) as $chunk) {
            Fixture::insert($chunk->all());
        }

        return $rows->count();
    }

    /**
     * Refresh fixtures we already have from the provider's current data:
     * kickoff time corrections, postponed/suspended/cancelled detection,
     * and final scores once a match finishes. Never touches manual
     * fixtures (they have no external_id to match against) and never
     * re-opens something already finished/cancelled locally.
     */
    protected function updateExisting(array $matches): int
    {
        $byExternalId = collect($matches)->keyBy('external_id');
        $updated = 0;

        Fixture::where('source', 'api')
            ->whereNotNull('external_id')
            ->whereNotIn('status', ['finished', 'cancelled'])
            ->get()
            ->each(function (Fixture $fixture) use ($byExternalId, &$updated) {
                $m = $byExternalId->get($fixture->external_id);
                if (!$m || !$m['status']) return;

                $changes = ['last_synced_at' => now(), 'provider_status' => $m['provider_status']];

                if ($m['kickoff_at'] && (string) $fixture->kickoff_at->utc()->toIso8601String() !== (string) \Carbon\Carbon::parse($m['kickoff_at'])->utc()->toIso8601String()) {
                    $changes['kickoff_at'] = $m['kickoff_at'];
                }

                if ($m['status'] !== $fixture->status) {
                    $changes['status'] = $m['status'];
                }

                if ($m['status'] === 'finished' && $m['home_score'] !== null && $m['away_score'] !== null) {
                    $this->settleFixture($fixture, (int) $m['home_score'], (int) $m['away_score'], $changes);
                } else {
                    $fixture->update($changes);
                }

                $updated++;
            });

        return $updated;
    }

    /**
     * Shares the exact same settlement logic as
     * AdminFixtureController::resolve() (same Fixture::isSelectionCorrect()
     * call, same flat point award) so a fixture ends up identically
     * settled whether an admin typed the score in manually or the
     * provider supplied it automatically.
     */
    protected function settleFixture(Fixture $fixture, int $homeScore, int $awayScore, array $changes): void
    {
        DB::transaction(function () use ($fixture, $homeScore, $awayScore, $changes) {
            $fixture->update([
                ...$changes,
                'home_score'  => $homeScore,
                'away_score'  => $awayScore,
                'status'      => 'finished',
                'resolved_at' => now(),
            ]);

            $fixture->markets()->with('predictions')->get()->each(function (FixtureMarket $market) use ($fixture) {
                foreach ($market->predictions as $prediction) {
                    $correct = $fixture->isSelectionCorrect(
                        $market->market_type,
                        $market->line ? (float) $market->line : null,
                        $prediction->selection
                    );
                    $prediction->update([
                        'is_correct'     => $correct,
                        'points_awarded' => $correct ? $this->pointsPerCorrectPrediction : 0,
                    ]);
                }
                $market->update(['status' => 'settled']);
            });
        });
    }
}
