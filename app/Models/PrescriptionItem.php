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
        return ['was_out_of_stock' => 'boolean', 'suggested_at' => 'datetime', 'quantity' => 'integer'];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function isCustom(): bool
    {
        return $this->product_id === null;
    }
}
