<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\News;
use App\Services\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', News::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:announcement,notice,event,public_safety,general'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'cover_image' => ['nullable', 'url', 'max:2048'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:draft,published,scheduled'],
            'visibility' => ['sometimes', 'string', 'in:public,residents'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge([
                'body' => HtmlSanitizer::clean((string) $this->input('body', '')),
            ]);
        }
    }

    /**
     * Validated data with a final sanitizer pass on the body.
     *
     * @return array<string, mixed>
     */
    public function passedData(): array
    {
        $data = $this->validated();

        if (array_key_exists('body', $data)) {
            $data['body'] = HtmlSanitizer::clean((string) $data['body']);
        }

        return $data;
    }
}
