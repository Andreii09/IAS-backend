<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OfflineHelp;
use PHPUnit\Framework\TestCase;

final class OfflineHelpTest extends TestCase
{
    public function test_it_answers_common_questions_from_the_knowledge_base(): void
    {
        self::assertStringContainsString('Forgot password', (string) OfflineHelp::answer('How do I reset my password?', 'reader'));
        self::assertStringContainsString('reader account', (string) OfflineHelp::answer('Can I register as an editor?', 'reader'));
        self::assertStringContainsString('scheduled', (string) OfflineHelp::answer('What is a scheduled story?', 'editor'));
    }

    public function test_it_never_offers_admin_topics_to_other_roles(): void
    {
        self::assertNull(OfflineHelp::answer('How do I verify the audit chain?', 'reader'));
        self::assertNull(OfflineHelp::answer('How do token abilities work?', 'editor'));

        self::assertStringContainsString('audit:verify', (string) OfflineHelp::answer('How do I verify the audit chain?', 'admin'));
    }

    public function test_it_returns_null_for_uncovered_questions(): void
    {
        self::assertNull(OfflineHelp::answer('What is the weather in Cordova tomorrow?', 'reader'));
    }
}
