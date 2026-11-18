<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    /**
     * Only created_at is meaningful for chat messages; updated_at is not used.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'content',
        'input_tokens',
        'output_tokens',
        'model',
        'flagged',
        'failed',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'flagged' => 'boolean',
        'failed' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $message): void {
            if (empty($message->created_at)) {
                $message->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }
}
