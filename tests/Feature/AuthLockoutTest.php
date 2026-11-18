<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_bad_logins_lock_the_account(): void
    {
        $user = User::create([
            'name' => 'Test Reader',
            'email' => 'reader@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'reader',
            'is_active' => true,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $user->refresh();

        $this->assertSame(5, $user->failed_logins);
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());

        // Even correct credentials are refused while the account is locked.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(429);
    }

    public function test_self_registration_cannot_grant_the_editor_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Applicant',
            'email' => 'applicant@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'requested_role' => 'editor',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'reader');

        $this->assertDatabaseHas('users', [
            'email' => 'applicant@example.com',
            'role' => 'reader',
            'requested_role' => 'editor',
        ]);
    }
}
