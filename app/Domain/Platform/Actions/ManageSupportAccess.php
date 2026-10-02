<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Governance\AuditLogger;
use App\Domain\Platform\Models\SupportAccessRequest;
use App\Domain\Platform\PlatformAudit;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Support access (CLAUDE-PLATFORM-MODULES §14): request with a reason →
 * the tenant's own admin/director approves for a limited time → a visible,
 * read-only support view → automatic expiry or revocation. Recorded in
 * both the platform audit log and the tenant's own audit log, so the
 * school sees it too. There is no impersonation.
 */
class ManageSupportAccess
{
    public const MAX_MINUTES = 240;

    public function __construct(
        private readonly PlatformAudit $platformAudit,
        private readonly AuditLogger $tenantAudit,
    ) {}

    public function request(User $operator, Tenant $tenant, string $reason, int $minutes): SupportAccessRequest
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'მიუთითეთ მიზეზი (მინიმუმ 10 სიმბოლო).']);
        }

        if ($minutes < 15 || $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'ხანგრძლივობა 15-დან '.self::MAX_MINUTES.' წუთამდე.']);
        }

        $request = SupportAccessRequest::query()->create([
            'tenant_id' => $tenant->id,
            'operator_id' => $operator->id,
            'reason' => trim($reason),
            'requested_minutes' => $minutes,
            'status' => 'requested',
        ]);

        $this->platformAudit->record($operator, 'support.requested', $tenant->id, ['request_id' => $request->id, 'minutes' => $minutes]);
        $this->tenantAudit->record($tenant->id, 'support_access.requested', $request, $operator->id, ['minutes' => $minutes]);

        return $request;
    }

    public function approve(SupportAccessRequest $request, User $tenantAdmin): void
    {
        $this->decide($request, $tenantAdmin, 'approved');
    }

    public function deny(SupportAccessRequest $request, User $tenantAdmin): void
    {
        $this->decide($request, $tenantAdmin, 'denied');
    }

    public function revoke(SupportAccessRequest $request, User $tenantAdmin): void
    {
        if ($request->status !== 'approved') {
            return;
        }

        $request->status = 'revoked';
        $request->expires_at = Carbon::now();
        $request->save();

        $this->platformAudit->record(null, 'support.revoked', $request->tenant_id, ['request_id' => $request->id, 'by' => $tenantAdmin->id]);
        $this->tenantAudit->record($request->tenant_id, 'support_access.revoked', $request, $tenantAdmin->id);
    }

    public function activeFor(User $operator, Tenant $tenant): ?SupportAccessRequest
    {
        return SupportAccessRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('operator_id', $operator->id)
            ->where('status', 'approved')
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();
    }

    private function decide(SupportAccessRequest $request, User $tenantAdmin, string $status): void
    {
        if ($request->status !== 'requested') {
            throw ValidationException::withMessages(['status' => 'მოთხოვნა უკვე განხილულია.']);
        }

        if ($request->operator_id === $tenantAdmin->id) {
            throw ValidationException::withMessages(['status' => 'საკუთარ მოთხოვნას ვერ დაამტკიცებთ.']);
        }

        $request->status = $status;
        $request->decided_by = $tenantAdmin->id;
        $request->decided_at = Carbon::now();
        $request->expires_at = $status === 'approved' ? Carbon::now()->addMinutes($request->requested_minutes) : null;
        $request->save();

        $this->platformAudit->record(null, "support.{$status}", $request->tenant_id, ['request_id' => $request->id, 'by' => $tenantAdmin->id]);
        $this->tenantAudit->record($request->tenant_id, "support_access.{$status}", $request, $tenantAdmin->id);
    }
}
