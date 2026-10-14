<?php

namespace App\Domain\Content\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One old-site URL that now permanently lives elsewhere on the new site.
 * Matching is done on {@see self::normalizePath()} so the many spellings the
 * old WordPress site produced (percent-encoded upper/lower case, trailing
 * slash or not, WPML /en/ and /ru/ copies of the same Georgian post) all
 * resolve to one row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source_path
 * @property string $source_hash
 * @property string $target_path
 * @property int $status_code
 * @property string $origin
 * @property int $hits
 * @property CarbonImmutable|null $last_hit_at
 */
#[Fillable(['source_path', 'source_hash', 'target_path', 'status_code', 'origin'])]
class LegacyRedirect extends Model
{
    use BelongsToTenant;

    public const ORIGIN_IMPORT = 'import';

    public const ORIGIN_MANUAL = 'manual';

    /** Old WPML language copies; the content itself only ever existed in Georgian. */
    private const LANGUAGE_PREFIXES = ['en', 'ru'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * Accepts a full URL or a bare path; returns a decoded, lower-cased path
     * with no language prefix, query string or trailing slash.
     */
    public static function normalizePath(string $urlOrPath): string
    {
        $path = parse_url($urlOrPath, PHP_URL_PATH);
        $path = mb_strtolower(rawurldecode(is_string($path) ? $path : '/'));
        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));

        if ($segments !== [] && in_array($segments[0], self::LANGUAGE_PREFIXES, true)) {
            array_shift($segments);
        }

        return '/'.implode('/', $segments);
    }

    public static function hashPath(string $normalizedPath): string
    {
        return hash('sha256', $normalizedPath);
    }
}
