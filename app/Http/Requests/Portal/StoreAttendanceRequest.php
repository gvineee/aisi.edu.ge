<?php

namespace App\Http\Requests\Portal;

use App\Domain\Learning\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller checks the teacher/lesson relationship explicitly.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'occurred_on' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in([
                AttendanceRecord::STATUS_PRESENT,
                AttendanceRecord::STATUS_LATE,
                AttendanceRecord::STATUS_ABSENT,
                AttendanceRecord::STATUS_EXCUSED,
            ])],
            'records.*.comment' => ['nullable', 'string', 'max:280'],
        ];
    }
}
