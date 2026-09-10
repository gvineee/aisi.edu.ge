<?php

namespace App\Http\Requests\Portal;

use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Timetable\Actions\CheckLessonConflicts;
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
