<?php

return [

    // Read through config() (never env() directly) so config:cache is safe.
    'name' => env('ADMIN_NAME', ''),

    'email' => env('ADMIN_EMAIL', ''),

    'password' => env('ADMIN_PASSWORD', ''),

];
