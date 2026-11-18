<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Gemini client with availability controls.
 *
 * The chat controller keeps sending a provider-neutral payload. This client
 * translates it to Gemini's native generateContent format and normalises the
 * response. Transient failures get one retry; repeated failures briefly open
 * a cache-backed circuit so the widget degrades to offline help instead of
 * hammering the provider.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const FAILURES_KEY = 'ai:gemini:failures';

    private const OPEN_KEY = 'ai:gemini:circuit-open';

    private const TRANSIENT_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $key = config('services.gemini.key');

        if (! is_string($key) || trim($key) === '') {
            throw new GeminiClientException('The Gemini API key is not configured.');
        }

        $model = trim((string) ($payload['model'] ?? config('services.gemini.model') ?? ''));
        if ($model === '') {
            throw new GeminiClientException('The Gemini model is not configured.');
        }

        $contents = $this->contents($payload['messages'] ?? []);
        if ($contents === []) {
            throw new GeminiClientException('The Gemini request has no messages.');
        }

        $this->assertCircuitClosed();

        $body = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => is_string($payload['system'] ?? null) ? (string) $payload['system'] : ''],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => max(1, (int) ($payload['max_tokens'] ?? 600)),
                'temperature' => 0.4,
            ],
        ];

        $url = sprintf(self::ENDPOINT, rawurlencode($model));
        $retries = max(0, (int) config('ai.retry.attempts', 1));
        $delayMs = max(0, (int) config('ai.retry.delay_ms', 350));
        $lastError = null;

        for ($attempt = 1; $attempt <= $retries + 1; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => trim($key),
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                ])->timeout(30)->post($url, $body);

                if ($response->failed()) {
                    $status = $response->status();
                    // Log metadata only - never headers, payload, key or prompt text.
                    Log::warning('Gemini request failed', ['status' => $status, 'attempt' => $attempt]);

                    $lastError = new GeminiClientException(
                        'The Gemini service returned status '.$status.'.',
                        $status
                    );
                } else {
                    $data = $response->json();

                    if (! is_array($data)) {
                        throw new GeminiClientException('The Gemini service returned an invalid response.', 500);
                    }

                    $this->clearCircuitFailures();

                    $result = $this->normalise($data, $model);
                    $result['attempts'] = $attempt;

                    return $result;
                }
            } catch (GeminiClientException $e) {
                $lastError = $e;
            } catch (Throwable $e) {
                // Treat network/transport problems as transient (503).
                Log::warning('Gemini transport failure', ['exception' => get_class($e), 'attempt' => $attempt]);

                $lastError = new GeminiClientException('The Gemini service could not be reached.', 503, $e);
            }

            if (! $this->isTransient($lastError) || $attempt > $retries) {
                break;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->recordCircuitFailure();

        throw $lastError ?? new GeminiClientException('The Gemini service is unavailable.', 503);
    }

    private function assertCircuitClosed(): void
    {
        if (! (bool) config('ai.circuit_breaker.enabled', true)) {
            return;
        }

        if ((bool) Cache::get(self::OPEN_KEY, false)) {
            throw new GeminiClientException(
                'The Gemini circuit breaker is open.',
                GeminiClientException::CIRCUIT_OPEN
            );
        }
    }

    private function recordCircuitFailure(): void
    {
        if (! (bool) config('ai.circuit_breaker.enabled', true)) {
            return;
        }

        $threshold = max(1, (int) config('ai.circuit_breaker.threshold', 3));
        $ttl = max(1, (int) config('ai.circuit_breaker.ttl_seconds', 60));
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;

        Cache::put(self::FAILURES_KEY, $failures, $ttl);

        if ($failures >= $threshold) {
            Cache::put(self::OPEN_KEY, true, $ttl);
            Log::warning('Gemini circuit breaker opened', ['failures' => $failures]);
        }
    }

    private function clearCircuitFailures(): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::OPEN_KEY);
    }

    private function isTransient(GeminiClientException $error): bool
    {
        return in_array($error->getCode(), self::TRANSIENT_STATUSES, true);
    }

    /**
     * @param  mixed  $messages
     * @return list<array<string, mixed>>
     */
    private function contents(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $contents = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $text = $message['content'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $role = ($message['role'] ?? null) === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]],
            ];
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, string $model): array
    {
        $text = '';

        foreach ($data['candidates'] ?? [] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text = trim($part['text']);
                    break 2;
                }
            }
        }

        $usage = $data['usageMetadata'] ?? [];

        return [
            'content' => $text === '' ? [] : [
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => [
                'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
            ],
            'model' => (string) ($data['modelVersion'] ?? $model),
        ];
    }
}
