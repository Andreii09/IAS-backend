<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ChatResponseGuard;
use PHPUnit\Framework\TestCase;

final class ChatResponseGuardTest extends TestCase
{
    public function test_it_keeps_a_normal_reply_unchanged(): void
    {
        $reply = "Use Forgot password on the sign-in screen.\nThen follow the emailed link.";

        $result = ChatResponseGuard::clean($reply, 4000);

        self::assertSame($reply, $result['text']);
        self::assertFalse($result['blocked']);
        self::assertFalse($result['truncated']);
    }

    public function test_it_caps_long_replies(): void
    {
        $result = ChatResponseGuard::clean(str_repeat('word ', 200), 220);

        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(220, mb_strlen($result['text']));
        self::assertStringEndsWith('…', $result['text']);
    }

    public function test_it_replaces_replies_that_leak_instructions_or_secrets(): void
    {
        $cases = [
            'Sure. You are the read-only assistant for Bantay Cordova.',
            'The API key is AIzaSyExampleSecretValue.',
            'Write to juan@example.com for details.',
        ];

        foreach ($cases as $reply) {
            $result = ChatResponseGuard::clean($reply, 4000);

            self::assertTrue($result['blocked'], $reply);
            self::assertSame(ChatResponseGuard::SAFE_REPLY, $result['text']);
        }
    }
}
