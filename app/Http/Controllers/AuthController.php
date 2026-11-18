<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            // Self-registration never grants elevated privileges.
            'role' => 'reader',
            'organization' => $data['organization'] ?? null,
            'requested_role' => $data['requested_role'] ?? null,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        AuditLogger::record('auth.register', $user, [], [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'requested_role' => $user->requested_role,
        ]);

        return response()->json(['user' => $user], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $inputEmail = (string) $request->input('email');
        $email = Str::lower($inputEmail);
        $throttleKey = $this->throttleKey($request, $email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            AuditLogger::record('auth.login.failure', null, [], [
                'email' => $email,
                'ip' => $request->ip(),
                'reason' => 'rate_limited',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)])],
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            AuditLogger::record('auth.login.failure', $user, [], [
                'email' => $email,
                'ip' => $request->ip(),
                'reason' => 'locked',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', [
                    'seconds' => max(1, now()->diffInSeconds($user->locked_until)),
                ])],
            ]);
        }

        // A previous lockout has expired; start counting again.
        if ($user && $user->locked_until && $user->locked_until->isPast()) {
            $user->forceFill([
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();
        }

        $remember = $request->boolean('remember');
        $credentials = [
            'email' => $user?->email ?? $inputEmail,
            'password' => (string) $request->input('password'),
        ];

        if (! $user || ! $user->is_active || ! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, 60);

            if ($user) {
                $failedLogins = $user->failed_logins + 1;

                $user->forceFill([
                    'failed_logins' => $failedLogins,
                    'locked_until' => $failedLogins >= self::MAX_LOGIN_ATTEMPTS
                        ? now()->addMinutes(self::LOCKOUT_MINUTES)
                        : null,
                ])->save();
            }

            AuditLogger::record('auth.login.failure', $user, [], [
                'email' => $email,
                'ip' => $request->ip(),
                'reason' => ! $user ? 'unknown_user' : (! $user->is_active ? 'inactive' : 'invalid_credentials'),
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();

        $user->forceFill([
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        AuditLogger::record('auth.login.success', $user, [], [
            'ip' => $request->ip(),
        ]);

        return response()->json(['user' => $user->refresh()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();

            // SPA cookie requests use Sanctum's TransientToken, which has no delete().
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }

            AuditLogger::record('auth.logout', $user);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.password')],
            ]);
        }

        $old = ['must_change_password' => $user->must_change_password];

        $user->forceFill([
            'password' => Hash::make((string) $request->input('password')),
            'must_change_password' => false,
        ])->save();

        // Invalidate every API token; the SPA session remains valid.
        $user->tokens()->delete();

        AuditLogger::record('auth.password.changed', $user, $old, [
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
            'user' => $user->refresh(),
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = Str::lower((string) $request->input('email'));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        AuditLogger::record('auth.password.reset.requested', $user, [], [
            'email' => $email,
            'ip' => $request->ip(),
        ]);

        $status = Password::sendResetLink(['email' => $user?->email ?? $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $old = ['must_change_password' => $user->must_change_password];

                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'failed_logins' => 0,
                    'locked_until' => null,
                ])->save();

                $user->tokens()->delete();

                AuditLogger::record('auth.password.reset.completed', $user, $old, [
                    'must_change_password' => false,
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => __($status)]);
    }

    /** Rate limit key: failed attempts per email address and IP. */
    private function throttleKey(Request $request, string $email): string
    {
        return Str::transliterate($email.'|'.$request->ip());
    }
}
