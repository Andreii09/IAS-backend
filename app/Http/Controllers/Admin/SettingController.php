<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $settings = $this->allSettings();

        return response()->json($settings + [
            'budget' => [
                'key' => 'ai.daily_budget.override',
                'value' => $settings['ai.daily_budget.override'] ?? null,
                'ai_daily_budget' => $settings['ai.daily_budget.override'] ?? null,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'settings' => ['sometimes', 'array'],
            'key' => ['sometimes', 'string', 'max:191'],
            'value' => ['sometimes', 'nullable'],
            'ai_daily_budget' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $pairs = [];

        if (isset($data['settings']) && is_array($data['settings'])) {
            $pairs = $data['settings'];
        } elseif (isset($data['key'])) {
            $pairs = [$data['key'] => $data['value'] ?? null];
        } elseif (array_key_exists('ai_daily_budget', $data)) {
            $pairs = ['ai.daily_budget.override' => $data['ai_daily_budget']];
        }

        $before = [];
        $after = [];

        foreach ($pairs as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $before[$key] = Setting::get($key);
            Setting::set($key, $value);
            $after[$key] = $value;
        }

        if ($after !== []) {
            AuditLogger::record('settings.updated', 'setting', $before, $after);
        }

        $settings = $this->allSettings();

        return response()->json($settings + [
            'budget' => [
                'key' => 'ai.daily_budget.override',
                'value' => $settings['ai.daily_budget.override'] ?? null,
                'ai_daily_budget' => $settings['ai.daily_budget.override'] ?? null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function allSettings(): array
    {
        return Setting::query()
            ->orderBy('key')
            ->get(['key', 'value'])
            ->mapWithKeys(fn ($setting): array => [(string) $setting->key => $setting->value])
            ->all();
    }
}
