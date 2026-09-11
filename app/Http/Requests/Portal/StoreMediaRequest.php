<?php

namespace App\Http\Requests\Portal;

use App\Domain\Content\MediaFileStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['png', 'jpg', 'jpeg', 'webp', 'pdf'])->max(MediaFileStorage::MAX_BYTES / 1024),
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
