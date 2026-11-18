<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Advisories are published notice/public_safety news posts in this system.
 * There is no separate advisory table.
 */
class AdvisoryRetrievalService
{
    private const CATEGORIES = ['notice', 'public_safety'];

    public function __construct(private readonly PublishedNewsRetrievalService $news)
    {
    }

    /**
     * @return array{status: string, records: list<array<string, mixed>>}
     */
    public function retrieve(User $user): array
    {
        return $this->news->retrieve($user, self::CATEGORIES);
    }
}
