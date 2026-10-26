<?php

namespace App\Http\Middleware;

use App\Domain\Content\PublicLocales;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Routes that render public content in a language (see PublicLocales). */
    private const PUBLIC_ROUTES = ['home', 'page.show', 'news.index', 'news.show', 'locale.home', 'locale.page.show', 'locale.news.index', 'locale.news.show'];

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Controllers report outcomes with `->with('toast', [...])`, a plain
        // session flash; Inertia v3 only delivers its own flash bag to the
        // client (the `flash` router event), so hand the toast over to it.
        if ($request->hasSession() && is_array($toast = $request->session()->get('toast'))) {
            Inertia::flash('toast', $toast);
        }

        $tenant = app(CurrentTenant::class)->get();

        $appName = config('app.name');
        $brandProp = null;

        if ($tenant !== null) {
            $brand = $tenant->brandSetting;
            $displayName = $brand !== null ? $brand->display_name : $tenant->name;
            $appName = $displayName;

            // "brand" is the ONLY source pages should read school name/logo/
            // colors/locale/contact from — never hardcode "aisi" in a
            // component (see CLAUDE.md invariant #8).
            $brandProp = [
                'name' => $displayName,
                'shortName' => $brand?->short_name,
                'logoUrl' => $brand?->logo_path ? $this->versionedAssetUrl($brand->logo_path) : null,
                'heroImageUrl' => $brand?->hero_image_path ? $this->versionedAssetUrl($brand->hero_image_path) : null,
                'colors' => $brand === null ? [] : $brand->colors,
                'locale' => $tenant->locale,
                'contact' => [
                    'email' => $brand?->contact_email,
                    'phone' => $brand?->contact_phone,
                    'address' => $brand?->contact_address,
                ],
            ];
        }

        // Public pages: the language the visitor reads and the switcher.
        $publicLocale = null;

        if ($tenant !== null && in_array((string) $request->route()?->getName(), self::PUBLIC_ROUTES, true)) {
            $locales = app(PublicLocales::class);
            $current = $locales->current($request, $tenant);
            app()->setLocale($current);
            $publicLocale = [
                'current' => $current,
                'default' => $tenant->locale,
                'switcher' => $locales->switcher($request, $tenant),
                'alternates' => $locales->alternates($request, $tenant),
                'nav' => $locales->nav($tenant, $current),
                'homeUrl' => $locales->url($tenant, $current, '/'),
            ];
        }

        $user = $request->user();
        $portalProp = null;

        if ($tenant !== null && $user !== null) {
            $portalProp = app(PortalContext::class)->propsFor($request, $tenant, $user);
        }

        return [
            ...parent::share($request),
            'name' => $appName,
            'brand' => $brandProp,
            'auth' => [
                'user' => $user,
            ],
            'portal' => $portalProp,
            'publicLocale' => $publicLocale,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Appends the file's last-modified timestamp as a query string so that
     * replacing a brand asset (logo/hero) at the same storage path busts
     * both browser caches and any origin-side static-asset cache the
     * hosting environment applies in front of the public disk.
     */
    private function versionedAssetUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        $version = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : null;

        return $version !== null ? "{$url}?v={$version}" : $url;
    }
}
