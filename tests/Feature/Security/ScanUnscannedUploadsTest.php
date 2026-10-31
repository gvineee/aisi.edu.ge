<?php

namespace Tests\Feature\Security;

use App\Domain\Uploads\FileScanner;
use App\Domain\Uploads\ScanStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Switching the antivirus on: the engine is proven with EICAR first, then
 * uploads stored without a scan are scanned; an infected one stops being
 * served, an unreadable one is left as it was and reported.
 */
class ScanUnscannedUploadsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEngine(): void
    {
        $this->app->instance(FileScanner::class, new class implements FileScanner
        {
            public function scan(string $disk, string $path): string
            {
                // By name: a local antivirus (e.g. Windows Defender) may
                // remove a real EICAR file from the test disk on write.
                return str_contains($path, 'eicar') || str_contains($path, 'bad') ? ScanStatus::INFECTED : ScanStatus::CLEAN;
            }
        });
    }

    public function test_refuses_without_an_engine(): void
    {
        config(['uploads.scanner' => 'null']);

        $this->artisan('uploads:scan-unscanned --check-only')->assertFailed();
    }

    public function test_checks_the_engine_and_scans_skipped_uploads(): void
    {
        Storage::fake('local');
        $this->fakeEngine();
        Storage::disk('local')->put('portfolio/ok.png', 'image');
        Storage::disk('local')->put('portfolio/bad.png', 'EICAR inside');
        $ok = DB::table('portfolio_assets')->insertGetId($this->asset('portfolio/ok.png'));
        $bad = DB::table('portfolio_assets')->insertGetId($this->asset('portfolio/bad.png'));

        $this->artisan('uploads:scan-unscanned --check-only')->expectsOutputToContain('ანტივირუსი მუშაობს')->assertSuccessful();
        $this->assertSame(ScanStatus::SKIPPED, DB::table('portfolio_assets')->where('id', $ok)->value('scan_status'));

        $this->artisan('uploads:scan-unscanned')->expectsOutputToContain('სუფთა: 1 · ინფიცირებული (დაიბლოკა): 1 · ვერ შემოწმდა (უცვლელი): 0')->assertSuccessful();
        $this->assertSame(ScanStatus::CLEAN, DB::table('portfolio_assets')->where('id', $ok)->value('scan_status'));
        $this->assertSame(ScanStatus::INFECTED, DB::table('portfolio_assets')->where('id', $bad)->value('scan_status'));
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->directories(), fn ($dir) => str_starts_with($dir, 'scanner-check-'))), 'the EICAR probe is removed');
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $path): array
    {
        $tenant = DB::table('tenants')->value('id');
        $student = DB::table('students')->insertGetId(['tenant_id' => $tenant, 'first_name' => 'ტ', 'last_name' => 'ტ', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('portfolio_items')->insertGetId(['tenant_id' => $tenant, 'student_id' => $student, 'title' => 'ნამუშევარი', 'status' => 'draft', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['tenant_id' => $tenant, 'portfolio_item_id' => $item, 'storage_path' => $path, 'checksum' => 'x', 'mime' => 'image/png', 'size' => 5, 'original_filename' => 'a.png', 'scan_status' => ScanStatus::SKIPPED, 'created_at' => now(), 'updated_at' => now()];
    }
}
