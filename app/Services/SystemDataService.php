<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates authorized live-data retrieval for one assistant question.
 *
 * Each source is retrieved independently so an empty result is never confused
 * with a backend failure, and no provider is called with invented data.
 */
final class SystemDataService
{
    public function __construct(
        private readonly AdvisoryRetrievalService $advisories,
        private readonly AnnouncementRetrievalService $announcements,
        private readonly NewsRetrievalService $news,
        private readonly IncidentRetrievalService $incidents,
    ) {
    }

    /**
     * @param  list<string>  $intents
     * @return array<string, mixed>
     */
    public function retrieve(User $user, array $intents): array
    {
        $result = [
            'status' => 'ok',
            'sources' => [],
            'has_records' => false,
            'has_empty' => false,
        ];

        $retrievers = [
            AssistantIntentDetector::ADVISORY => ['source' => 'advisories', 'service' => $this->advisories],
            AssistantIntentDetector::ANNOUNCEMENT => ['source' => 'announcements', 'service' => $this->announcements],
            AssistantIntentDetector::NEWS => ['source' => 'news', 'service' => $this->news],
        ];

        foreach ($retrievers as $intent => $retriever) {
            if (! in_array($intent, $intents, true)) {
                continue;
            }

            $source = $retriever['source'];
            $result['sources'][] = $source;

            try {
                $data = $retriever['service']->retrieve($user);
            } catch (Throwable $e) {
                Log::warning('AI chat retrieval service threw', [
                    'exception' => get_class($e),
                    'source' => $source,
                ]);

                $data = ['status' => 'error', 'records' => []];
            }

            $status = ($data['status'] ?? 'error') === 'ok' ? 'ok' : 'error';
            $records = is_array($data['records'] ?? null) ? array_values($data['records']) : [];

            $result[$source] = [
                'status' => $status,
                'records' => $records,
            ];

            if ($status === 'error') {
                $result['status'] = 'error';
            } elseif ($records === []) {
                $result['has_empty'] = true;
            } else {
                $result['has_records'] = true;
            }
        }

        if (in_array(AssistantIntentDetector::INCIDENT, $intents, true)) {
            $result['sources'][] = 'incident';
            $result['incident'] = $this->incidents->retrieve();
        }

        return $result;
    }

    /**
     * Compact, authoritative data supplied to the prompt. Only the records
     * returned here may be mentioned by the provider.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    public function promptContext(array $data): array
    {
        $context = [];

        foreach ($data['sources'] ?? [] as $source) {
            if (! is_string($source) || ! isset($data[$source]) || ! is_array($data[$source])) {
                continue;
            }

            if ($source === 'incident') {
                $context[$source] = $data[$source];

                continue;
            }

            $context[$source] = [
                'status' => (string) ($data[$source]['status'] ?? 'error'),
                'records' => array_values((array) ($data[$source]['records'] ?? [])),
            ];
        }

        return $context;
    }
}
