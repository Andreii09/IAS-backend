<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Retrieves every published, visible news post regardless of category.
 */
final class NewsRetrievalService
{
    public function __construct(private readonly PublishedNewsRetrievalService $news)
    {
    }

    /**
     * @return array{status: string, records: list<array<string, mixed>>}
     */
    public function retrieve(User $user): array
    {
        return $this->news->retrieve($user, []);
    }
}
