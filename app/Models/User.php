<?php
// LOCATION: app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'full_name',
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'status',
        'phone',
        'country_code',
        'country',
        'country_iso2',
        'address',
        'city',
        'state',
        'postal_code',
        'date_of_birth',
        'balance',
        'referral_code',
        'referred_by',
        'registration_stage',
        'registration_completed',
        'employment_status',
        'annual_income_range',
        'source_of_funds',
        'investment_experience',
        'risk_tolerance',
        'investment_objectives',
        'withdrawal_pin',
        'two_factor_enabled',
        'profile_photo',
        'avatar',
        'last_login_at',
        // KYC
        'id_type',
        'id_number',
        'id_document_path',
        'selfie_path',
        'kyc_status',
        'kyc_verified',
        'kyc_verified_at',
        'kyc_rejection_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'withdrawal_pin',
    ];

    protected $casts = [
        'email_verified_at'       => 'datetime',
        'last_login_at'           => 'datetime',
        'date_of_birth'           => 'date',
        'balance'                 => 'decimal:2',
        'registration_completed'  => 'boolean',
        'two_factor_enabled'      => 'boolean',
        'kyc_verified'            => 'boolean',
        'kyc_verified_at'         => 'datetime',
    ];

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────

public function sendPasswordResetNotification($token)
{
    $this->notify(new ResetPasswordNotification($token));
}
    // All investment accounts for this user
    public function investmentAccounts()
    {
        return $this->hasMany(InvestmentAccount::class);
    }

    // Alias
    public function investments()
    {
        return $this->hasMany(InvestmentAccount::class);
    }

    // All deposits
    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    // All withdrawals
    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    // All messages sent TO admin
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    // All tasks assigned to this investor (new shared-code model)
    public function taskAssignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    // Who referred this user
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // Users this person referred
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    // ── HELPERS ────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isInvestor(): bool
    {
        return $this->role === 'investor';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    // Total invested amount
    public function getTotalInvestedAttribute(): float
    {
        return (float) $this->investmentAccounts()->sum('amount');
    }

    // Total expected profit
    public function getTotalProfitAttribute(): float
    {
        return (float) $this->investmentAccounts()->sum('expected_profit');
    }

    // Count of active investments
    public function getActiveInvestmentsCountAttribute(): int
    {
        return $this->investmentAccounts()->where('status', 'active')->count();
    }

    // KYC status, derived defensively in case of legacy null/blank values
    public function getKycStatusSafeAttribute(): string
    {
        return $this->kyc_status ?: ($this->id_document_path ? 'pending' : 'not_submitted');
    }
}