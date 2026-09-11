<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both create and update — the controller re-checks the actor's
 * role either way (authorize() below only says "the request shape is
 * fine", not "this user may do this").
 *
 * `blocks` arrives as a JSON string from the editor's textarea (typed
 * content blocks, never raw HTML/JS per CLAUDE.md's design-implementation
 * rule); prepareForValidation decodes it into `blocks_decoded` so we can
 * validate its shape structurally — every element must at least be an
 * object with a string `type`, the same field resources/js/pages/public/
 * page.tsx switches on to render.
 */
class SavePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $decoded = json_decode((string) $this->input('blocks', ''), true);

        $this->merge([
            'blocks_decoded' => is_array($decoded) ? $decoded : null,
        ]);
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
            'blocks' => ['required', 'string'],
            // 'array' alone (no 'required') so an empty blocks array — a
            // legitimate brand-new page with no content yet — validates;
            // Laravel's 'required' rule treats an empty array as absent,
            // which would wrongly reject that case. Invalid JSON decodes
            // to null in prepareForValidation(), which 'array' still
            // correctly rejects since the key is always present (merged).
            'blocks_decoded' => ['array'],
            'blocks_decoded.*.type' => ['required', 'string'],
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
            'blocks_decoded.required' => 'ბლოკების JSON არასწორია — გადაამოწმეთ ფორმატი.',
            'blocks_decoded.array' => 'ბლოკების JSON არასწორია — გადაამოწმეთ ფორმატი.',
            'slug.regex' => 'Slug უნდა შეიცავდეს მხოლოდ პატარა ლათინურ ასოებს, ციფრებს და დეფისს.',
        ];
    }

    /**
     * Reads from input(), not validated() — validated() only returns keys
     * that have their own explicit rule, and since only `type` has a rule
     * per block (blocks are heterogeneous: a hero block's `heading`/`body`
     * has nothing in common with a programs block's `items`), validated()
     * would silently strip every other field from every block. The
     * `array`/`*.type` rules above still ran against the full structure
     * before this is called — this only changes which copy of the (already
     * validated) data we read back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validatedBlocks(): array
    {
        $blocks = $this->input('blocks_decoded');

        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = is_array($blocks) ? $blocks : [];

        return $blocks;
    }

    /**
     * @return array{slug: string, locale: string, title: string, excerpt: string|null, blocks: array<int, array<string, mixed>>, seo_title: string|null, seo_description: string|null}
     */
    public function pageAttributes(): array
    {
        return [
            'slug' => $this->string('slug')->toString(),
            'locale' => $this->string('locale')->toString(),
            'title' => $this->string('title')->toString(),
            'excerpt' => $this->string('excerpt')->toString() ?: null,
            'blocks' => $this->validatedBlocks(),
            'seo_title' => $this->string('seo_title')->toString() ?: null,
            'seo_description' => $this->string('seo_description')->toString() ?: null,
        ];
    }
}
