<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\News;
use App\Models\User;
use App\Services\AdvisoryRetrievalService;
use App\Services\AssistantIntentDetector;
use App\Services\GeminiClient;
use App\Services\IncidentRetrievalService;
use App\Services\LiveDataAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AssistantIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_intent_detection_understands_informal_and_multi_part_questions(): void
    {
        self::assertSame([AssistantIntentDetector::ADVISORY], AssistantIntentDetector::detect('Any advisories?'));
        self::assertSame([AssistantIntentDetector::ADVISORY], AssistantIntentDetector::detect('What advisories are published?'));
        self::assertSame([AssistantIntentDetector::ANNOUNCEMENT], AssistantIntentDetector::detect('Latest announcements?'));
        self::assertSame([AssistantIntentDetector::NEWS], AssistantIntentDetector::detect("What's the latest news?"));
        self::assertSame([AssistantIntentDetector::INCIDENT], AssistantIntentDetector::detect('Can I report an incident?'));
        self::assertSame([AssistantIntentDetector::INCIDENT], AssistantIntentDetector::detect('Where can I find my submitted reports?'));
        self::assertSame([AssistantIntentDetector::KNOWLEDGE], AssistantIntentDetector::detect('What is an advisory?'));
        self::assertSame(
            [AssistantIntentDetector::ADVISORY, AssistantIntentDetector::ANNOUNCEMENT],
            AssistantIntentDetector::detect('Show me advisories and current announcements.')
        );
    }

    public function test_published_advisories_are_retrieved_and_unverified_provider_text_is_replaced(): void
    {
        $user = $this->user();
        $published = now()->subDay();
        $this->story($user, 'Bridge cleanup advisory', 'notice', 'Cleanup on Saturday.', 'published', 'public', $published);
        $this->story($user, 'Draft road closure', 'notice', 'Not public yet.', 'draft', 'public');
        $this->story($user, 'Future road closure', 'notice', 'Scheduled.', 'published', 'public', now()->addDay());
        $this->story($user, 'Town meeting', 'announcement', 'Council meeting.', 'published', 'public', $published);

        $deleted = $this->story($user, 'Deleted advisory', 'notice', 'Removed.', 'published', 'public', $published);
        $deleted->delete();

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->once()->andReturn($this->providerReply("I don't know. Please contact the barangay hall."));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($user, ['news:read']);

        $response = $this->postJson('/api/chat', [
            'message' => 'What advisories are published?',
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('meta.refused', false)
            ->assertJsonPath('meta.live_data', true)
            ->assertJsonPath('meta.source', 'verified-fallback')
            ->assertJsonPath('meta.empty', false);

        $reply = (string) $response->json('reply');

        self::assertStringContainsString('Here are the latest published advisories in Bantay Cordova:', $reply);
        self::assertStringContainsString('1. Bridge cleanup advisory', $reply);
        self::assertStringContainsString('Category: Notice', $reply);
        self::assertStringContainsString('Published: '.$published->format('F j, Y'), $reply);
        self::assertStringContainsString('Summary: Cleanup on Saturday.', $reply);
        self::assertStringNotContainsString('Draft road closure', $reply);
        self::assertStringNotContainsString('Future road closure', $reply);
        self::assertStringNotContainsString('Town meeting', $reply);
        self::assertStringNotContainsString('Deleted advisory', $reply);
    }

    public function test_empty_advisories_get_the_exact_reply_without_a_provider_call(): void
    {
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('send');
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($this->user(), ['news:read']);

        $this->postJson('/api/chat', [
            'message' => 'Any current advisories?',
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('reply', LiveDataAnswer::EMPTY_ADVISORIES)
            ->assertJsonPath('meta.empty', true)
            ->assertJsonPath('meta.live_data', false)
            ->assertJsonPath('meta.source', 'verified')
            ->assertJsonPath('meta.refused', false);
    }

    public function test_empty_announcements_get_the_exact_reply_without_a_provider_call(): void
    {
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('send');
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($this->user(), ['news:read']);

        $this->postJson('/api/chat', [
            'message' => 'Show me the latest announcements.',
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('reply', LiveDataAnswer::EMPTY_ANNOUNCEMENTS)
            ->assertJsonPath('meta.empty', true)
            ->assertJsonPath('meta.source', 'verified');
    }

    public function test_retrieval_failure_is_not_confused_with_an_empty_result(): void
    {
        $retrieval = Mockery::mock(AdvisoryRetrievalService::class);
        $retrieval->shouldReceive('retrieve')->once()->andThrow(new RuntimeException('Database unavailable'));
        $this->app->instance(AdvisoryRetrievalService::class, $retrieval);

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('send');
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($this->user(), ['news:read']);

        $this->postJson('/api/chat', [
            'message' => 'What advisories are published?',
            'session_id' => 'test-session',
        ])->assertStatus(503)
            ->assertJsonPath('reply', LiveDataAnswer::RETRIEVAL_ERROR)
            ->assertJsonPath('meta.retrieval_error', true)
            ->assertJsonPath('meta.source', 'retrieval-error');
    }

    public function test_incident_and_report_questions_are_honest_without_inventing_a_workflow(): void
    {
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldNotReceive('send');
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($this->user(), ['news:read']);

        foreach ([
            'How do I report an incident?',
            'Where can I find my submitted reports?',
            'What does the status of my report mean?',
            'What happens after reporting?',
        ] as $question) {
            $response = $this->postJson('/api/chat', [
                'message' => $question,
                'session_id' => 'test-session',
            ])->assertOk()
                ->assertJsonPath('meta.refused', false)
                ->assertJsonPath('meta.source', 'verified');

            $reply = (string) $response->json('reply');

            self::assertStringContainsString(IncidentRetrievalService::REPLY, $reply);
            self::assertSame(1, substr_count($reply, 'barangay hall'));
            self::assertStringNotContainsString('submit a report', $reply);
            self::assertStringNotContainsString('Under review', $reply);
        }
    }

    public function test_multi_part_request_returns_verified_advisories_and_announcements(): void
    {
        $user = $this->user();
        $published = now()->subDay();
        $this->story($user, 'Bridge cleanup advisory', 'notice', 'Cleanup on Saturday.', 'published', 'public', $published);
        $this->story($user, 'Town meeting', 'announcement', 'Council meeting.', 'published', 'public', $published);

        $reply = "Here are the latest published advisories in Bantay Cordova:\n\n"
            ."1. Bridge cleanup advisory\n   Category: Notice\n   Published: ".$published->format('F j, Y')."\n\n"
            ."Here are the latest published announcements in Bantay Cordova:\n\n"
            ."1. Town meeting\n   Category: Announcement\n   Published: ".$published->format('F j, Y');

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->once()->andReturn($this->providerReply($reply));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($user, ['news:read']);

        $response = $this->postJson('/api/chat', [
            'message' => 'Show me advisories and current announcements.',
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('meta.refused', false)
            ->assertJsonPath('meta.live_data', true)
            ->assertJsonPath('meta.source', 'provider')
            ->assertJsonPath('meta.empty', false);

        self::assertStringContainsString('Bridge cleanup advisory', (string) $response->json('reply'));
        self::assertStringContainsString('Town meeting', (string) $response->json('reply'));
    }

    public function test_a_provider_that_invents_an_extra_record_is_replaced(): void
    {
        $user = $this->user();
        $published = now()->subDay();
        $this->story($user, 'Real cleanup advisory', 'notice', 'Cleanup on Saturday.', 'published', 'public', $published);

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->once()->andReturn($this->providerReply(
            "1. Real cleanup advisory\n2. Imaginary typhoon advisory"
        ));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($user, ['news:read']);

        $response = $this->postJson('/api/chat', [
            'message' => 'Latest advisories?',
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('meta.source', 'verified-fallback');

        self::assertStringContainsString('Real cleanup advisory', (string) $response->json('reply'));
        self::assertStringNotContainsString('Imaginary typhoon advisory', (string) $response->json('reply'));
    }

    public function test_latest_news_lists_all_published_visible_categories(): void
    {
        $user = $this->user();
        $published = now()->subDay();
        $this->story($user, 'Council announcement', 'announcement', 'Meeting notes.', 'published', 'public', $published);
        $this->story($user, 'Fiesta event', 'event', 'Community event.', 'published', 'residents', $published);
        $this->story($user, 'Cleanup notice', 'notice', 'Cleanup schedule.', 'published', 'public', $published);
        $this->story($user, 'Internal draft', 'general', 'Not public.', 'draft', 'public');

        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->once()->andReturn($this->providerReply('Sorry, contact the barangay hall.'));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($user, ['news:read']);

        $response = $this->postJson('/api/chat', [
            'message' => "What's the latest news?",
            'session_id' => 'test-session',
        ])->assertOk()
            ->assertJsonPath('meta.live_data', true)
            ->assertJsonPath('meta.source', 'verified-fallback');

        $reply = (string) $response->json('reply');

        self::assertStringContainsString('Council announcement', $reply);
        self::assertStringContainsString('Fiesta event', $reply);
        self::assertStringContainsString('Cleanup notice', $reply);
        self::assertStringNotContainsString('Internal draft', $reply);
    }

    public function test_simple_system_questions_still_receive_grounded_answers(): void
    {
        $client = Mockery::mock(GeminiClient::class);
        $client->shouldReceive('send')->twice()->andReturn($this->providerReply(
            'Bantay Cordova is a community-focused information and monitoring platform for Cordova residents.'
        ));
        $this->app->instance(GeminiClient::class, $client);

        Sanctum::actingAs($this->user(), ['news:read']);

        foreach (['What is Bantay Cordova?', 'What is your purpose?'] as $question) {
            $this->postJson('/api/chat', [
                'message' => $question,
                'session_id' => 'test-session',
            ])->assertOk()
                ->assertJsonPath('meta.refused', false)
                ->assertJsonPath('meta.source', 'provider');
        }
    }

    /** @return array<string, mixed> */
    private function providerReply(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'model' => 'gemini-3.5-flash-lite',
        ];
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => Hash::make('password123'),
            'role' => 'reader',
            'is_active' => true,
        ]);
    }

    private function story(
        User $user,
        string $title,
        string $category,
        string $summary,
        string $status,
        string $visibility,
        ?\Illuminate\Support\Carbon $publishedAt = null
    ): News {
        return News::create([
            'user_id' => $user->id,
            'title' => $title,
            'category' => $category,
            'summary' => $summary,
            'body' => '<p>Verified body.</p>',
            'status' => $status,
            'visibility' => $visibility,
            'published_at' => $publishedAt,
        ]);
    }
}
