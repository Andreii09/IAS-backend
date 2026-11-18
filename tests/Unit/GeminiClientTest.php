<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeminiClient;
use App\Services\GeminiClientException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GeminiClientTest extends TestCase
{
    public function test_it_translates_the_payload_and_normalises_the_response(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.5-flash-lite');

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Bantay Cordova is a community news platform.'],
                        ],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 12,
                    'candidatesTokenCount' => 7,
                ],
                'modelVersion' => 'gemini-3.5-flash-lite',
            ]),
        ]);

        $result = (new GeminiClient())->send([
            'model' => 'gemini-3.5-flash-lite',
            'max_tokens' => 600,
            'system' => 'Be helpful.',
            'messages' => [
                ['role' => 'user', 'content' => 'What is this site?'],
                ['role' => 'assistant', 'content' => 'It is a news site.'],
                ['role' => 'user', 'content' => 'Tell me more.'],
            ],
        ]);

        self::assertSame('Bantay Cordova is a community news platform.', $result['content'][0]['text']);
        self::assertSame(12, $result['usage']['input_tokens']);
        self::assertSame(7, $result['usage']['output_tokens']);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'gemini-3.5-flash-lite:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && data_get($request->data(), 'systemInstruction.parts.0.text') === 'Be helpful.'
                && data_get($request->data(), 'contents.0.role') === 'user'
                && data_get($request->data(), 'contents.1.role') === 'model'
                && data_get($request->data(), 'contents.2.role') === 'user'
                && data_get($request->data(), 'generationConfig.maxOutputTokens') === 600;
        });
    }

    public function test_it_rejects_a_missing_api_key(): void
    {
        config()->set('services.gemini.key', '');

        $this->expectException(GeminiClientException::class);

        (new GeminiClient())->send([
            'model' => 'gemini-3.5-flash-lite',
            'system' => 'Be helpful.',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);
    }
}
