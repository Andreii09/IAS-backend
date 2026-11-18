<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['key', 'value'];

    /**
     * Read a setting, decoding values written as JSON by set().
     *
     * @param  mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting) {
            return value($default);
        }

        $raw = $setting->value;

        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        // Plain strings stay strings; JSON scalars/arrays are decoded.
        if (json_last_error() === JSON_ERROR_NONE && (! is_string($decoded) || $decoded !== $raw)) {
            return $decoded;
        }

        return $raw;
    }

    /** Persist a scalar or structured value in the key/value settings table. */
    public static function set(string $key, $value): void
    {
        $stored = is_string($value)
            ? $value
            : json_encode($value, JSON_THROW_ON_ERROR);

        static::updateOrCreate(['key' => $key], ['value' => $stored]);
    }
}
