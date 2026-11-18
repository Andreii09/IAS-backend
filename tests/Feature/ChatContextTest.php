<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\News;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GeminiClientException;
use App\Services\ChatContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ChatContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_prompt_only_contains_stories_the_reader_may_see(): void
    {
        $reader = $this->user('reader', 'reader@example.com');

        News::create([
            'user_id' => $reader->id,
            'title' => 'Public advisory about cleanup',
            'category' => 'notice',
            'summary' => 'Public cleanup schedule',
            'body' => '<p>Cleanup drives are open to all residents.</p>',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        News::create([
            'user_id' => $reader->id,
            'title' => 'Secret draft about roads',
            'category' => 'general',
            'body' => '<p>Internal only.</p>',
            'status' => 'draft',
            'visibility' => 'public',
        ]);

        News::create([
            'user_id' => $reader->id,
            'title' => 'Residents only bulletin',
            'category' => 'general',
            'body' => '<p>Authenticated residents only.</p>',
            'status' => 'published',
            'visibility' => 'residents',
            'published_at' => now()->subDay(),
        ]);

        $prompt = (new ChatContextBuilder())->systemPrompt($reader, 'cleanup');

        $this->assertStringContainsString('Public advisory about cleanup', $prompt);
        $this->assertStringNotContainsString('Secret draft about roads', $prompt);
        $this->assertStringNotContainsString('Residents only bulletin', $prompt);
        $this->assertStringNotContainsString($reader->email, $prompt);
        $this->assertStringContainsString('not an administrator', $prompt);
    }

    public function test_provider_failure_returns_friendly_reply_and_stores_only_masked_text(): void
    {
        $user = $this->user('reader', 'reader@example.com');

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->once()->andThrow(new GeminiClientException('Provider offline'));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($user, ['news:read']);

        $this->postJson('/api/chat', [
            'message' => 'Please email juan@example.com about this',
            'session_id' => 'test-session',
        ])->assertStatus(503)->assertJsonPath(
            'reply',
            'The assistant is unavailable right now. You can still browse and manage news as usual.'
        );

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $user->id,
            'content' => 'Please email [email] about this',
            'flagged' => 1,
            'failed' => 1,
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'content' => 'Please email juan@example.com about this',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.chat.flagged']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
