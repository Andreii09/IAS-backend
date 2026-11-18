<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RolesAndPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_policies_restrict_story_writes_and_private_visibility(): void
    {
        $reader = $this->user('reader', 'reader@example.com');
        $editor = $this->user('editor', 'editor@example.com');
        $otherEditor = $this->user('editor', 'other@example.com');
        $admin = $this->user('admin', 'admin@example.com');

        $draft = $this->story($editor, 'Secret draft about roads', 'draft');
        $published = $this->story($editor, 'Published community notice');

        $this->assertFalse(Gate::forUser($reader)->allows('create', News::class));
        $this->assertTrue(Gate::forUser($editor)->allows('create', News::class));

        $this->assertTrue(Gate::forUser($editor)->allows('update', $published));
        $this->assertFalse(Gate::forUser($editor)->allows('update', $this->story($otherEditor, 'Another editors story')));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $published));

        $this->assertFalse(Gate::forUser($reader)->allows('view', $draft));
        $this->assertTrue(Gate::forUser($editor)->allows('view', $draft));
    }

    public function test_reader_cannot_reach_admin_and_editor_cannot_edit_another_authors_story(): void
    {
        $reader = $this->user('reader', 'reader@example.com');
        $editor = $this->user('editor', 'editor@example.com');
        $otherEditor = $this->user('editor', 'other@example.com');
        $story = $this->story($editor, 'Owned by first editor');

        Sanctum::actingAs($reader, ['news:read']);
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->getJson('/api/news/'.$story->id.'/revisions')->assertForbidden();

        Sanctum::actingAs($otherEditor, ['news:write']);
        $this->putJson('/api/news/'.$story->id, [
            'title' => 'Hijacked title',
            'category' => 'announcement',
            'body' => '<p>Trying to edit someone else\'s story.</p>',
            'status' => 'published',
            'visibility' => 'public',
        ])->assertForbidden();

        $this->assertDatabaseMissing('news', ['id' => $story->id, 'title' => 'Hijacked title']);
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

    private function story(User $author, string $title, string $status = 'published'): News
    {
        return News::create([
            'user_id' => $author->id,
            'title' => $title,
            'category' => 'announcement',
            'body' => '<p>Story body.</p>',
            'status' => $status,
            'visibility' => 'public',
            'published_at' => $status === 'published' ? now()->subDay() : null,
        ]);
    }
}
