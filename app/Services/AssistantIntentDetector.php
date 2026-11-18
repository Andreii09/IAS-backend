<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic intent detection for system-related questions.
 *
 * Short and informal wording is intentional: a resident should not need to
 * know the database vocabulary (for example "notice") to ask about advisories.
 */
final class AssistantIntentDetector
{
    public const ADVISORY = 'advisory';

    public const ANNOUNCEMENT = 'announcement';

    public const NEWS = 'news';

    public const INCIDENT = 'incident';

    public const KNOWLEDGE = 'knowledge';

    /**
     * @return list<string>
     */
    public static function detect(string $question): array
    {
        $text = self::normalise($question);

        if ($text === '') {
            return [self::KNOWLEDGE];
        }

        $intents = [];
        $explanation = self::isExplanation($text);

        if (self::matchesAdvisory($text, $explanation)) {
            $intents[] = self::ADVISORY;
        }

        if (self::matchesAnnouncement($text, $explanation)) {
            $intents[] = self::ANNOUNCEMENT;
        }

        if (self::matchesNews($text, $explanation)) {
            $intents[] = self::NEWS;
        }

        if (preg_match('/\b(?:incident|emergency|disaster|field report|case record|submitted reports?|my reports?|report status|status of (?:my|a) report|after (?:i )?(?:submit(?:ting)?|report(?:ing)?)|report(?:ing)? an? incident|information needed when reporting|manage reports?)\b/i', $text) === 1) {
            $intents[] = self::INCIDENT;
        }

        if ($intents === []) {
            $intents[] = self::KNOWLEDGE;
        }

        return array_values(array_unique($intents));
    }

    /**
     * @param  list<string>  $intents
     */
    public static function hasLiveData(array $intents): bool
    {
        return array_intersect($intents, [self::ADVISORY, self::ANNOUNCEMENT, self::NEWS]) !== [];
    }

    private static function isExplanation(string $text): bool
    {
        $patterns = [
            '/\bwhat\s+(?:is|are)\s+(?:an?\s+|the\s+)?(?:advisory|advisories|notice|notices|announcement|announcements|news)\b/i',
            '/\b(?:purpose|meaning|define|definition|why)\b.{0,50}\b(?:advisory|advisories|notice|notices|announcement|announcements|news|feed|section)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function matchesAdvisory(string $text, bool $explanation): bool
    {
        $term = '/\b(?:advisories|advisory|notices?|public[ -]?safety)\b/i';

        return self::matchesDataRequest($text, $term, $explanation);
    }

    private static function matchesAnnouncement(string $text, bool $explanation): bool
    {
        $term = '/\b(?:announcements?|bulletins?)\b/i';

        return self::matchesDataRequest($text, $term, $explanation);
    }

    private static function matchesNews(string $text, bool $explanation): bool
    {
        $term = '/\b(?:news|stories|updates?|posts?|feed)\b/i';

        return self::matchesDataRequest($text, $term, $explanation);
    }

    private static function matchesDataRequest(string $text, string $term, bool $explanation): bool
    {
        if ($explanation) {
            return false;
        }

        if (preg_match($term, $text) !== 1) {
            return false;
        }

        // A bare noun ("advisories?") is a valid short request. Otherwise a
        // retrieval cue distinguishes listing data from how-to questions.
        if (preg_match('/^\s*(?:any\s+)?(?:current\s+|latest\s+|recent\s+)?(?:advisories|announcements?|news|stories|updates?)\??\s*$/i', $text) === 1) {
            return true;
        }

        // Multi-part requests such as "advisories and announcements" are data
        // requests even without an explicit verb.
        if (preg_match('/\b(?:advisories|announcements?|news|stories|updates?|notices?)\b.{0,30}\b(?:and|&|,)\b.{0,30}\b(?:advisories|announcements?|news|stories|updates?|notices?)\b/i', $text) === 1) {
            return true;
        }

        return preg_match('/\b(?:any|current|latest|recent|new|published|show|list|see|read|view|fetch|get|give|available|updates?|what|what\'s|whats|tell me)\b/i', $text) === 1;
    }

    private static function normalise(string $text): string
    {
        $clean = preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? mb_strtolower(trim($text));

        return trim($clean, " \t\n\r\0\x0B.!?");
    }
}
