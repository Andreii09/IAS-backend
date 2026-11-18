<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Last line of defence for assistant replies (CIA: integrity + confidentiality).
 *
 * The system prompt asks the model to stay in character, but a model can still
 * echo hidden instructions or sensitive-looking values. Every reply is checked
 * here before it is returned to the browser or written to the transcript.
 */
final class ChatResponseGuard
{
    public const SAFE_REPLY = 'I can only share general Bantay Cordova information and published stories. Please ask another question or contact the barangay hall.';

    /** @var list<string> */
    private const LEAK_PATTERNS = [
        '/you are the read-only assistant/i',
        '/systeminstruction/i',
        '/knowledge base \(fallback\)/i',
        '/\brules:\s*\r?\n\s*-/i',
        '/\bapi\s*[-_ ]?\s*key\b\s*(?:is|=|:)?\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
        '/\bpassword\b\s*(?:is|=|:)\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        '/(?<!\w)(?:\+?63[\s.\-]?)?(?:0?9\d{2}[\s.\-]?\d{3}[\s.\-]?\d{4})(?!\w)/',
    ];

    /**
     * Normalise, validate and bound a model reply.
     *
     * @return array{text: string, blocked: bool, truncated: bool}
     */
    public static function clean(string $reply, int $maxChars): array
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $reply) ?? $reply;
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? trim($text);

        if ($text === '' || self::containsLeak($text)) {
            return ['text' => self::SAFE_REPLY, 'blocked' => true, 'truncated' => false];
        }

        $limit = max(200, $maxChars);
        $truncated = false;

        if (mb_strlen($text) > $limit) {
            $cut = mb_substr($text, 0, $limit - 1);
            $lastSpace = mb_strrpos($cut, ' ');

            if ($lastSpace !== false && $lastSpace > (int) ($limit * 0.6)) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }

            $text = rtrim($cut).'…';
            $truncated = true;
        }

        return ['text' => $text, 'blocked' => false, 'truncated' => $truncated];
    }

    private static function containsLeak(string $text): bool
    {
        foreach (self::LEAK_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
