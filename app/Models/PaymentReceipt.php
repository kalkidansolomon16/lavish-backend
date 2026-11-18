<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A receipt (photo / PDF) proving a payment made outside the app. */
class PaymentReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function toArray(): array
    {
        $file = $this->relationLoaded('attachments') ? $this->attachments->first() : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'method' => $this->method ? ['value' => $this->method->value, 'label' => $this->method->label()] : null,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'note' => $this->note,
            'file' => $file ? ['url' => $file->url(), 'name' => $file->original_name, 'mime' => $file->mime, 'is_image' => str_starts_with($file->mime, 'image/')] : null,
            'uploaded_by' => $this->relationLoaded('uploader') ? $this->uploader?->name : null,
            'reviewed_by' => $this->relationLoaded('reviewer') ? $this->reviewer?->name : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
