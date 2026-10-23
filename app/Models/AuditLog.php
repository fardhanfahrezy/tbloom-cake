<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /** @return array{actor_type: class-string<AuditActorType>, entity_id: string, before_redacted: string, after_redacted: string, created_at: string} */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'entity_id' => 'integer',
            'before_redacted' => 'array',
            'after_redacted' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Audit logs are append-only.');
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException('Audit logs are append-only.');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException('Audit logs are append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit logs are append-only.');
    }
}
