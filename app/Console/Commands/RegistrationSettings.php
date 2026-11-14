<?php
// LOCATION: app/Console/Commands/RegistrationSettings.php
//
// Task 2 — prints the Registration Team settings currently in force and states which of them are PROPOSED DEVELOPER DEFAULTS awaiting
// Management / legal approval. Read-only: it changes nothing. Values come from config('registration.*'), i.e. from .env, so Management can
// adjust any of them without a code change (see config/registration.php).

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RegistrationSettings extends Command
{
    protected $signature = 'registration:settings';
    protected $description = 'Show Registration Team settings in force and which are awaiting Management approval';

    public function handle(): int
    {
        $c = fn (string $k) => config("registration.{$k}");

        $this->info('AWAITING MANAGEMENT / LEGAL APPROVAL (developer-proposed defaults — not approved SSI policy)');
        $this->line('  visit_tracking_enabled (REGISTRATION_VISIT_TRACKING)         = ' . ($c('visit_tracking_enabled') ? 'ON' : 'OFF') . '   [shipped OFF; needs approved privacy notice]');
        $this->line('  tracking_notice_version (REGISTRATION_TRACKING_NOTICE_VERSION) = ' . $c('tracking_notice_version') . '   [draft wording]');
        $this->line('  incomplete_after_hours (REGISTRATION_INCOMPLETE_AFTER_HOURS)   = ' . $c('incomplete_after_hours') . '   [proposed 24]');
        $this->line('  followup_cooldown_hours (REGISTRATION_FOLLOWUP_COOLDOWN_HOURS) = ' . $c('followup_cooldown_hours') . '   [proposed 72]');
        $this->line('  followup_max (REGISTRATION_FOLLOWUP_MAX)                       = ' . $c('followup_max') . '   [proposed 3]');
        $this->newLine();
        $this->info('OPERATIONAL (not policy)');
        $this->line('  notify_on_visit = ' . ($c('notify_on_visit') ? 'ON' : 'OFF') . ', visit_alert_max_per_hour = ' . $c('visit_alert_max_per_hour') . ', visit_retention_days = ' . $c('visit_retention_days'));
        $this->newLine();
        $this->info('SECURITY CONTROLS — resuming an unfinished registration (tighten, do not loosen, without a documented reason)');
        $this->line('  code ttl = ' . $c('resume_code_ttl_minutes') . ' min, max wrong guesses per code = ' . $c('resume_code_max_attempts')
            . ', min gap between codes = ' . $c('resume_request_cooldown_sec') . ' s, max codes per account per hour = ' . $c('resume_requests_per_hour'));

        return self::SUCCESS;
    }
}
