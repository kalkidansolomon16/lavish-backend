<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A patient. `user_id` is set once the person has an app account.
 */
class Client extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'app_invited_at' => 'datetime',
            'first_treated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(ClientHistory::class);
    }

    public function preferredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
    }

    public function primaryDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'primary_doctor_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Bills for treatments that are already done but not paid (a receipt waiting to be checked doesn't count).
     * Used to stop a client leaving without paying: reception is alerted, and new bookings wait until it is paid.
     */
    public function unpaidTreatmentBills()
    {
        return $this->payments()->where('payable_type', 'appointment')
            ->whereIn('status', [\App\Enums\PaymentStatus::Unpaid->value, \App\Enums\PaymentStatus::Pending->value, \App\Enums\PaymentStatus::Failed->value])
            ->whereIn('payable_id', Appointment::query()->where('client_id', $this->id)->where('status', 'completed')->select('id'));
    }

    /** ETB still owed for finished treatments. */
    public function owedForTreatments(): int
    {
        return (int) $this->unpaidTreatmentBills()->get()->sum(fn (Payment $p) => $p->dueAmount());
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function loyalty(): HasOne
    {
        return $this->hasOne(ClientLoyalty::class);
    }

    public function pointsLedger(): HasMany
    {
        return $this->hasMany(PointsLedger::class);
    }

    public function pointsRequests(): HasMany
    {
        return $this->hasMany(PointsRequest::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    /** Gift cards this client received (or claimed). */
    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'recipient_client_id');
    }

    /** Gift cards this client bought for someone. */
    public function giftCardsSent(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'purchaser_client_id');
    }

    /** Who referred this client (Lavish Rewards). */
    public function referral(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_client_id');
    }

    public function hasApp(): bool
    {
        return $this->user_id !== null;
    }

    /** Has this doctor completed a treatment with the client? (unlocks chat) */
    public function treatedBy(int $doctorId): bool
    {
        return $this->appointments()
            ->where('doctor_id', $doctorId)
            ->where('status', AppointmentStatus::Completed)
            ->exists();
    }
}
