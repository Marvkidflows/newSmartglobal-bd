<?php
// LOCATION: app/Services/Rank/InvestmentFlagService.php
//
// Disputed / reversed / under-review marks on an investment position (Board §3). A flagged position is excluded from rank eligibility —
// its principal AND its cycle — until the flag is cleared. Append-only (the latest row decides), always with a reason and the responsible
// user, and every change is audited. Setting a flag never moves money; rank re-evaluation is queued by the model event.

namespace App\Services\Rank;

use App\Models\InvestmentAccount;
use App\Models\InvestmentFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentFlagService
{
    public const TYPES = ['disputed', 'reversed', 'under_review'];

    public function __construct(protected RankBenefitService $audit) {}

    public function current(int $investmentId): ?InvestmentFlag
    {
        $row = InvestmentFlag::where('investment_id', $investmentId)->orderByDesc('id')->first();
        return ($row && $row->action === 'set') ? $row : null;
    }

    public function set(InvestmentAccount $position, string $type, string $reason, User $actor): InvestmentFlag
    {
        if (!in_array($type, self::TYPES, true)) throw ValidationException::withMessages(['flag_type' => ['Choose disputed, reversed or under review.']]);
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);

        return DB::transaction(function () use ($position, $type, $reason, $actor) {
            $i = InvestmentAccount::whereKey($position->id)->lockForUpdate()->firstOrFail();
            $before = $this->current($i->id);
            $row = InvestmentFlag::create(['investment_id' => $i->id, 'flag_type' => $type, 'action' => 'set', 'reason' => trim($reason),
                'actor_id' => $actor->id, 'actor_name' => $this->audit->actorName($actor), 'actor_role' => $actor->role ?? 'staff', 'created_at' => now()]);
            $this->audit->audit('flag', $row->id, 'flag_set', $actor, $before ? ['flag' => $before->flag_type] : null, ['flag' => $type, 'investment_id' => $i->id], $reason);
            return $row;
        });
    }

    public function clear(InvestmentAccount $position, string $reason, User $actor): InvestmentFlag
    {
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);

        return DB::transaction(function () use ($position, $reason, $actor) {
            $i = InvestmentAccount::whereKey($position->id)->lockForUpdate()->firstOrFail();
            $before = $this->current($i->id);
            if (!$before) throw ValidationException::withMessages(['flag' => ['This position has no active flag.']]);
            $row = InvestmentFlag::create(['investment_id' => $i->id, 'flag_type' => $before->flag_type, 'action' => 'cleared', 'reason' => trim($reason),
                'actor_id' => $actor->id, 'actor_name' => $this->audit->actorName($actor), 'actor_role' => $actor->role ?? 'staff', 'created_at' => now()]);
            $this->audit->audit('flag', $row->id, 'flag_cleared', $actor, ['flag' => $before->flag_type], ['flag' => null, 'investment_id' => $i->id], $reason);
            return $row;
        });
    }
}
