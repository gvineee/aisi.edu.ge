<?php

namespace App\Http\Requests\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Actions\CheckLessonConflicts;
use App\Domain\Timetable\Models\Room;
use App\Domain\Timetable\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller checks the academic_manager/admin role explicitly.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer'],
            'school_class_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'online_url' => ['nullable', 'url'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenant = app(CurrentTenant::class)->get();
            $data = $validator->getData();

            // Every one of these ids is client-supplied and must not be
            // trusted as belonging to the current tenant (CLAUDE.md
            // invariant #2: relations/route binding must re-check tenant
            // scope) — without this, an academic_manager could reference
            // another school's class/subject/year/room by guessing its id,
            // creating a lesson row that leaks cross-tenant data through
            // its own relations. Each model already carries BelongsToTenant,
            // but that global scope is a convenience, never the sole
            // isolation guarantee for a client-supplied id (see that
            // trait's own docblock) — hence the explicit tenant_id here too.
            if (! AcademicYear::query()->where('tenant_id', $tenant->id)->whereKey($data['academic_year_id'])->exists()) {
                $validator->errors()->add('academic_year_id', 'არასწორი მონაცემი — სცადეთ თავიდან.');
            }

            if (! SchoolClass::query()->where('tenant_id', $tenant->id)->whereKey($data['school_class_id'])->exists()) {
                $validator->errors()->add('school_class_id', 'არასწორი მონაცემი — სცადეთ თავიდან.');
            }

            if (! Subject::query()->where('tenant_id', $tenant->id)->whereKey($data['subject_id'])->exists()) {
                $validator->errors()->add('subject_id', 'არასწორი მონაცემი — სცადეთ თავიდან.');
            }

            if (! empty($data['room_id']) && ! Room::query()->where('tenant_id', $tenant->id)->whereKey($data['room_id'])->exists()) {
                $validator->errors()->add('room_id', 'არასწორი მონაცემი — სცადეთ თავიდან.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $conflicts = app(CheckLessonConflicts::class)->find(
                tenantId: $tenant->id,
                dayOfWeek: (int) $data['day_of_week'],
                startsAt: $data['starts_at'].':00',
                endsAt: $data['ends_at'].':00',
                teacherId: (int) $data['teacher_id'],
                roomId: $data['room_id'] ?? null,
                schoolClassId: (int) $data['school_class_id'],
                // No update/edit endpoint exists yet — when one is added,
                // pass the lesson being edited here so it excludes itself.
                excludingLessonId: null,
            );

            if ($conflicts->isNotEmpty()) {
                $validator->errors()->add(
                    'starts_at',
                    'ამ დროს უკვე დაკავებულია მასწავლებელი, ოთახი ან კლასი.',
                );
            }
        });
    }
}
