<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TokenAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_read_only_token_cannot_publish_a_story(): void
    {
        $editor = $this->editor();
        $token = $editor->createToken('read-only', ['news:read']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/news', $this->storyPayload('Read-only token story'))
            ->assertForbidden();

        $this->assertDatabaseMissing('news', ['title' => 'Read-only token story']);
    }

    public function test_a_write_token_can_publish_but_its_plaintext_is_not_stored(): void
    {
        $editor = $this->editor();
        $token = $editor->createToken('writer', ['news:write']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/news', $this->storyPayload('Write token story'))
            ->assertCreated()
            ->assertJsonPath('title', 'Write token story');

        $this->assertDatabaseHas('news', [
            'title' => 'Write token story',
            'user_id' => $editor->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $token->plainTextToken,
        ]);
    }

    private function editor(): User
    {
        return User::create([
            'name' => 'Token Editor',
            'email' => 'token-editor@example.com',
            'password' => Hash::make('password123'),
            'role' => 'editor',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storyPayload(string $title): array
    {
        return [
            'title' => $title,
            'category' => 'announcement',
            'summary' => 'A short summary.',
            'body' => '<p>Useful community information.</p>',
            'status' => 'published',
            'visibility' => 'public',
        ];
    }
}
