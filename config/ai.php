<?php

return [

    'daily_budget' => [
        // Default daily token budget; overridable via .env.
        'default' => (int) env('AI_DAILY_TOKEN_BUDGET', 20000),

        'per_role' => [
            'reader' => 20000,
            'editor' => 40000,
            'admin' => 60000,
        ],

        // Alias for callers that prefer config('ai.daily_budget.roles').
        'roles' => [
            'reader' => 20000,
            'editor' => 40000,
            'admin' => 60000,
        ],
    ],

    'roles' => [
        'reader' => 20000,
        'editor' => 40000,
        'admin' => 60000,
    ],

    // Availability: retry only transient provider failures. "attempts" is the
    // number of retries AFTER the initial request (0 disables retries).
    'retry' => [
        'attempts' => max(0, (int) env('AI_RETRY_ATTEMPTS', 1)),
        'delay_ms' => max(0, (int) env('AI_RETRY_DELAY_MS', 350)),
    ],

    // Availability: stop calling the provider briefly after repeated failures.
    'circuit_breaker' => [
        'enabled' => filter_var(env('AI_CIRCUIT_BREAKER', true), FILTER_VALIDATE_BOOL),
        'threshold' => max(1, (int) env('AI_CIRCUIT_THRESHOLD', 3)),
        'ttl_seconds' => max(1, (int) env('AI_CIRCUIT_TTL_SECONDS', 60)),
    ],

    // Integrity: cap stored/returned replies so one response cannot flood the UI.
    'response' => [
        'max_chars' => max(200, (int) env('AI_RESPONSE_MAX_CHARS', 4000)),
    ],

    // Availability: answer common knowledge-base questions locally when the
    // provider is down, instead of showing only a generic error.
    'offline_help' => [
        'enabled' => filter_var(env('AI_OFFLINE_HELP', true), FILTER_VALIDATE_BOOL),
    ],

    // Integrity: system-only assistant enforcement. Questions are checked
    // before answer generation; uncertainty fails closed with the standard
    // refusal. The local check is a fast path for clearly documented topics;
    // anything else is classified contextually by the provider.
    'scope' => [
        'enabled' => filter_var(env('AI_SCOPE_GUARD', true), FILTER_VALIDATE_BOOL),
        'local_check' => filter_var(env('AI_SCOPE_LOCAL_CHECK', true), FILTER_VALIDATE_BOOL),
        'classifier_model' => (string) env('AI_SCOPE_CLASSIFIER_MODEL', ''),
        'max_tokens' => max(16, (int) env('AI_SCOPE_MAX_TOKENS', 64)),
    ],

];
