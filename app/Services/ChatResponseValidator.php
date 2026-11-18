<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Verifies a provider answer against the records retrieved by Laravel.
 *
 * A fluent answer is accepted only when every retrieved record is present and
 * no extra record is introduced. Anything else is replaced by the
 * deterministic, verified answer from LiveDataAnswer.
 */
final class ChatResponseValidator
{
    private const UNHELPFUL_PATTERN = '/\b(?:i don\'t know|i do not know|contact the barangay hall|check the home news feed|contact the barangay office)\b/i';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validate(string $reply, array $data): bool
    {
        if (trim($reply) === '' || preg_match(self::UNHELPFUL_PATTERN, $reply) === 1) {
            return false;
        }

        $expected = LiveDataAnswer::expectedTitles($data);

        if ($expected === []) {
            return true;
        }

        $normalisedReply = self::normalise($reply);
        $normalisedTitles = [];

        foreach ($expected as $title) {
            $normalisedTitle = self::normalise($title);
            $normalisedTitles[$normalisedTitle] = true;

            if (! str_contains($normalisedReply, $normalisedTitle)) {
                return false;
            }
        }

        preg_match_all('/^\s*(?:\d+\.|[-*])\s+(.+)$/mu', $reply, $matches);

        foreach ($matches[1] ?? [] as $item) {
            $normalisedItem = self::normalise($item);

            if ($normalisedItem === '' || str_starts_with($normalisedItem, 'category ') || str_starts_with($normalisedItem, 'published ') || str_starts_with($normalisedItem, 'summary ')) {
                continue;
            }

            $known = false;

            foreach (array_keys($normalisedTitles) as $normalisedTitle) {
                if ($normalisedItem === $normalisedTitle || str_starts_with($normalisedItem, $normalisedTitle)) {
                    $known = true;

                    break;
                }
            }

            if (! $known) {
                return false;
            }
        }

        return true;
    }

    private static function normalise(string $text): string
    {
        $plain = strip_tags($text);
        $plain = preg_replace('/[*_`~]+/', ' ', $plain) ?? $plain;
        $plain = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $plain) ?? $plain;

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }
}
