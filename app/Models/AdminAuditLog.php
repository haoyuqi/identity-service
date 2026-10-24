<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['actor_id', 'target_user_id', 'action'])]
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    public function setAttribute($key, $value)
    {
        if ($key === 'created_at' && $value !== null) {
            $value = Carbon::parse($value, 'UTC')->utc();
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Get the actor who performed the audited action, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the account affected by the audited action.
     *
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Admin audit logs are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Admin audit logs are append-only.');
        });
    }
}
