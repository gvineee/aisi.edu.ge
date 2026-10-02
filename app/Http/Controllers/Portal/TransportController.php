<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\Student;
use App\Domain\Operations\Actions\ManageTransport;
use App\Domain\Operations\Models\StudentTransportAssignment;
use App\Domain\Operations\Models\TransportRoute;
use App\Domain\Operations\Models\TransportRun;
use App\Domain\Operations\Models\TransportStop;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesOperationsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ტრანსპორტი" (CLAUDE-PLATFORM-MODULES §11). Planned routes and the day's
 * reported status only — no live location without a GPS provider. A
 * guardian sees only their own child's route.
 */
class TransportController extends Controller
{
    use ResolvesOperationsAccess;

    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $isManagement = $this->isManagement($tenant->id, $user);
        $children = $this->ownChildren($tenant->id, $user);
        abort_unless($isManagement || $children->isNotEmpty(), 403);

        $today = Carbon::now('Asia/Tbilisi')->toDateString();
        $runs = TransportRun::query()->where('tenant_id', $tenant->id)->whereDate('run_date', $today)->get()->keyBy('transport_route_id');

        $routeShape = fn (TransportRoute $route) => [
            'id' => $route->id,
            'name' => $route->name,
            'direction' => $route->direction,
            'vehicleLabel' => $route->vehicle_label,
            'todayStatus' => $runs->get($route->id)->status ?? 'planned',
            'todayNote' => $runs->get($route->id)?->note,
            'stops' => $route->stops->sortBy('sort_order')->map(fn (TransportStop $stop) => [
                'id' => $stop->id, 'name' => $stop->name, 'plannedTime' => substr($stop->planned_time, 0, 5),
            ])->values()->all(),
        ];

        $guardianView = $children->map(function (Student $child) use ($tenant) {
            return [
                'id' => $child->id,
                'name' => $child->fullName(),
                'assignments' => StudentTransportAssignment::query()
                    ->where('tenant_id', $tenant->id)->where('student_id', $child->id)->where('is_active', true)
                    ->with('route', 'stop')->get()
                    ->map(fn (StudentTransportAssignment $a) => [
                        'routeId' => $a->transport_route_id,
                        'routeName' => $a->route->name,
                        'stopName' => $a->stop->name,
                        'plannedTime' => substr($a->stop->planned_time, 0, 5),
                    ])->values()->all(),
            ];
        })->values();

        $childRouteIds = $guardianView->flatMap(fn (array $c) => array_column($c['assignments'], 'routeId'))->unique();

        $routes = TransportRoute::query()
            ->where('tenant_id', $tenant->id)
            ->when(! $isManagement, fn ($query) => $query->whereIn('id', $childRouteIds))
            ->with('stops')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/transport/index', [
            'isManagement' => $isManagement,
            'routes' => $routes->map($routeShape)->values(),
            'children' => $guardianView,
            'students' => $isManagement
                ? Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('last_name')->get()->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName()])->values()
                : [],
            'assignedCounts' => $isManagement
                ? StudentTransportAssignment::query()->where('tenant_id', $tenant->id)->where('is_active', true)->selectRaw('transport_route_id, count(*) as aggregate')->groupBy('transport_route_id')->pluck('aggregate', 'transport_route_id')
                : [],
        ]);
    }

    public function storeRoute(Request $request, CurrentTenant $currentTenant, ManageTransport $transport): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'in:morning,afternoon'],
            'vehicle_label' => ['nullable', 'string', 'max:100'],
        ]);

        $transport->createRoute($tenant->id, $request->user(), $data['name'], $data['direction'], $data['vehicle_label'] ?? null);

        return back();
    }

    public function storeStop(Request $request, CurrentTenant $currentTenant, TransportRoute $transportRoute, ManageTransport $transport): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($transportRoute->tenant_id === $tenant->id, 404);
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'planned_time' => ['required', 'date_format:H:i'],
        ]);

        $transport->addStop($transportRoute, $data['name'], $data['planned_time']);

        return back();
    }

    public function assign(Request $request, CurrentTenant $currentTenant, TransportRoute $transportRoute, ManageTransport $transport): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($transportRoute->tenant_id === $tenant->id, 404);
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $request->validate(['student_id' => ['required', 'integer'], 'transport_stop_id' => ['required', 'integer']]);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('student_id'))->firstOrFail();
        $stop = TransportStop::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('transport_stop_id'))->firstOrFail();

        $transport->assignStudent($transportRoute, $stop, $student, $request->user());

        return back();
    }

    public function reportStatus(Request $request, CurrentTenant $currentTenant, TransportRoute $transportRoute, ManageTransport $transport): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($transportRoute->tenant_id === $tenant->id, 404);
        abort_unless($this->isManagement($tenant->id, $request->user()), 403);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', TransportRun::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transport->reportStatus($transportRoute, Carbon::now('Asia/Tbilisi'), $data['status'], $data['note'] ?? null, $request->user());

        return back();
    }
}
