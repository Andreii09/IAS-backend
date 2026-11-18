<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ApiInfoController extends Controller
{
    /**
     * Lightweight health probe used by the frontend and uptime checks.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ]);
    }
}
