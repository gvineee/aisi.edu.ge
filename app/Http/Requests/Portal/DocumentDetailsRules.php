<?php

namespace App\Http\Requests\Portal;

use App\Domain\Documents\Models\Document;
use Illuminate\Validation\Rule;

/**
 * Metadata fields shared by the create and edit requests for documents.
 * Tags arrive as one comma-separated string and are normalised by
 * DocumentDetailsRules::tags().
 */
final class DocumentDetailsRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_date' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:document_date'],
            'tags' => ['nullable', 'string', 'max:500'],
            'visibility' => ['nullable', Rule::in([Document::VISIBILITY_WORKSPACE, Document::VISIBILITY_RESTRICTED])],
        ];
    }

    /**
     * @return array<int, string>|null
     */
    public static function tags(?string $raw): ?array
    {
        $tags = collect(explode(',', (string) $raw))
            ->map(fn (string $tag) => mb_substr(trim($tag), 0, 40))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        return $tags === [] ? null : $tags;
    }
}
