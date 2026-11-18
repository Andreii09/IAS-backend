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

];
