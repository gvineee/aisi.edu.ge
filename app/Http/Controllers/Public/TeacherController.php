<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Teacher;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TeacherController extends Controller
{
    public function index(CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();

        $teachers = Teacher::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Teacher::STATUS_PUBLISHED)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('public/teachers/index', [
            'teachers' => $teachers->map(fn (Teacher $teacher) => [
                'slug' => $teacher->slug,
                'name' => $teacher->name,
                'subject' => $teacher->subject,
                'photoUrl' => $teacher->photoUrl(),
            ])->values(),
        ]);
    }

    public function show(CurrentTenant $currentTenant, string $slug): Response
    {
        $tenant = $currentTenant->get();

        $teacher = Teacher::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('status', Teacher::STATUS_PUBLISHED)
            ->first();

        if (! $teacher) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('public/teachers/show', [
            'teacher' => [
                'name' => $teacher->name,
                'subject' => $teacher->subject,
                'bio' => $teacher->bio,
                'photoUrl' => $teacher->photoUrl(),
            ],
        ]);
    }
}
