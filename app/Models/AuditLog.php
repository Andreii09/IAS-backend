<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit trail.
 *
 * Rows are written exclusively through App\Services\AuditLogger so the hash
 * chain stays consistent. Eloquent updates and deletes are rejected.
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
        'prev_hash',
        'hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit log entries are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * Canonical JSON is stored as a string so the hash chain stays stable, but
     * API consumers receive decoded arrays.
     */
    protected function oldValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => self::decodeValues($value),
        );
    }

    protected function newValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => self::decodeValues($value),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeValues(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
