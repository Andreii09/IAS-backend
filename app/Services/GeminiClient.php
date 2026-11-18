<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Gemini client.
 *
 * The chat controller keeps sending the same provider-neutral payload it used
 * with the previous provider. This client translates it to Gemini's native
 * generateContent format and normalises the response back to the shape the
 * controller and AI-usage dashboard already understand.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

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

        $system = $payload['system'] ?? '';
        $maxOutputTokens = max(1, (int) ($payload['max_tokens'] ?? 600));

        $body = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => is_string($system) ? $system : ''],
                ],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxOutputTokens,
                'temperature' => 0.4,
            ],
        ];

        $url = sprintf(self::ENDPOINT, rawurlencode($model));

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => trim($key),
                'content-type' => 'application/json',
                'accept' => 'application/json',
            ])->timeout(30)->post($url, $body);
        } catch (Throwable $e) {
            // Log metadata only - never headers, payload or the key itself.
            Log::warning('Gemini transport failure', ['exception' => get_class($e)]);

            throw new GeminiClientException('The Gemini service could not be reached.', 0, $e);
        }

        if ($response->failed()) {
            Log::warning('Gemini request failed', ['status' => $response->status()]);

            throw new GeminiClientException('The Gemini service returned status '.$response->status().'.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new GeminiClientException('The Gemini service returned an invalid response.');
        }

        return $this->normalise($data, $model);
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
