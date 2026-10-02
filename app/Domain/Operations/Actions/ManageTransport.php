<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Academics\Models\Student;
use App\Domain\Governance\AuditLogger;
use App\Domain\Operations\Models\StudentTransportAssignment;
use App\Domain\Operations\Models\TransportRoute;
use App\Domain\Operations\Models\TransportRun;
use App\Domain\Operations\Models\TransportStop;
use App\Domain\Operations\Models\VehicleEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Planned routes, stops, student assignments and the day's run status.
 * There is no GPS provider, so nothing here claims a live location — only
 * the status staff report (CLAUDE-PLATFORM-MODULES §11).
 */
class ManageTransport
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function createRoute(int $tenantId, User $actor, string $name, string $direction, ?string $vehicleLabel): TransportRoute
    {
        if (! in_array($direction, ['morning', 'afternoon'], true)) {
            throw ValidationException::withMessages(['direction' => 'მიმართულება არასწორია.']);
        }

        $route = new TransportRoute(['name' => $name, 'direction' => $direction, 'vehicle_label' => $vehicleLabel, 'is_active' => true]);
        $route->tenant_id = $tenantId;
        $route->save();

        $this->auditLogger->record($tenantId, 'transport_route.created', $route, $actor->id);

        return $route;
    }

    public function addStop(TransportRoute $route, string $name, string $plannedTime): TransportStop
    {
        $stop = new TransportStop([
            'transport_route_id' => $route->id,
            'name' => $name,
            'planned_time' => $plannedTime,
            'sort_order' => (int) TransportStop::query()->where('transport_route_id', $route->id)->max('sort_order') + 1,
        ]);
        $stop->tenant_id = $route->tenant_id;
        $stop->save();

        return $stop;
    }

    public function assignStudent(TransportRoute $route, TransportStop $stop, Student $student, User $actor): StudentTransportAssignment
    {
        if ($stop->transport_route_id !== $route->id || $student->tenant_id !== $route->tenant_id) {
            throw ValidationException::withMessages(['transport_stop_id' => 'გაჩერება ამ მარშრუტს არ ეკუთვნის.']);
        }

        $assignment = StudentTransportAssignment::query()
            ->where('student_id', $student->id)
            ->where('transport_route_id', $route->id)
            ->first() ?? new StudentTransportAssignment(['student_id' => $student->id, 'transport_route_id' => $route->id]);

        $assignment->tenant_id = $route->tenant_id;
        $assignment->transport_stop_id = $stop->id;
        $assignment->is_active = true;
        $assignment->save();

        $this->auditLogger->record($route->tenant_id, 'transport.student_assigned', $assignment, $actor->id, [
            'student_id' => $student->id,
            'route_id' => $route->id,
        ]);

        return $assignment;
    }

    public function reportStatus(TransportRoute $route, Carbon $date, string $status, ?string $note, User $actor): TransportRun
    {
        if (! in_array($status, TransportRun::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'სტატუსი არასწორია.']);
        }

        return DB::transaction(function () use ($route, $date, $status, $note, $actor) {
            $run = TransportRun::query()
                ->where('transport_route_id', $route->id)
                ->whereDate('run_date', $date->toDateString())
                ->lockForUpdate()
                ->first() ?? new TransportRun(['transport_route_id' => $route->id, 'run_date' => $date->toDateString()]);

            $run->tenant_id = $route->tenant_id;
            $run->status = $status;
            $run->note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $run->updated_by = $actor->id;
            $run->save();

            $event = new VehicleEvent(['transport_run_id' => $run->id, 'status' => $status, 'note' => $run->note, 'recorded_by' => $actor->id]);
            $event->tenant_id = $route->tenant_id;
            $event->save();

            return $run;
        });
    }
}
