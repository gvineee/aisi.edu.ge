<?php

namespace App\Domain\Calendar;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Student;
use App\Domain\Calendar\Models\SchoolEvent;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timetable\Models\NonTeachingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What one person sees in the school calendar for a date range, decided
 * from their active role and server-side links only:
 *
 * - staff see every event;
 * - a pupil sees events for everyone and for their own class;
 * - a guardian, those for everyone and for the classes of the children an
 *   active link lets them follow academically.
 *
 * Holidays and vacations (non_teaching_periods) are on everyone's calendar.
 */
class CalendarFeed
{
    /**
     * @return list<array{key: string, id: int|null, kind: string, title: string, description: string|null, category: string, startsOn: string, endsOn: string, startsAt: string|null, endsAt: string|null, location: string|null, audience: string, classes: list<array{id: int, name: string}>}>
     */
    public function for(int $tenantId, string $role, int $userId, Carbon $from, Carbon $to): array
    {
        $events = SchoolEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->with('classes:id,name')
            ->orderBy('starts_on')->orderBy('starts_at')->orderBy('id');

        $this->limitToAudience($events, $tenantId, $role, $userId);

        $entries = $events->get()->map(fn (SchoolEvent $event) => [
            'key' => "event-{$event->id}",
            'id' => $event->id,
            'kind' => 'event',
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'startsOn' => $event->starts_on->toDateString(),
            'endsOn' => $event->ends_on->toDateString(),
            'startsAt' => $event->starts_at,
            'endsAt' => $event->ends_at,
            'location' => $event->location,
            'audience' => $event->audience,
            'classes' => array_values($event->classes->sortBy('name', SORT_NATURAL)->map(fn (SchoolClass $class) => ['id' => $class->id, 'name' => $class->name])->all()),
        ]);

        $breaks = NonTeachingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->map(fn (NonTeachingPeriod $break) => [
                'key' => "break-{$break->id}",
                'id' => null,
                'kind' => 'holiday',
                'title' => $break->label,
                'description' => null,
                'category' => 'holiday',
                'startsOn' => $break->starts_on->toDateString(),
                'endsOn' => $break->ends_on->toDateString(),
                'startsAt' => null,
                'endsAt' => null,
                'location' => null,
                'audience' => SchoolEvent::AUDIENCE_EVERYONE,
                'classes' => [],
            ]);

        return array_values($breaks->concat($entries)
            ->sortBy(fn (array $entry) => $entry['startsOn'].($entry['startsAt'] ?? '00:00').$entry['key'])
            ->all());
    }

    /**
     * @param  Builder<SchoolEvent>  $events
     */
    private function limitToAudience(Builder $events, int $tenantId, string $role, int $userId): void
    {
        if (! in_array($role, [TenantMembership::ROLE_GUARDIAN, TenantMembership::ROLE_STUDENT], true)) {
            return; // staff
        }

        $classIds = $role === TenantMembership::ROLE_STUDENT
            ? Student::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('is_active', true)->pluck('school_class_id')
            : GuardianLink::query()
                ->where('guardian_links.tenant_id', $tenantId)
                ->where('guardian_links.user_id', $userId)
                ->where('guardian_links.is_active', true)
                ->where('guardian_links.can_view_academic', true)
                ->join('students', 'students.id', '=', 'guardian_links.student_id')
                ->where('students.tenant_id', $tenantId)
                ->where('students.is_active', true)
                ->pluck('students.school_class_id');

        $classIds = $classIds->filter()->unique()->values()->all();

        $events->where(fn (Builder $query) => $query
            ->where('audience', SchoolEvent::AUDIENCE_EVERYONE)
            ->orWhere(fn (Builder $query) => $query
                ->where('audience', SchoolEvent::AUDIENCE_CLASSES)
                ->whereHas('classes', fn (Builder $query) => $query->whereIn('school_classes.id', $classIds === [] ? [0] : $classIds))));
    }
}
