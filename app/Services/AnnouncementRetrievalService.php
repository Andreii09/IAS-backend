<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Announcements are published news posts with the announcement category.
 * There is no separate announcement table.
 */
final class AnnouncementRetrievalService
{
    public function __construct(private readonly PublishedNewsRetrievalService $news)
    {
    }

    /**
     * @return array{status: string, records: list<array<string, mixed>>}
     */
    public function retrieve(User $user): array
    {
        return $this->news->retrieve($user, ['announcement']);
    }
}
