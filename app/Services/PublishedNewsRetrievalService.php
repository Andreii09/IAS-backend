<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\News;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared, read-only retrieval for published news records.
 *
 * Every caller is scoped to published, non-deleted rows that the signed-in
 * user may see. Drafts, scheduled/future posts and soft-deleted rows never
 * reach the assistant.
 */
class PublishedNewsRetrievalService
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'announcement' => 'Announcement',
        'notice' => 'Notice',
        'event' => 'Event',
        'public_safety' => 'Public safety',
        'general' => 'General',
    ];

    /**
     * @param  list<string>  $categories  Empty list means every category.
     * @return array{status: string, records: list<array<string, mixed>>}
     */
    public function retrieve(User $user, array $categories = [], int $limit = 10): array
    {
        try {
            $query = News::query();

            if (method_exists(News::class, 'scopePublished')) {
                $query->published();
            }

            if (method_exists(News::class, 'scopeVisibleTo')) {
                $query->visibleTo($user);
            }

            if ($categories !== []) {
                $query->whereIn('category', array_values(array_unique($categories)));
            }

            $records = $query->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(max(1, min(20, $limit)))
                ->get()
                ->map(fn (News $story): array => $this->mapRecord($story))
                ->values()
                ->all();

            return ['status' => 'ok', 'records' => $records];
        } catch (Throwable $e) {
            Log::warning('AI chat published news retrieval failed', [
                'exception' => get_class($e),
                'detail' => ChatContextBuilder::maskPii($e->getMessage()),
            ]);

            return ['status' => 'error', 'records' => []];
        }
    }

    /** @return array<string, mixed> */
    private function mapRecord(News $story): array
    {
        $category = (string) ($story->category ?? 'general');
        $published = $story->published_at;

        return [
            'id' => (int) $story->id,
            'title' => $this->clean((string) ($story->title ?? '')),
            'category' => $category,
            'category_label' => self::CATEGORY_LABELS[$category] ?? ($category !== '' ? ucfirst($category) : 'Uncategorized'),
            'published_at' => $published?->toIso8601String() ?? '',
            'published_label' => $published?->format('F j, Y') ?? 'Date unavailable',
            'summary' => $this->clean((string) ($story->summary ?? '')),
        ];
    }

    private function clean(string $value): string
    {
        $plain = strip_tags($value);
        $collapsed = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return ChatContextBuilder::maskPii(trim($collapsed));
    }
}
