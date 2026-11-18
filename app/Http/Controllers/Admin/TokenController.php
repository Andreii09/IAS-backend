<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return PersonalAccessToken::query()
            ->with('tokenable')
            ->latest('id')
            ->paginate(max(1, min(100, $request->integer('per_page', 25))))
            ->through(fn (PersonalAccessToken $token): array => $this->present($token));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:news:read,news:write'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        $owner = isset($data['user_id'])
            ? User::query()->findOrFail($data['user_id'])
            : $request->user();

        $abilities = array_values(array_unique($data['abilities'] ?? ['news:read']));
        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : (isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null);

        $token = $owner->createToken($data['name'], $abilities, $expiresAt);

        AuditLogger::record(
            'token.created',
            [
                'entity_type' => 'personal_access_token',
                'entity_id' => (string) $token->accessToken->getKey(),
            ],
            [],
            [
                'user_id' => $owner->getKey(),
                'name' => $token->accessToken->name,
                'abilities' => $abilities,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]
        );

        return response()->json([
            'id' => $token->accessToken->getKey(),
            'name' => $token->accessToken->name,
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'plainTextToken' => $token->plainTextToken,
            'plain_text_token' => $token->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $accessToken = PersonalAccessToken::query()->findOrFail($token);
        $snapshot = $this->present($accessToken);

        $accessToken->delete();

        AuditLogger::record(
            'token.revoked',
            [
                'entity_type' => 'personal_access_token',
                'entity_id' => (string) $snapshot['id'],
            ],
            $snapshot,
            []
        );

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $token): array
    {
        $owner = $token->tokenable;

        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'abilities' => $token->abilities,
            'tokenable_id' => $token->tokenable_id,
            'tokenable_type' => $token->tokenable_type,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'owner' => $owner === null ? null : [
                'id' => $owner->getKey(),
                'name' => $owner->name ?? null,
                'email' => $owner->email ?? null,
            ],
        ];
    }
}
