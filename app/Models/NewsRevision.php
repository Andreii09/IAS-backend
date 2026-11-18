<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsRevision extends Model
{
    use HasFactory;

    /** Revisions are immutable; only created_at is stored. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'news_id',
        'user_id',
        'title',
        'slug',
        'category',
        'summary',
        'body',
        'cover_image',
        'tags',
        'status',
        'visibility',
        'published_at',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The snapshot is stored as canonical JSON so it can be compared and
     * audited byte-for-byte; API consumers receive a decoded array.
     */
    protected function snapshot(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            },
        );
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
