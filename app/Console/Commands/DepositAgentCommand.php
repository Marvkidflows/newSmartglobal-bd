<?php
// LOCATION: app/Console/Commands/DepositAgentCommand.php
//
// Sets up who an investor is connected to on Telegram after a deposit. There is no admin screen for this yet (a management
// decision: who may assign agents), so this command is the only writer. Only staff accounts (financial/admin) can be agents.
//
//   php artisan ssi:deposit-agent show   investor@example.com
//   php artisan ssi:deposit-agent telegram agent@example.com @agent_handle [--chat-id=123456789]
//   php artisan ssi:deposit-agent assign investor@example.com agent@example.com
//   php artisan ssi:deposit-agent unassign investor@example.com
//
// Nothing is invented: a handle is stored only if it is a valid Telegram username; a chat id only if you pass one.

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DepositAgentResolver;
use Illuminate\Console\Command;

class DepositAgentCommand extends Command
{
    protected $signature   = 'ssi:deposit-agent {action : show|telegram|assign|unassign} {user : email of the investor (show/assign/unassign) or of the agent (telegram)} {value? : agent email (assign) or Telegram username (telegram)} {--chat-id= : optional Telegram chat id of the agent, so the bot can DM them}';
    protected $description = 'Show or set the Telegram contact / assigned Financial Team agent used after an investor submits a deposit.';

    public function handle(DepositAgentResolver $resolver): int
    {
        $action = $this->argument('action');
        $user   = User::where('email', $this->argument('user'))->first();
        if (!$user) { $this->error('No user with that email.'); return self::FAILURE; }

        switch ($action) {
            case 'show':
                $r = $resolver->resolve($user);
                $this->line($r ? "Resolved: {$r['source']} -> {$r['name']} (@{$r['username']}), bot DM " . ($r['chat_id'] ? 'possible' : 'not possible (no chat id)') : 'Resolved: NONE (investor would see the fallback message)');
                return self::SUCCESS;

            case 'telegram':
                if (!in_array($user->role, DepositAgentResolver::STAFF_ROLES, true)) { $this->error('Only financial/admin accounts can be agents.'); return self::FAILURE; }
                $handle = DepositAgentResolver::normaliseUsername($this->argument('value'));
                if (!$handle) { $this->error('Not a valid Telegram username (5-32 letters, digits or underscores, starting with a letter).'); return self::FAILURE; }
                $user->forceFill(['telegram_username' => $handle]);
                if ($this->option('chat-id') !== null) {
                    if (!preg_match('/^-?\d{4,20}$/', (string) $this->option('chat-id'))) { $this->error('Chat id must be numeric.'); return self::FAILURE; }
                    $user->forceFill(['telegram_chat_id' => (string) $this->option('chat-id')]);
                }
                $user->save();
                $this->info("Saved Telegram details for {$user->email}.");
                return self::SUCCESS;

            case 'assign':
                $agent = User::where('email', $this->argument('value'))->first();
                if (!$agent || !in_array($agent->role, DepositAgentResolver::STAFF_ROLES, true) || $agent->status !== 'active') { $this->error('Agent must be an ACTIVE financial/admin account.'); return self::FAILURE; }
                if ($user->role !== 'investor') { $this->error('The first user must be an investor.'); return self::FAILURE; }
                if (!DepositAgentResolver::normaliseUsername($agent->telegram_username)) $this->warn('That agent has no valid Telegram username yet; investors will see the fallback message until you run the "telegram" action.');
                $user->forceFill(['assigned_agent_id' => $agent->id])->save();
                $this->info("{$user->email} is now assigned to {$agent->email}.");
                return self::SUCCESS;

            case 'unassign':
                $user->forceFill(['assigned_agent_id' => null])->save();
                $this->info('Assignment cleared.');
                return self::SUCCESS;
        }
        $this->error('Unknown action.');
        return self::FAILURE;
    }
}
