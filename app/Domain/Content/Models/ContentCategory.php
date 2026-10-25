<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site navigation category (სკოლის შესახებ, მიღება, …). `hub_path` is
 * the public page that lists its items; an archive category has none and
 * is never shown publicly.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $slug
 * @property string $locale
 * @property string $name
 * @property string|null $hub_path
 * @property int $position
 * @property bool $is_public
 */
#[Fillable(['parent_id', 'slug', 'locale', 'name', 'hub_path', 'position', 'is_public'])]
class ContentCategory extends Model
{
    use BelongsToTenant;

    /**
     * The categories of the school site, in menu order. Names follow the
     * agreed navigation; archive-review is internal only.
     *
     * @var list<array{slug: string, name: string, hub_path: string|null, is_public: bool}>
     */
    public const DEFAULTS = [
        ['slug' => 'about', 'name' => 'სკოლის შესახებ', 'hub_path' => '/about', 'is_public' => true],
        ['slug' => 'admissions', 'name' => 'მიღება', 'hub_path' => '/admissions', 'is_public' => true],
        ['slug' => 'academics', 'name' => 'სასწავლო პროცესი', 'hub_path' => '/learning', 'is_public' => true],
        ['slug' => 'team', 'name' => 'ჩვენი გუნდი', 'hub_path' => '/teachers', 'is_public' => true],
        ['slug' => 'news', 'name' => 'სიახლეები', 'hub_path' => '/news', 'is_public' => true],
        ['slug' => 'school-life', 'name' => 'პროექტები და ღონისძიებები', 'hub_path' => '/school-life', 'is_public' => true],
        ['slug' => 'documents', 'name' => 'დოკუმენტები', 'hub_path' => '/official-documents', 'is_public' => true],
        ['slug' => 'library', 'name' => 'ბიბლიოთეკა', 'hub_path' => '/library', 'is_public' => true],
        ['slug' => 'contact', 'name' => 'კონტაქტი', 'hub_path' => '/contact', 'is_public' => true],
        ['slug' => 'archive-review', 'name' => 'არქივი / განსახილველი', 'hub_path' => null, 'is_public' => false],
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ContentCategorizable, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(ContentCategorizable::class);
    }

    /**
     * The tenant's categories, creating any default that is missing — never
     * renaming or moving one a person already edited.
     */
    public static function ensureDefaults(int $tenantId, string $locale = 'ka'): void
    {
        foreach (self::DEFAULTS as $position => $default) {
            $exists = self::query()->where('tenant_id', $tenantId)->where('slug', $default['slug'])->where('locale', $locale)->exists();

            if (! $exists) {
                $category = new self($default + ['locale' => $locale, 'position' => $position]);
                $category->tenant_id = $tenantId;
                $category->save();
            }
        }
    }
}
