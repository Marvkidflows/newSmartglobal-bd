<?php
// LOCATION: app/Console/Commands/RegistrationBackfill.php
//
// Task 2 — creates registration_applications rows for investors who registered BEFORE this module existed. Idempotent (existing rows are
// skipped) and non-destructive (it only inserts into registration_*; it never edits users). Nothing is invented: a timestamp is set only
// when it can be derived from existing data, and every row is flagged backfilled=true so reports can tell observed from reconstructed.
//
//   registration_completed = false                       → pending
//   completed, users.kyc_status = approved + verified    → approved   (decided_at = kyc_verified_at)
//   completed, users.kyc_status = rejected               → rejected
//   completed, anything else                             → submitted  (awaiting review)
//
// Run with --dry-run first. The command does not send any notification or email.

namespace App\Console\Commands;

use App\Models\RegistrationApplication;
use App\Models\RegistrationEvent;
use App\Models\User;
use Illuminate\Console\Command;

class RegistrationBackfill extends Command
{
    protected $signature = 'registration:backfill {--dry-run}';
    protected $description = 'Create registration application records for investors who registered before the Registration Team module';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = 0;

        User::where('role', 'investor')->whereDoesntHave('registrationApplication')->orderBy('id')->chunkById(200, function ($users) use ($dry, &$created) {
            foreach ($users as $u) {
                $created++;
                if ($dry) continue;

                $kyc = $u->kyc_status_safe;
                if (!$u->registration_completed)                         $status = RegistrationApplication::PENDING;
                elseif ($kyc === 'approved' && $u->kyc_verified)         $status = RegistrationApplication::APPROVED;
                elseif ($kyc === 'rejected')                             $status = RegistrationApplication::REJECTED;
                else                                                     $status = RegistrationApplication::SUBMITTED;

                $app = RegistrationApplication::create([
                    'user_id'              => $u->id,
                    'review_status'        => $status,
                    'stage_reached'        => max(1, min(4, (int) $u->registration_stage)),
                    'started_at'           => $u->created_at,
                    'details_completed_at' => null,   // not derivable from existing data
                    'submitted_at'         => null,
                    'decided_at'           => in_array($status, [RegistrationApplication::APPROVED, RegistrationApplication::REJECTED], true) ? $u->kyc_verified_at : null,
                    'decision_reason'      => $status === RegistrationApplication::REJECTED ? $u->kyc_rejection_reason : null,
                    'last_activity_at'     => $u->updated_at,
                    'backfilled'           => true,
                ]);
                RegistrationEvent::record('backfilled', $app, null, null, $status, 'Created from pre-existing account data.');
            }
        });

        $this->info(($dry ? '[dry-run] Would create ' : 'Created ') . "{$created} application record(s).");
        return self::SUCCESS;
    }
}
