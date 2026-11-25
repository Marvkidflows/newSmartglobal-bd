<?php
// LOCATION: app/Services/FinancialConversationService.php
//
// ONE place for "an investor writes to the Financial Team inside the dashboard". It is NOT a new messaging system: it writes the
// EXISTING `messages` table (one conversation per investor, department = 'financial') and uses the EXISTING notification class.
// Used by BOTH the chat (MessageController::investorStore) and the deposit page (InvestorDepositController::initiate), so a deposit
// enquiry and a typed question end up in the same thread with the same routing, notification and duplicate protection.
//
// Routing (uses the EXISTING assignment, users.assigned_agent_id, via DepositAgentResolver - no second mechanism):
//   * investor has a valid assigned agent (active financial/admin account) -> that agent is the recipient and is alerted;
//   * otherwise -> every active Financial Team member is alerted, so an unassigned enquiry is never left unseen.
// Every financial.communicate user (financial + admin) can still open and answer any conversation - assignment only decides who is
// alerted and who the investor is told they are talking to.

namespace App\Services;

use App\Models\Deposit;
use App\Models\Message;
use App\Models\User;
use App\Notifications\FinancialAlertNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class FinancialConversationService
{
    public const LINK = '/financial/messages';

    public function __construct(protected DepositAgentResolver $agents) {}

    /** The investor's assigned agent, only while that assignment is still valid. */
    public function assignedAgent(User $investor): ?User
    {
        return $this->agents->assignedAgent($investor);
    }

    /**
     * Who is alerted / addressed. Empty only when there is no active Financial Team (or, with $fallbackToAdmins, no active admin).
     *
     * @return Collection<int, User>
     */
    public function recipients(User $investor, bool $fallbackToAdmins = false): Collection
    {
        $agent = $this->assignedAgent($investor);
        if ($agent) return collect([$agent]);

        $team = $this->activeStaff('financial');
        if ($team->isEmpty() && $fallbackToAdmins) $team = $this->activeStaff('admin');
        return $team;
    }

    /**
     * Save an investor's message to the Financial Team (idempotent) and alert the recipients.
     *
     * @param  array{body:string, body_html:?string} $content  already validated/sanitised by RichMessageBody
     * @return array{message: Message, duplicate: bool}|null    null => nobody on the Financial Team can receive it
     */
    public function submit(User $investor, array $content, ?string $subject, string $topic = Message::TOPIC_GENERAL, ?Deposit $deposit = null, ?string $clientToken = null, bool $fallbackToAdmins = false): ?array
    {
        if ($deposit && (int) $deposit->user_id !== (int) $investor->id) {
            throw new InvalidArgumentException('A deposit enquiry can only reference the investor\'s own deposit.');
        }
        $topic = $deposit ? Message::TOPIC_DEPOSIT : (in_array($topic, [Message::TOPIC_GENERAL, Message::TOPIC_DEPOSIT], true) ? $topic : Message::TOPIC_GENERAL);

        // A retry of a message that is already saved: return it; no second row, no second alert.
        if ($clientToken && ($existing = $this->findByToken($investor->id, $clientToken))) {
            return ['message' => $existing, 'duplicate' => true];
        }

        $recipients = $this->recipients($investor, $fallbackToAdmins);
        if ($recipients->isEmpty()) return null;

        try {
            $message = Message::create([
                'sender_id'         => $investor->id,
                'receiver_id'       => $recipients->first()->id,
                'investor_id'       => $investor->id,
                'subject'           => $subject,
                'body'              => $content['body'],
                'body_html'         => $content['body_html'] ?? null,
                'initiated_by'      => 'investor',
                'department'        => 'financial',
                'topic'             => $topic,
                'deposit_id'        => $deposit?->id,
                'client_token'      => $clientToken,
                'read_by_admin'     => true,    // not part of the support inbox's unread count
                'read_by_financial' => false,
                'read_by_investor'  => true,
            ]);
        } catch (QueryException $e) {
            // Two identical sends raced: UNIQUE (investor_id, client_token) let only one through. Return that one.
            if ($clientToken && ($existing = $this->findByToken($investor->id, $clientToken))) {
                return ['message' => $existing, 'duplicate' => true];
            }
            throw $e;
        }

        $this->alert($recipients, $investor, $message, $subject, $deposit);

        return ['message' => $message, 'duplicate' => false];
    }

    public function findByToken(int $investorId, string $token): ?Message
    {
        return Message::where('investor_id', $investorId)->where('client_token', $token)->first();
    }

    /** In-app alert only (existing 'database' channel). A failed alert never undoes the saved message. */
    protected function alert(Collection $recipients, User $investor, Message $message, ?string $subject, ?Deposit $deposit): void
    {
        try {
            $name = $investor->name ?? 'An investor';
            Notification::send($recipients, new FinancialAlertNotification(
                $deposit ? 'New deposit enquiry' : 'New investor message',
                $name . ': ' . str($subject ?: $message->body)->limit(80),
                'investor_message',
                array_filter([
                    'investor_id'       => $investor->id,
                    'message_id'        => $message->id,
                    'topic'             => $message->topic,
                    'deposit_id'        => $deposit?->id,
                    'deposit_reference' => $deposit?->transaction_reference,
                    'link'              => self::LINK . '?investor=' . $investor->id,
                ])
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, User> */
    protected function activeStaff(string $role): Collection
    {
        return User::where('role', $role)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('id')->get();
    }
}
