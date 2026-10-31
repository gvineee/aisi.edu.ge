<?php

namespace App\Http\Controllers\Public;

use App\Domain\Library\Models\LibraryResource;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LibraryCatalogController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $query = trim((string) $request->string('q'));

        $resources = LibraryResource::query()
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function ($builder) use ($query) {
                // Plain LIKE (not ILIKE) so this works identically on
                // PostgreSQL and the sqlite connection tests run against.
                // Georgian (Mkhedruli) script has no letter case, so this
                // doesn't lose case-insensitivity for the primary use case.
                $builder->where(function ($inner) use ($query) {
                    $inner->where('title', 'like', "%{$query}%")
                        ->orWhere('author', 'like', "%{$query}%")
                        ->orWhere('subject', 'like', "%{$query}%");
                });
            })
            ->orderBy('grade')
            ->orderBy('title')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (LibraryResource $resource) => [
                'id' => $resource->id,
                'title' => $resource->title,
                'author' => $resource->author,
                'grade' => $resource->grade,
                'subject' => $resource->subject,
                'isRequired' => $resource->is_required,
                'accessScope' => $resource->access_scope,
                // A link only for a digital item whose distribution right is
                // confirmed; catalog and loan entries never promise a download.
                'externalUrl' => $resource->access_scope === LibraryResource::SCOPE_DIGITAL ? $resource->external_url : null,
            ]);

        return Inertia::render('public/library-index', [
            'resources' => $resources,
            'query' => $query,
        ]);
    }
}
