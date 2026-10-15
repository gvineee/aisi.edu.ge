<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Models\FeeCharge;
use App\Domain\Finance\Models\FeePlan;
use App\Domain\Finance\Models\FeePlanInstallment;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\StudentFeeContract;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fee plans (the school's own amounts and installment dates), per-student
 * fee contracts with an optional discount, and invoice generation from
 * them. Generation is idempotent: each (contract, installment) pair is
 * billed at most once, enforced by a unique key, so a scheduled run and a
 * manual "generate now" can never double-bill a family.
 */
class ManageFeeBilling
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ManageInvoices $invoices,
        private readonly NotifyInvoiceGuardians $notifier,
    ) {}

    /**
     * @param  array<int, array{label: string, amount_minor: int, issue_on: string, due_on: string}>  $installments
     */
    public function createPlan(int $tenantId, string $name, ?AcademicYear $year, array $installments, User $actor): FeePlan
    {
        if ($installments === []) {
            throw ValidationException::withMessages(['installments' => 'გეგმას სულ მცირე ერთი შენატანი სჭირდება.']);
        }

        foreach ($installments as $i => $installment) {
            if ($installment['amount_minor'] < 1) {
                throw ValidationException::withMessages(["installments.{$i}.amount" => 'თანხა დადებითი უნდა იყოს.']);
            }

            if ($installment['due_on'] < $installment['issue_on']) {
                throw ValidationException::withMessages(["installments.{$i}.due_on" => 'ვადა გამოწერის თარიღზე ადრე ვერ იქნება.']);
            }
        }

        return DB::transaction(function () use ($tenantId, $name, $year, $installments, $actor) {
            $plan = new FeePlan([
                'academic_year_id' => $year?->id,
                'name' => trim($name),
                'currency' => (string) config('finance.currency', 'GEL'),
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
            $plan->tenant_id = $tenantId;
            $plan->save();

            foreach ($installments as $installment) {
                $row = new FeePlanInstallment([
                    'fee_plan_id' => $plan->id,
                    'label' => trim($installment['label']),
                    'amount_minor' => $installment['amount_minor'],
                    'issue_on' => $installment['issue_on'],
                    'due_on' => $installment['due_on'],
                ]);
                $row->tenant_id = $tenantId;
                $row->save();
            }

            $this->auditLogger->record($tenantId, 'fee_plan.created', $plan, $actor->id, [
                'name' => $plan->name,
                'installments' => count($installments),
                'total_minor' => array_sum(array_column($installments, 'amount_minor')),
            ]);

            return $plan;
        });
    }

    public function setPlanActive(FeePlan $plan, bool $active, User $actor): void
    {
        $plan->is_active = $active;
        $plan->save();

        $this->auditLogger->record($plan->tenant_id, $active ? 'fee_plan.activated' : 'fee_plan.deactivated', $plan, $actor->id, []);
    }

    public function addContract(
        FeePlan $plan,
        Student $student,
        int $discountPercent,
        ?string $discountReason,
        ?string $contractNumber,
        ?Carbon $signedOn,
        ?string $notes,
        User $actor,
    ): StudentFeeContract {
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw ValidationException::withMessages(['discount_percent' => 'ფასდაკლება 0–100% შუალედშია.']);
        }

        if ($discountPercent > 0 && ($discountReason === null || mb_strlen(trim($discountReason)) < 3)) {
            throw ValidationException::withMessages(['discount_reason' => 'ფასდაკლებას საფუძველი სჭირდება.']);
        }

        $existing = StudentFeeContract::query()->where('tenant_id', $plan->tenant_id)
            ->where('student_id', $student->id)->where('fee_plan_id', $plan->id)->first();

        if ($existing !== null && $existing->status === StudentFeeContract::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['student_id' => 'ამ მოსწავლეს ეს გეგმა უკვე აქვს.']);
        }

        $contract = $existing ?? new StudentFeeContract(['student_id' => $student->id, 'fee_plan_id' => $plan->id, 'created_by' => $actor->id]);
        $contract->tenant_id = $plan->tenant_id;
        $contract->fill([
            'contract_number' => $contractNumber !== null && trim($contractNumber) !== '' ? trim($contractNumber) : null,
            'signed_on' => $signedOn?->toDateString(),
            'discount_percent' => $discountPercent,
            'discount_reason' => $discountPercent > 0 ? trim((string) $discountReason) : null,
            'status' => StudentFeeContract::STATUS_ACTIVE,
            'ended_on' => null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);
        $contract->save();

        $this->auditLogger->record($plan->tenant_id, 'fee_contract.saved', $contract, $actor->id, [
            'student_id' => $student->id,
            'fee_plan_id' => $plan->id,
            'discount_percent' => $discountPercent,
        ]);

        return $contract;
    }

    /**
     * Ending a contract stops future installments; already issued invoices
     * stay as they are (void them explicitly if needed).
     */
    public function endContract(StudentFeeContract $contract, Carbon $endedOn, User $actor): void
    {
        $contract->status = StudentFeeContract::STATUS_ENDED;
        $contract->ended_on = $endedOn;
        $contract->save();

        $this->auditLogger->record($contract->tenant_id, 'fee_contract.ended', $contract, $actor->id, ['ended_on' => $endedOn->toDateString()]);
    }

    /**
     * Bills due installments. With $installment, bills that installment
     * now (even before its issue date); otherwise every installment of an
     * active plan whose issue date has arrived. A dry run only counts.
     *
     * @return array{invoices: int, waived: int, totalMinor: int, skippedInactive: int, rows: array<int, array{student: string, installment: string, amountMinor: int}>}
     */
    public function generate(int $tenantId, ?FeePlanInstallment $installment, bool $dryRun, ?User $actor, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now('Asia/Tbilisi'))->copy()->startOfDay();

        $installments = $installment !== null
            ? collect([$installment])
            : FeePlanInstallment::query()->where('tenant_id', $tenantId)
                ->where('issue_on', '<=', $today->toDateString())
                ->whereHas('plan', fn ($query) => $query->where('is_active', true))
                ->with('plan')->orderBy('issue_on')->orderBy('id')->get();

        $summary = ['invoices' => 0, 'waived' => 0, 'totalMinor' => 0, 'skippedInactive' => 0, 'rows' => []];

        foreach ($installments as $row) {
            $row->loadMissing('plan');

            if ($row->tenant_id !== $tenantId || ! $row->plan->is_active) {
                continue;
            }

            $contracts = StudentFeeContract::query()->where('tenant_id', $tenantId)
                ->where('fee_plan_id', $row->fee_plan_id)->where('status', StudentFeeContract::STATUS_ACTIVE)
                ->whereDoesntHave('charges', fn ($query) => $query->where('fee_plan_installment_id', $row->id))
                ->with('student')->orderBy('id')->get();

            foreach ($contracts as $contract) {
                if ($contract->student === null || ! $contract->student->is_active) {
                    $summary['skippedInactive']++;

                    continue;
                }

                $net = $contract->netAmount($row->amount_minor);

                if (count($summary['rows']) < 200) {
                    $summary['rows'][] = ['student' => $contract->student->fullName(), 'installment' => "{$row->plan->name} — {$row->label}", 'amountMinor' => $net];
                }

                if ($dryRun) {
                    $net > 0 ? $summary['invoices']++ : $summary['waived']++;
                    $summary['totalMinor'] += $net;

                    continue;
                }

                $billedBy = $actor ?? User::query()->find($row->plan->created_by);

                if ($billedBy === null) {
                    continue;
                }

                $invoice = $this->billOne($contract, $row, $net, $billedBy, $today);

                if ($invoice === false) {
                    continue; // billed concurrently
                }

                if ($invoice === null) {
                    $summary['waived']++;
                } else {
                    $summary['invoices']++;
                    $summary['totalMinor'] += $net;
                    $this->notifier->issued($invoice);
                }
            }
        }

        if (! $dryRun && ($summary['invoices'] + $summary['waived']) > 0) {
            $this->auditLogger->record($tenantId, 'fee_invoices.generated', Tenant::query()->findOrFail($tenantId), $actor?->id, [
                'invoices' => $summary['invoices'],
                'waived' => $summary['waived'],
                'total_minor' => $summary['totalMinor'],
                'scheduled' => $actor === null,
            ]);
        }

        return $summary;
    }

    /**
     * @return Invoice|null|false the invoice, null when waived, false when another run billed it first
     */
    private function billOne(StudentFeeContract $contract, FeePlanInstallment $installment, int $net, User $actor, Carbon $today): Invoice|null|false
    {
        try {
            return DB::transaction(function () use ($contract, $installment, $net, $actor, $today) {
                $charge = new FeeCharge(['student_fee_contract_id' => $contract->id, 'fee_plan_installment_id' => $installment->id]);
                $charge->tenant_id = $contract->tenant_id;
                $charge->save();

                if ($net === 0) {
                    return null;
                }

                $description = "{$installment->plan->name} — {$installment->label}";

                if ($contract->discount_percent > 0) {
                    $description .= " (ფასდაკლება {$contract->discount_percent}%)";
                }

                $dueOn = $installment->due_on->copy()->lt($today) ? $today->copy() : Carbon::parse($installment->due_on->toDateString(), 'Asia/Tbilisi');

                $invoice = $this->invoices->issue($this->invoices->accountFor($contract->student), [[
                    'description' => mb_substr($description, 0, 255),
                    'quantity' => 1,
                    'unit_minor' => $net,
                    'source_type' => 'fee_installment',
                    'source_id' => $installment->id,
                ]], $dueOn, $actor);

                $charge->invoice_id = $invoice->id;
                $charge->save();

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
