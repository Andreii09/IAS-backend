<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Small, factual answer set used when the AI provider is unreachable
 * (CIA: availability). Answers come from docs/system-knowledge.md only and are
 * deliberately role-aware, so an offline answer can never widen access.
 */
final class OfflineHelp
{
    /**
     * @return string|null  A grounded answer, or null when the question is not covered.
     */
    public static function answer(string $question, string $role): ?string
    {
        $text = self::normalise($question);

        if ($text === '') {
            return null;
        }

        if (self::matches($text, [
            'what is bantay cordova', 'what is bantay', 'what is this system', 'what is the system',
            'purpose of this system', 'purpose of bantay cordova', 'what is your purpose',
            'what do you do', 'what can you do',
        ])) {
            return 'Bantay Cordova is a community-focused information and monitoring platform for Cordova residents. It provides a news feed, role-based accounts, story management for authorised editors, an admin dashboard and a read-only assistant.';
        }

        if (self::matches($text, [
            'what is an advisory', 'what are advisories', 'purpose of the advisory', 'advisory section',
            'what is an announcement', 'how can i view the latest announcements',
        ])) {
            return 'Advisories and announcements are published news posts, not separate modules. Advisories are notices or public-safety posts; announcements use the announcement category. Published posts appear in the news feed with their category, publication date and summary when available.';
        }

        if (self::matches($text, [
            'admin dashboard', 'who can access the admin dashboard', 'what is the admin dashboard',
        ])) {
            return 'The admin dashboard is available to administrators only. It shows aggregate counts for users, stories, failed sign-ins and AI usage, and links to admin tools for users, tokens, audit, security, settings, AI usage and content recovery.';
        }

        if (self::matches($text, [
            'security features', 'what security', 'system security', 'how is data protected',
            'privacy features', 'accountability features',
        ])) {
            return 'Bantay Cordova uses Sanctum authentication, role-based access and policies, hashed passwords, sign-in rate limiting and lockout, CSRF protection, hashed one-time API tokens, a hash-chained audit log, input validation, HTML sanitising and PII masking in chat.';
        }

        if (self::matches($text, [
            'forgot password', 'reset password', 'reset my password', 'reset the password',
            'password reset', 'change password', 'change my password', 'locked out', 'lockout',
        ])) {
            return 'Use Forgot password on the sign-in screen; the emailed link opens the reset form where you can choose a new password. After five failed sign-in attempts an account is locked until an administrator unlocks it.';
        }

        if (self::matches($text, ['register', 'registration', 'editor request', 'become an editor', 'roles', 'what is a reader', 'what is an editor'])) {
            return 'Self-registration always creates a reader account. An editor request is only recorded; an administrator must approve it by changing the role in Admin → Users. Editors manage their own stories and admins manage users, tokens, settings and the audit log.';
        }

        if (self::matches($text, ['draft', 'scheduled', 'published', 'visibility', 'residents only', 'story status', 'status of a story'])) {
            return 'A draft is unpublished, a scheduled story publishes automatically at its date and time, and a published story is live according to its visibility. Public stories are visible to everyone; Residents stories require sign-in.';
        }

        if (self::matches($text, ['daily budget', 'token budget', 'ai limit', 'rate limit', 'message limit'])) {
            return 'The assistant has a per-minute request limit and a daily token budget (reader 20,000; editor 40,000; admin 60,000, unless an administrator sets an override). When the budget is spent, replies resume after the daily reset.';
        }

        // The system has no incident or emergency module. Answer honestly from
        // the knowledge base instead of inventing a workflow.
        if (self::matches($text, [
            'report an incident', 'report incident', 'incident report', 'incident reporting',
            'emergency', 'monitor incidents', 'incident monitoring',
        ])) {
            return 'Bantay Cordova currently publishes local news, announcements and advisories; it does not include an incident-reporting, monitoring or emergency-dispatch module. For an emergency or to report an incident, contact the barangay hall.';
        }

        if (self::matches($text, ['system records', 'data management', 'reports and records'])) {
            return 'Bantay Cordova stores published/revisioned stories and an audit log of significant actions (only admins can view all entries). There is no separate field-report or case-record module; ask the site administrator about data questions, or contact the barangay hall.';
        }

        if ($role === 'admin') {
            if (self::matches($text, ['audit', 'hash chain', 'verify chain', 'tamper', 'integrity'])) {
                return 'Significant actions are written to an append-only audit log with a hash chain. Use the Verify chain button in Admin → Audit, or run php artisan audit:verify, to check that no entry was changed.';
            }

            if (self::matches($text, ['token', 'ability', 'abilities', 'sanctum'])) {
                return 'Personal access tokens use least-privilege abilities: news:read for read-only integrations and news:write only where publishing is needed. The plaintext token is shown once; only its hash is stored, and it can be revoked at any time.';
            }
        }

        return null;
    }

    private static function normalise(string $question): string
    {
        $lower = mb_strtolower($question);
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lower) ?? $lower;

        return trim((string) preg_replace('/\s+/u', ' ', $clean));
    }

    /** @param list<string> $phrases */
    private static function matches(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
