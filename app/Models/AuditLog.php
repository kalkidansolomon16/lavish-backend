<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who changed what, and why — every manual points / gift card / program-setting change. */
class AuditLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(?User $actor, string $action, string $entity, ?int $entityId, ?string $reason = null, ?array $before = null, ?array $after = null): self
    {
        return static::create([
            'actor_id' => $actor?->id, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
            'reason' => $reason, 'before' => $before, 'after' => $after,
        ]);
    }
}
