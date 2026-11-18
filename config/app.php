<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    'name' => env('APP_NAME', 'Bantay Cordova'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // Origin of the React SPA (password reset links, CORS, etc.).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'asset_url' => env('ASSET_URL'),

    'timezone' => 'UTC',

    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => 'file',
    ],

    'providers' => ServiceProvider::defaultProviders()->merge([
        // Additional package providers can be registered here.
    ])->toArray(),

    'aliases' => Facade::defaultAliases()->merge([
        // Additional facade aliases can be registered here.
    ])->toArray(),

];
