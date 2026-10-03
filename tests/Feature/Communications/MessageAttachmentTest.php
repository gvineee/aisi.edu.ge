<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\MessageAttachment;
use App\Domain\Tenancy\Models\TenantDomain;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Uploads\FileScanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE.md #5 for message attachments: allow-listed type and size,
 * private storage, served only to participants and only once the scan
 * allows it.
 */
class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachment_is_private_scanned_and_participant_only(): void
    {
        Storage::fake('local');
        $this->app->instance(FileScanner::class, new class implements FileScanner
        {
            public string $result = 'pending';

            public function scan(string $disk, string $path): string
            {
                return $this->result;
            }
        });

        $tenant = TenantDomain::query()->where('domain', 'localhost')->first()->tenant;
        $year = $tenant->academicYears()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-15', 'is_current' => true]);
        $class = $tenant->schoolClasses()->create(['academic_year_id' => $year->id, 'name' => 'VI']);
        $student = $tenant->students()->create(['school_class_id' => $class->id, 'first_name' => 'ნიკა', 'last_name' => 'დ.', 'is_active' => true]);
        $guardian = User::factory()->create();
        $teacher = User::factory()->create();
        $stranger = User::factory()->create();
        foreach ([[$guardian, TenantMembership::ROLE_GUARDIAN], [$teacher, TenantMembership::ROLE_TEACHER], [$stranger, TenantMembership::ROLE_TEACHER]] as [$u, $role]) {
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        }
        $tenant->guardianLinks()->create(['user_id' => $guardian->id, 'student_id' => $student->id, 'can_view_academic' => true, 'can_view_financial' => false, 'can_pickup' => true, 'can_receive_notifications' => true, 'is_active' => true]);
        $tenant->teacherAssignments()->create(['user_id' => $teacher->id, 'school_class_id' => $class->id, 'subject' => 'ქართული ენა']);

        $this->actingAs($guardian)->post('/portal/messages', ['recipient_id' => $teacher->id, 'subject' => 'ცნობა', 'body' => 'იხილეთ ცნობა',
            'attachment' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $conversation = Conversation::query()->firstOrFail();

        // Disallowed type is refused.
        $this->actingAs($teacher)->post("/portal/messages/{$conversation->id}/reply", ['body' => 'x',
            'attachment' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('attachment');

        $attachment = MessageAttachment::query()->firstOrFail();
        $this->assertStringStartsWith("messages/{$tenant->id}/", $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);
        $url = "/portal/messages/{$conversation->id}/attachments/{$attachment->id}";

        // Pending scan → not served, even to participants.
        $this->assertSame('pending', $attachment->scan_status);
        $this->actingAs($teacher)->get($url)->assertStatus(423);
        $this->actingAs($teacher)->get("/portal/messages/{$conversation->id}")
            ->assertInertia(fn (Assert $page) => $page->where('activeConversation.messages.0.attachments.0.available', false));

        $attachment->scan_status = 'clean';
        $attachment->save();
        $this->actingAs($teacher)->get($url)->assertOk();
        $this->actingAs($guardian)->get($url)->assertOk();
        $this->actingAs($stranger)->get($url)->assertForbidden();

        $attachment->scan_status = 'infected';
        $attachment->save();
        $this->actingAs($teacher)->get($url)->assertStatus(423);
    }
}
