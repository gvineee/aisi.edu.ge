<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
                'logoUrl' => $brand?->logo_path ? Storage::disk('public')->url($brand->logo_path) : null,
                'colors' => $brand === null ? [] : $brand->colors,
                'locale' => $tenant->locale,
                'contact' => [
                    'email' => $brand?->contact_email,
                    'phone' => $brand?->contact_phone,
                    'address' => $brand?->contact_address,
                ],
            ];
        }

        return [
            ...parent::share($request),
            'name' => $appName,
            'brand' => $brandProp,
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
