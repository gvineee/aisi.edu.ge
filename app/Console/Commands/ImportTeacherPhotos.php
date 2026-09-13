<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\SaveTeacher;
use App\Domain\Content\Models\Teacher;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * One-off, re-runnable bulk photo import for teachers whose real photo the
 * school already had published (recovered from the old site's own
 * "instructor" carousel, which links each real name to a real photo file —
 * see docs/design-parity-checklist.md's 2026-09-13 entry). Matches strictly
 * by exact Teacher.name; any file that doesn't match a known teacher is
 * reported and skipped rather than guessed at, so a stray/renamed file never
 * gets silently attached to the wrong person.
 *
 * Photo files must be named "<Teacher Name with underscores for spaces>.<ext>",
 * e.g. "სოფიკო_ტატულაშვილი.jpeg", matching Teacher.name once underscores are
 * turned back into spaces.
 */
class ImportTeacherPhotos extends Command
{
    protected $signature = 'teachers:import-photos {directory : Local directory of "<Name_With_Underscores>.<ext>" photo files} {--tenant=aisi}';

    protected $description = 'Attach real photo files to existing Teacher records, matched by exact name';

    public function handle(SaveTeacher $saveTeacher): int
    {
        $directory = (string) $this->argument('directory');
        $tenantSlug = (string) $this->option('tenant');

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant) {
            $this->error("No tenant found with slug \"{$tenantSlug}\".");

            return self::FAILURE;
        }

        $actorMembership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantMembership::ROLE_ADMIN)
            ->where('is_active', true)
            ->first();

        $actor = $actorMembership ? User::find($actorMembership->user_id) : null;

        if (! $actor) {
            $this->error("No active admin membership found for tenant \"{$tenantSlug}\" to attribute this change to.");

            return self::FAILURE;
        }

        $files = glob(rtrim($directory, '/\\').'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
        $matched = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $basename = pathinfo($file, PATHINFO_FILENAME);
            $name = str_replace('_', ' ', $basename);

            $teacher = Teacher::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $name)
                ->first();

            if (! $teacher) {
                $this->warn("No teacher named \"{$name}\" — skipping {$file}.");
                $skipped++;

                continue;
            }

            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $mime = match (strtolower($extension)) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            $upload = new UploadedFile($file, basename($file), $mime, null, true);

            $saveTeacher->handle(
                $tenant->id,
                $teacher,
                [
                    'name' => $teacher->name,
                    'subject' => $teacher->subject,
                    'bio' => $teacher->bio,
                    'display_order' => $teacher->display_order,
                ],
                $actor,
                $upload,
            );

            $this->info("Photo attached: {$teacher->name}");
            $matched++;
        }

        $this->newLine();
        $this->info("{$matched} photo(s) attached, {$skipped} file(s) skipped (no matching teacher).");

        return self::SUCCESS;
    }
}
