<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both create and update, mirroring SavePageRequest. `body` is
 * plain text (rendered with whitespace-pre-line, never dangerouslySet
 * innerHTML — see resources/js/pages/public/news-show.tsx) so there is no
 * HTML/script injection surface to worry about here.
 */
class SavePostRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'locale' => ['required', 'string', 'max:5'],
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'cover_image_path' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug უნდა შეიცავდეს მხოლოდ პატარა ლათინურ ასოებს, ციფრებს და დეფისს.',
        ];
    }

    /**
     * @return array{slug: string, locale: string, title: string, excerpt: string|null, body: string, cover_image_path: string|null, seo_title: string|null, seo_description: string|null}
     */
    public function postAttributes(): array
    {
        return [
            'slug' => $this->string('slug')->toString(),
            'locale' => $this->string('locale')->toString(),
            'title' => $this->string('title')->toString(),
            'excerpt' => $this->string('excerpt')->toString() ?: null,
            'body' => $this->string('body')->toString(),
            'cover_image_path' => $this->string('cover_image_path')->toString() ?: null,
            'seo_title' => $this->string('seo_title')->toString() ?: null,
            'seo_description' => $this->string('seo_description')->toString() ?: null,
        ];
    }
}
