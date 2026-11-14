<?php
// LOCATION: app/Console/Commands/RegistrationMaintain.php
//
// Task 2 — scheduled upkeep for the Registration Team module:
//   1. flags INCOMPLETE applications (still 'pending', inactive for config('registration.incomplete_after_hours')) to the team — exactly one
//      internal alert per application (deduplicated by registration_alerts). It NEVER emails an applicant: applicant follow-up is always a
//      deliberate, logged action by an authorised team member (consent + cooldown rules apply).
//   2. prunes anonymous visit rows (never linked to an applicant) older than config('registration.visit_retention_days').

namespace App\Console\Commands;

use App\Models\RegistrationApplication;
use App\Models\RegistrationVisit;
use App\Services\Registration\RegistrationNotificationService;
use Illuminate\Console\Command;

class RegistrationMaintain extends Command
{
    protected $signature = 'registration:maintain {--dry-run : Report what would happen without alerting or deleting}';
    protected $description = 'Flag incomplete registrations for follow-up and prune old anonymous visit records';

    public function handle(RegistrationNotificationService $alerts): int
    {
        $cutoff = now()->subHours((int) config('registration.incomplete_after_hours'));
        $dry = (bool) $this->option('dry-run');

        $flagged = 0;
        RegistrationApplication::where('review_status', RegistrationApplication::PENDING)
            ->where('last_activity_at', '<=', $cutoff)
            ->orderBy('id')->chunkById(200, function ($apps) use ($alerts, $dry, &$flagged) {
                foreach ($apps as $app) {
                    if ($dry) { $flagged++; continue; }
                    if ($alerts->incompleteNeedsFollowUp($app->id)) $flagged++;   // false = already alerted earlier
                }
            });

        $retention = now()->subDays((int) config('registration.visit_retention_days'));
        $old = RegistrationVisit::whereNull('user_id')->where('opened_at', '<', $retention);
        $pruned = $dry ? $old->count() : $old->delete();

        // Email-ownership codes (new registrations): the rows carry the per-address cooldown and hourly cap, so they are kept for 7 days (far longer than the
        // one-hour window the cap needs) and then deleted. Same for the resume codes. Only hashed codes are stored; nothing personal beyond the address.
        $codeCutoff = now()->subDays(7);
        $oldEmail   = \App\Models\RegistrationEmailChallenge::where('created_at', '<', $codeCutoff);
        $oldResume  = \App\Models\RegistrationResumeChallenge::where('created_at', '<', $codeCutoff);
        $codesPruned = $dry ? ($oldEmail->count() + $oldResume->count()) : ($oldEmail->delete() + $oldResume->delete());

        $this->info(($dry ? '[dry-run] ' : '') . "Incomplete applications flagged: {$flagged}. Anonymous visits " . ($dry ? 'that would be pruned' : 'pruned') . ": {$pruned}. Old verification codes " . ($dry ? 'that would be pruned' : 'pruned') . ": {$codesPruned}.");
        return self::SUCCESS;
    }
}
