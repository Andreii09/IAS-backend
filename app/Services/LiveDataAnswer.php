<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic, verified answers built only from retrieved records.
 *
 * These answers are used directly for empty/error/module limitations and as a
 * safety fallback whenever a provider reply cannot be verified.
 */
final class LiveDataAnswer
{
    public const EMPTY_ADVISORIES = 'There are currently no published advisories available in the Bantay Cordova system.';

    public const EMPTY_ANNOUNCEMENTS = 'There are currently no published announcements available in the system.';

    public const RETRIEVAL_ERROR = "I'm currently unable to retrieve that information from Bantay Cordova. Please try again later.";

    /** @var array<string, array{heading: string, empty: string, follow_up: string}> */
    private const SOURCES = [
        'advisories' => [
            'heading' => 'Here are the latest published advisories in Bantay Cordova:',
            'empty' => self::EMPTY_ADVISORIES,
            'follow_up' => 'Would you like to know more about a particular advisory?',
        ],
        'announcements' => [
            'heading' => 'Here are the latest published announcements in Bantay Cordova:',
            'empty' => self::EMPTY_ANNOUNCEMENTS,
            'follow_up' => 'Would you like to know more about a particular announcement?',
        ],
        'news' => [
            'heading' => 'Here is the latest published news in Bantay Cordova:',
            'empty' => 'There are currently no published news posts available in the Bantay Cordova system.',
            'follow_up' => 'Would you like to know more about a particular story?',
        ],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function hasRecords(array $data): bool
    {
        return (bool) ($data['has_records'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function mustAnswerDirectly(array $data): bool
    {
        return ! self::hasRecords($data)
            || (bool) ($data['has_empty'] ?? false)
            || isset($data['incident']);
    }

    /**
     * Build a complete answer without relying on the model.
     *
     * @param  list<string>  $intents
     * @param  array<string, mixed>  $data
     */
    public static function build(array $intents, array $data): string
    {
        $sections = [];

        foreach (array_keys(self::SOURCES) as $source) {
            if (! isset($data[$source]) || ! is_array($data[$source])) {
                continue;
            }

            $config = self::SOURCES[$source];
            $records = array_values((array) ($data[$source]['records'] ?? []));

            if ($records === []) {
                $sections[] = $config['empty'];

                continue;
            }

            $lines = [$config['heading']];

            foreach ($records as $index => $record) {
                if (! is_array($record)) {
                    continue;
                }

                $number = $index + 1;
                $lines[] = $number.'. '.(string) ($record['title'] ?? 'Untitled');
                $lines[] = '   Category: '.(string) ($record['category_label'] ?? $record['category'] ?? 'Uncategorized');
                $lines[] = '   Published: '.(string) ($record['published_label'] ?? 'Date unavailable');

                $summary = trim((string) ($record['summary'] ?? ''));

                if ($summary !== '') {
                    $lines[] = '   Summary: '.$summary;
                }
            }

            $lines[] = $config['follow_up'];
            $sections[] = implode("\n", $lines);
        }

        if (isset($data['incident']) && is_array($data['incident'])) {
            $sections[] = (string) ($data['incident']['reply'] ?? IncidentRetrievalService::REPLY);
        }

        return trim(implode("\n\n", $sections));
    }

    /**
     * All record titles that an answer must account for.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function expectedTitles(array $data): array
    {
        $titles = [];

        foreach (array_keys(self::SOURCES) as $source) {
            foreach ((array) ($data[$source]['records'] ?? []) as $record) {
                if (is_array($record)) {
                    $title = trim((string) ($record['title'] ?? ''));

                    if ($title !== '') {
                        $titles[] = $title;
                    }
                }
            }
        }

        return array_values(array_unique($titles));
    }
}
