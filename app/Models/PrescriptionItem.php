<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Either a Lavish product (product_id) or an outside medicine (custom_name) bought at a pharmacy.
 */
class PrescriptionItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['was_out_of_stock' => 'boolean', 'suggested_at' => 'datetime', 'quantity' => 'integer', 'reminder_times' => 'array', 'starts_on' => 'date', 'duration_days' => 'integer'];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** Reminder block for the app, or null when the doctor set no times. */
    public function reminder(): ?array
    {
        if (empty($this->reminder_times)) {
            return null;
        }
        $start = $this->starts_on ?? $this->created_at?->copy()->startOfDay();
        $end = $this->duration_days ? $start?->copy()->addDays($this->duration_days - 1) : null;

        return [
            'times' => array_values($this->reminder_times),
            'starts_on' => $start?->toDateString(),
            'ends_on' => $end?->toDateString(),          // null = keep reminding until the client turns it off
            'duration_days' => $this->duration_days,
            'active' => $end === null || $end->endOfDay()->isFuture(),
        ];
    }

    public function isCustom(): bool
    {
        return $this->product_id === null;
    }
}
