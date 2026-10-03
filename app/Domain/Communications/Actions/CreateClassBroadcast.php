<?php

namespace App\Domain\Communications\Actions;

use App\Domain\Academics\Models\GuardianLink;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\TeacherAssignment;
use App\Domain\Communications\Models\MessageBroadcast;
use App\Domain\Communications\Models\MessageBroadcastRecipient;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Jobs\DeliverMessageBroadcast;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Class announcement to families (CLAUDE-PLATFORM-MODULES §4). Who may
 * send: a teacher assigned to (or homeroom of) the class, or school
 * leadership. Recipients are resolved here, on the server, from active
 * guardian links that allow notifications for active students of the class
 * with an active guardian membership — and snapshotted, so later roster
 * changes do not alter who an announcement went to. Delivery is queued
 * after commit.
 */
class CreateClassBroadcast
{
    /**
     * @var array<int, string>
     */
    public const LEADERSHIP_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_DIRECTOR,
        TenantMembership::ROLE_ACADEMIC_MANAGER,
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return Collection<int, SchoolClass>
     */
    public function classesFor(int $tenantId, User $user): Collection
    {
        if (TenantMembership::userHasAnyActiveRole($tenantId, $user->id, self::LEADERSHIP_ROLES)) {
            return SchoolClass::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        if (! TenantMembership::userHasActiveRole($tenantId, $user->id, TenantMembership::ROLE_TEACHER)) {
            return new Collection;
        }

        $assigned = TeacherAssignment::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->pluck('school_class_id');

        return SchoolClass::query()->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereIn('id', $assigned)->orWhere('homeroom_teacher_id', $user->id))
            ->orderBy('name')->get();
    }

    public function handle(int $tenantId, User $sender, SchoolClass $class, string $subject, string $body): MessageBroadcast
    {
        if (! $this->classesFor($tenantId, $sender)->contains('id', $class->id)) {
            abort(403);
        }

        $guardianIds = GuardianLink::query()->where('tenant_id', $tenantId)->where('is_active', true)->where('can_receive_notifications', true)
            ->whereHas('student', fn ($query) => $query->where('school_class_id', $class->id)->where('is_active', true))
            ->pluck('user_id')->unique()
            ->filter(fn (int $userId) => $userId !== $sender->id && TenantMembership::userHasActiveRole($tenantId, $userId, TenantMembership::ROLE_GUARDIAN))
            ->values();

        if ($guardianIds->isEmpty()) {
            throw ValidationException::withMessages(['school_class_id' => 'ამ კლასს შეტყობინების მიმღები მშობელი არ ჰყავს.']);
        }

        $role = TenantMembership::userHasAnyActiveRole($tenantId, $sender->id, self::LEADERSHIP_ROLES) ? 'staff' : TenantMembership::ROLE_TEACHER;

        return DB::transaction(function () use ($tenantId, $sender, $class, $subject, $body, $guardianIds, $role) {
            $broadcast = new MessageBroadcast;
            $broadcast->tenant_id = $tenantId;
            $broadcast->sender_id = $sender->id;
            $broadcast->school_class_id = $class->id;
            $broadcast->sender_role = $role;
            $broadcast->subject = $subject;
            $broadcast->body = $body;
            $broadcast->status = MessageBroadcast::STATUS_QUEUED;
            $broadcast->recipients_count = $guardianIds->count();
            $broadcast->save();

            foreach ($guardianIds as $userId) {
                $recipient = new MessageBroadcastRecipient;
                $recipient->tenant_id = $tenantId;
                $recipient->message_broadcast_id = $broadcast->id;
                $recipient->user_id = $userId;
                $recipient->status = MessageBroadcastRecipient::STATUS_PENDING;
                $recipient->save();
            }

            $this->auditLogger->record($tenantId, 'message.broadcast_created', $broadcast, $sender->id, [
                'school_class_id' => $class->id,
                'recipients' => $guardianIds->count(),
            ]);

            DB::afterCommit(fn () => DeliverMessageBroadcast::dispatch($broadcast->id));

            return $broadcast;
        });
    }
}
