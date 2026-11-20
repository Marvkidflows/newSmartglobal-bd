<?php
// LOCATION: app/Services/DepositAgentResolver.php
//
// Decides WHICH Telegram contact an investor is connected to after submitting a deposit. Resolved server-side only:
//   1. the investor's own assigned Financial Team agent (users.assigned_agent_id) - verified to be an ACTIVE staff
//      account (role financial/admin) with a syntactically valid Telegram username;
//   2. otherwise the existing deployment-wide representative (TELEGRAM_AGENT_USERNAME / TELEGRAM_AGENT_NAME) - this is
//      the pre-existing policy, so investors without an individual assignment behave exactly as before;
//   3. otherwise null. Nothing is ever invented: there is no built-in default username.
// Never returns a chat id; that stays server-side (used only by notifyAssignedAgent()).

namespace App\Services;

use App\Models\User;

class DepositAgentResolver
{
    public const STAFF_ROLES = ['financial', 'admin'];

    /** Telegram usernames: 5-32 chars, letters/digits/underscore, must start with a letter. */
    public static function normaliseUsername(?string $raw): ?string
    {
        $u = ltrim(trim((string) $raw), '@');
        // Accept a pasted t.me link too, but only reduce it to the username; never keep arbitrary URLs.
        if (preg_match('#^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{4,31})/?$#i', $u, $m)) $u = $m[1];
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $u) === 1 ? $u : null;
    }

    /**
     * @return array{source:string, agent_id:?int, name:string, username:string, chat_id:?string}|null
     */
    public function resolve(User $investor): ?array
    {
        $agent = $this->assignedAgent($investor);
        if ($agent) {
            $username = self::normaliseUsername($agent->telegram_username);
            if ($username) {
                return [
                    'source'   => 'assigned',
                    'agent_id' => $agent->id,
                    'name'     => $agent->name ?: ($agent->full_name ?: 'Your Financial Team Agent'),
                    'username' => $username,
                    'chat_id'  => $agent->telegram_chat_id ?: null,
                ];
            }
            // Assigned but no usable Telegram details: do NOT silently hand the investor to somebody else's contact.
            return null;
        }

        $default = self::normaliseUsername(config('services.telegram.agent_username'));
        if (!$default) return null;

        return [
            'source'   => 'default',
            'agent_id' => null,
            'name'     => trim((string) config('services.telegram.agent_name')) ?: 'SSI Telegram Representative',
            'username' => $default,
            'chat_id'  => null,
        ];
    }

    /** The assigned agent, only if the assignment is still valid (active staff account). */
    public function assignedAgent(User $investor): ?User
    {
        if (!$investor->assigned_agent_id) return null;
        $agent = User::query()->whereKey($investor->assigned_agent_id)->first();
        if (!$agent || !in_array($agent->role, self::STAFF_ROLES, true) || $agent->status !== 'active') return null;
        return $agent;
    }

    /** Public shape for API responses: name + link only (no chat id, no raw username column). */
    public function publicShape(?array $resolved, string $investorName, string $reference): ?array
    {
        if (!$resolved) return null;
        return [
            'name' => $resolved['name'],
            'link' => $this->link($resolved['username'], $investorName, $reference),
        ];
    }

    /** t.me link. The prefilled text deliberately carries only the name and reference - no amount, balance or contact data. */
    public function link(string $username, string $investorName, string $reference): string
    {
        $text = "Hi, I'm {$investorName}. I have just submitted a deposit request. Reference: {$reference}.";
        return 'https://t.me/' . $username . '?text=' . rawurlencode($text);
    }
}
