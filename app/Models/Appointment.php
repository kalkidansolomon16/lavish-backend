<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Appointment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'source' => BookingSource::class,
            'is_online' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'change_request' => 'array',
            'change_requested_at' => 'datetime',
            'price' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    public function review(): HasOne
    {
        return $this->hasOne(DoctorReview::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeHolding(Builder $q): Builder
    {
        return $q->whereIn('status', AppointmentStatus::holding());
    }

    /** Appointments overlapping [start, end). */
    public function scopeOverlapping(Builder $q, $start, $end): Builder
    {
        return $q->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    public function isPaid(): bool
    {
        return $this->payment?->status === PaymentStatus::Paid;
    }

    /** Online sessions reveal the video link only once confirmed and paid. */
    public function videoLinkVisibleToClient(): bool
    {
        return $this->is_online && $this->status === AppointmentStatus::Approved && $this->isPaid();
    }
}
