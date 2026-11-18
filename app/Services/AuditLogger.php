<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use JsonSerializable;

/**
 * Central audit writer with a tamper-evident hash chain.
 *
 * The exact same canonical field order and serialization is used by record()
 * and verify(); changing either will invalidate existing chains.
 */
final class AuditLogger
{
    public const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fixed canonical field order for fingerprints. */
    public const FIELDS = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
        'prev_hash',
        'created_at',
    ];

    /** The one canonical JSON encoder used for audit payloads and fingerprints. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function canonicalJson(array $data): string
    {
        $json = json_encode($data, self::JSON_FLAGS);

        return $json === false ? 'null' : $json;
    }

    /**
     * Canonical fingerprint of an audit row.
     *
     * Accepts either raw database attributes (strings) or the values used when
     * inserting a row (ints, arrays, DateTime objects); both normalize to the
     * same representation.
     */
    public static function fingerprint(array $row): string
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $value = self::canonicalJson($value);
            } elseif ($value instanceof JsonSerializable) {
                $value = self::canonicalJson($value->jsonSerialize());
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $values[] = $value === null ? null : (string) $value;
        }

        $json = json_encode($values, self::JSON_FLAGS);

        return hash('sha256', $json === false ? '' : $json);
    }

    /**
     * Append an audit entry. The latest row is locked so concurrent writers
     * cannot fork the hash chain.
     */
    public static function record(string $action, $entity = null, array $old = [], array $new = []): void
    {
        [$entityType, $entityId] = self::resolveEntity($entity);

        $request = request();
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        // Works for both SPA cookie auth and bearer-token (Sanctum) requests.
        $userId = Auth::id() ?? request()?->user()?->getAuthIdentifier();
        $createdAt = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use (
            $action,
            $entityType,
            $entityId,
            $old,
            $new,
            $ip,
            $userAgent,
            $userId,
            $createdAt
        ): void {
            /** @var AuditLog|null $previous */
            $previous = AuditLog::query()->lockForUpdate()->orderByDesc('id')->first();
            $prevHash = $previous?->hash ?? self::ZERO_HASH;

            $payload = [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => self::canonicalJson($old),
                'new_values' => self::canonicalJson($new),
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1000),
                'prev_hash' => $prevHash,
                'created_at' => $createdAt,
            ];

            $payload['hash'] = self::fingerprint($payload);

            AuditLog::query()->create($payload);
        });
    }

    /**
     * Verify every hash and every chain link.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public static function verify(): array
    {
        $errors = [];
        $previousHash = self::ZERO_HASH;

        AuditLog::query()->orderBy('id')->chunkById(500, function ($logs) use (&$errors, &$previousHash): void {
            foreach ($logs as $log) {
                $attributes = $log->getAttributes();
                $expected = self::fingerprint($attributes);
                $stored = (string) ($attributes['hash'] ?? '');
                $storedPrev = (string) ($attributes['prev_hash'] ?? '');

                if (! hash_equals($expected, $stored)) {
                    $errors[] = sprintf(
                        'Audit row #%s: hash mismatch (expected %s, found %s).',
                        $log->getKey(),
                        $expected,
                        $stored
                    );
                }

                if (! hash_equals($previousHash, $storedPrev)) {
                    $errors[] = sprintf(
                        'Audit row #%s: broken chain (expected prev_hash %s, found %s).',
                        $log->getKey(),
                        $previousHash,
                        $storedPrev
                    );
                }

                // Follow the recorded chain so one tampered row does not
                // produce a misleading cascade of chain errors.
                $previousHash = $stored;
            }
        });

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveEntity($entity): array
    {
        if ($entity instanceof Model) {
            return [$entity->getMorphClass(), (string) $entity->getKey()];
        }

        if (is_object($entity) && method_exists($entity, 'getKey')) {
            return [get_class($entity), (string) $entity->getKey()];
        }

        if (is_string($entity)) {
            return [$entity, null];
        }

        if (is_array($entity)) {
            $type = $entity['entity_type'] ?? $entity['type'] ?? null;
            $id = $entity['entity_id'] ?? $entity['id'] ?? null;

            return [
                $type === null ? null : (string) $type,
                $id === null ? null : (string) $id,
            ];
        }

        return [null, null];
    }
}
