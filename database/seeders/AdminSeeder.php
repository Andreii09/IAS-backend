<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');

        if (blank($email)) {
            abort(500, 'ADMIN_EMAIL is not configured. Add it to .env before seeding.');
        }

        $configuredName = trim((string) config('admin.name'));
        $configuredPassword = (string) config('admin.password');

        $admin = User::where('email', $email)->first();

        if ($admin) {
            // Never reset an existing admin's (possibly changed) password.
            $admin->forceFill([
                'name' => $configuredName !== '' ? $configuredName : $admin->name,
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ])->save();

            AuditLogger::record('admin.seeded', $admin, [], [
                'email' => $admin->email,
                'created' => false,
            ]);

            $this->command?->info("Admin account {$admin->email} already exists; password left unchanged.");

            return;
        }

        $generated = $configuredPassword === '';
        $plainPassword = $generated ? Str::password(20) : $configuredPassword;

        $admin = User::create([
            'name' => $configuredName !== '' ? $configuredName : 'Administrator',
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'role' => 'admin',
            'organization' => null,
            'is_active' => true,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);

        if ($generated) {
            $this->command?->warn("Generated one-time admin password (change after first login): {$plainPassword}");
        }

        AuditLogger::record('admin.seeded', $admin, [], [
            'email' => $admin->email,
            'created' => true,
            'password_generated' => $generated,
        ]);

        $this->command?->info("Admin account {$admin->email} created.");
    }
}
