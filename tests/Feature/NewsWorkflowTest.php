<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NewsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_story_creates_a_revision_and_audit_entry(): void
    {
        $editor = User::create([
            'name' => 'Workflow Editor',
            'email' => 'workflow@example.com',
            'password' => Hash::make('password123'),
            'role' => 'editor',
            'is_active' => true,
        ]);

        Sanctum::actingAs($editor, ['news:write']);

        $created = $this->postJson('/api/news', [
            'title' => 'Original title',
            'category' => 'announcement',
            'body' => '<p>Original body.</p>',
            'status' => 'published',
            'visibility' => 'public',
        ])->assertCreated()->json();

        $this->putJson('/api/news/'.$created['id'], [
            'title' => 'Updated title',
            'category' => 'notice',
            'body' => '<p>Safe body.</p><script>alert(1)</script>',
            'status' => 'published',
            'visibility' => 'public',
        ])->assertOk();

        $story = News::findOrFail($created['id']);

        $this->assertSame('Updated title', $story->title);
        $this->assertStringNotContainsString('<script', $story->body);
        $this->assertSame(1, NewsRevision::query()->where('news_id', $story->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'news.created',
            'entity_type' => (new News())->getMorphClass(),
            'entity_id' => (string) $story->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'news.updated',
            'entity_type' => (new News())->getMorphClass(),
            'entity_id' => (string) $story->id,
        ]);
    }
}
