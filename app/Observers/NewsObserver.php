<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\News;
use App\Models\NewsRevision;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class NewsObserver
{
    /** Attributes captured for audit/revision snapshots. */
    private const AUDITED = [
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
    ];

    /** @var array<int, array<string, mixed>> */
    private static array $oldAttributes = [];

    public function created(News $news): void
    {
        AuditLogger::record('news.created', $news, [], $this->snapshot($news->getAttributes()));
    }

    /**
     * Snapshot the version being replaced before the UPDATE statement runs.
     */
    public function updating(News $news): void
    {
        self::$oldAttributes[spl_object_id($news)] = $news->getAttributes();

        $old = $news->getOriginal();

        NewsRevision::query()->create([
            'news_id' => $news->getKey(),
            'user_id' => Auth::id(),
            'title' => $old['title'] ?? null,
            'slug' => $old['slug'] ?? null,
            'category' => $old['category'] ?? null,
            'summary' => $old['summary'] ?? null,
            'body' => $old['body'] ?? null,
            'cover_image' => $old['cover_image'] ?? null,
            'tags' => $this->decodeTags($old['tags'] ?? null),
            'status' => $old['status'] ?? null,
            'visibility' => $old['visibility'] ?? null,
            'published_at' => $old['published_at'] ?? null,
            'snapshot' => AuditLogger::canonicalJson($old),
        ]);
    }

    public function updated(News $news): void
    {
        $old = self::$oldAttributes[spl_object_id($news)] ?? [];
        unset(self::$oldAttributes[spl_object_id($news)]);

        AuditLogger::record(
            'news.updated',
            $news,
            $this->snapshot($old),
            $this->snapshot($news->getAttributes())
        );
    }

    public function deleted(News $news): void
    {
        AuditLogger::record('news.deleted', $news, $this->snapshot($news->getAttributes()), []);
    }

    public function restored(News $news): void
    {
        AuditLogger::record('news.restored', $news, [], $this->snapshot($news->getAttributes()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        $snapshot = [];

        foreach (self::AUDITED as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            if ($field === 'tags' && is_string($value)) {
                $value = $this->decodeTags($value);
            }

            if ($field === 'body' && is_string($value)) {
                $value = Str::limit($value, 2000);
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    /**
     * @return array<int, string>|null
     */
    private function decodeTags(mixed $tags): ?array
    {
        if (is_array($tags)) {
            return array_values($tags);
        }

        if (is_string($tags) && $tags !== '') {
            $decoded = json_decode($tags, true);

            return is_array($decoded) ? array_values($decoded) : null;
        }

        return null;
    }
}
