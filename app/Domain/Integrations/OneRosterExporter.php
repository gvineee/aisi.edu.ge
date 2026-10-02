<?php

namespace App\Domain\Integrations;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\Student;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds a OneRoster 1.2-style CSV bulk export (orgs, academicSessions,
 * courses, classes, users, roles, enrollments + manifest) for one tenant
 * (CLAUDE-PLATFORM-MODULES §6/§15). This is an export adapter with
 * internal consistency validation only — it is not a certified OneRoster
 * implementation and does not claim to be.
 *
 * sourcedIds are stable for the life of a record: "<tenant slug>-<type>-<id>".
 * Data is minimised: no national id, no phone numbers, and guardian
 * contact details are not exported — only the link to their child.
 */
class OneRosterExporter
{
    /**
     * @return array{files: array<string, array<int, array<int, string>>>, issues: array<int, string>, counts: array<string, int>}
     */
    public function build(Tenant $tenant): array
    {
        $now = Carbon::now()->toIso8601ZuluString();
        $id = fn (string $type, int $key) => "{$tenant->slug}-{$type}-{$key}";
        $orgId = "{$tenant->slug}-org";
        $issues = [];

        $years = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderBy('starts_on')->get();
        $teacherAssignments = TeacherAssignment::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
            ->with(['schoolClass' => fn ($query) => $query->withoutGlobalScope('tenant')])->get()
            ->filter(fn (TeacherAssignment $ta) => $ta->schoolClass !== null);
        $students = Student::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('is_active', true)->get();

        $files = [];
        $files['orgs.csv'] = [['sourcedId', 'status', 'dateLastModified', 'name', 'type', 'identifier', 'parentSourcedId'], [$orgId, 'active', $now, $tenant->name, 'school', '', '']];

        $files['academicSessions.csv'] = [['sourcedId', 'status', 'dateLastModified', 'title', 'type', 'startDate', 'endDate', 'parentSourcedId', 'schoolYear']];
        foreach ($years as $year) {
            $files['academicSessions.csv'][] = [$id('session', $year->id), 'active', $now, $year->name, 'schoolYear', $year->starts_on->toDateString(), $year->ends_on->toDateString(), '', $year->ends_on->format('Y')];
        }

        // One course per (academic year, subject label).
        $courses = [];
        $files['courses.csv'] = [['sourcedId', 'status', 'dateLastModified', 'schoolYearSourcedId', 'title', 'courseCode', 'grades', 'orgSourcedId', 'subjects', 'subjectCodes']];
        $files['classes.csv'] = [['sourcedId', 'status', 'dateLastModified', 'title', 'grades', 'courseSourcedId', 'classCode', 'classType', 'location', 'schoolSourcedId', 'termSourcedIds', 'subjects', 'subjectCodes', 'periods']];

        foreach ($teacherAssignments as $ta) {
            $subject = $ta->subject ?: 'საგანი';
            $courseKey = $ta->schoolClass->academic_year_id.'|'.$subject;

            if (! isset($courses[$courseKey])) {
                $courses[$courseKey] = "{$tenant->slug}-course-".substr(hash('sha256', $courseKey), 0, 12);
                $files['courses.csv'][] = [$courses[$courseKey], 'active', $now, $id('session', $ta->schoolClass->academic_year_id), $subject, '', '', $orgId, $subject, ''];
            }

            $files['classes.csv'][] = [$id('class', $ta->id), 'active', $now, "{$ta->schoolClass->name} · {$subject}", '', $courses[$courseKey], '', 'scheduled', '', $orgId, $id('session', $ta->schoolClass->academic_year_id), $subject, '', ''];
        }

        $files['users.csv'] = [['sourcedId', 'status', 'dateLastModified', 'enabledUser', 'username', 'userIds', 'givenName', 'familyName', 'middleName', 'identifier', 'email', 'sms', 'phone', 'agentSourcedIds', 'grades', 'password', 'userMasterIdentifier', 'resourceSourcedIds', 'preferredGivenName', 'preferredMiddleName', 'preferredFamilyName', 'primaryOrgSourcedId', 'pronouns']];
        $files['roles.csv'] = [['sourcedId', 'status', 'dateLastModified', 'userSourcedId', 'roleType', 'role', 'orgSourcedId', 'userProfileSourcedId']];
        $files['enrollments.csv'] = [['sourcedId', 'status', 'dateLastModified', 'classSourcedId', 'schoolSourcedId', 'userSourcedId', 'role', 'primary', 'beginDate', 'endDate']];

        $guardianLinks = GuardianLink::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('is_active', true)->get();

        foreach ($students as $student) {
            $agents = $guardianLinks->where('student_id', $student->id)->map(fn (GuardianLink $l) => $id('user', $l->user_id))->implode(',');
            $files['users.csv'][] = [$id('student', $student->id), 'active', $now, 'true', '', '', $student->first_name, $student->last_name, '', '', '', '', '', $agents, '', '', '', '', '', '', '', $orgId, ''];
            $files['roles.csv'][] = [$id('role-student', $student->id), 'active', $now, $id('student', $student->id), 'primary', 'student', $orgId, ''];

            if ($student->school_class_id === null) {
                $issues[] = "student {$student->id}: no class — no enrollment exported";
            }
        }

        $teacherIds = $teacherAssignments->pluck('user_id')->unique();
        foreach (User::query()->whereIn('id', $teacherIds)->get() as $teacher) {
            [$given, $family] = array_pad(explode(' ', (string) $teacher->name, 2), 2, '');
            $files['users.csv'][] = [$id('user', $teacher->id), 'active', $now, 'true', '', '', $given, $family, '', '', (string) $teacher->email, '', '', '', '', '', '', '', '', '', '', $orgId, ''];
            $files['roles.csv'][] = [$id('role-teacher', $teacher->id), 'active', $now, $id('user', $teacher->id), 'primary', 'teacher', $orgId, ''];
        }

        foreach (User::query()->whereIn('id', $guardianLinks->pluck('user_id')->unique()->diff($teacherIds))->get() as $guardian) {
            [$given, $family] = array_pad(explode(' ', (string) $guardian->name, 2), 2, '');
            // Contact details deliberately omitted (minimisation).
            $files['users.csv'][] = [$id('user', $guardian->id), 'active', $now, 'true', '', '', $given, $family, '', '', '', '', '', '', '', '', '', '', '', '', '', $orgId, ''];
            $files['roles.csv'][] = [$id('role-guardian', $guardian->id), 'active', $now, $id('user', $guardian->id), 'primary', 'guardian', $orgId, ''];
        }

        foreach ($teacherAssignments as $ta) {
            $files['enrollments.csv'][] = [$id('enrollment-t', $ta->id), 'active', $now, $id('class', $ta->id), $orgId, $id('user', $ta->user_id), 'teacher', 'true', '', ''];

            foreach ($students->where('school_class_id', $ta->school_class_id) as $student) {
                $files['enrollments.csv'][] = [$id('enrollment', $ta->id * 1000000 + $student->id), 'active', $now, $id('class', $ta->id), $orgId, $id('student', $student->id), 'student', 'false', '', ''];
            }
        }

        $files['manifest.csv'] = [['propertyName', 'value'], ['manifest.version', '1.0'], ['oneroster.version', '1.2'],
            ['file.academicSessions', 'bulk'], ['file.categories', 'absent'], ['file.classes', 'bulk'], ['file.classResources', 'absent'],
            ['file.courses', 'bulk'], ['file.courseResources', 'absent'], ['file.demographics', 'absent'], ['file.enrollments', 'bulk'],
            ['file.lineItemLearningObjectiveIds', 'absent'], ['file.lineItems', 'absent'], ['file.lineItemScoreScales', 'absent'],
            ['file.orgs', 'bulk'], ['file.resources', 'absent'], ['file.resultLearningObjectiveIds', 'absent'], ['file.results', 'absent'],
            ['file.resultScoreScales', 'absent'], ['file.roles', 'bulk'], ['file.scoreScales', 'absent'], ['file.userProfiles', 'absent'],
            ['file.userResources', 'absent'], ['file.users', 'bulk'], ['source.systemName', (string) config('app.name')], ['source.systemCode', $tenant->slug]];

        $issues = array_merge($issues, $this->validate($files));

        return [
            'files' => $files,
            'issues' => $issues,
            'counts' => collect($files)->except('manifest.csv')->map(fn (array $rows) => count($rows) - 1)->all(),
        ];
    }

    /**
     * Referential checks between the files (every referenced sourcedId
     * exists and every sourcedId is unique).
     *
     * @param  array<string, array<int, array<int, string>>>  $files
     * @return array<int, string>
     */
    private function validate(array $files): array
    {
        $issues = [];
        $ids = fn (string $file) => collect(array_slice($files[$file], 1))->pluck(0);

        foreach (['orgs.csv', 'academicSessions.csv', 'courses.csv', 'classes.csv', 'users.csv', 'roles.csv', 'enrollments.csv'] as $file) {
            $dupes = $ids($file)->duplicates();
            if ($dupes->isNotEmpty()) {
                $issues[] = "{$file}: duplicate sourcedId ".Str::limit($dupes->implode(','), 80);
            }
        }

        $users = $ids('users.csv')->flip();
        $classes = $ids('classes.csv')->flip();

        foreach (array_slice($files['enrollments.csv'], 1) as $row) {
            if (! isset($classes[$row[3]]) || ! isset($users[$row[5]])) {
                $issues[] = "enrollments.csv: {$row[0]} references a missing class or user";
            }
        }

        return $issues;
    }
}
