<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Student;
use App\Domain\Finance\Actions\ManageFeeBilling;
use App\Domain\Finance\Models\FeeCharge;
use App\Domain\Finance\Models\FeePlan;
use App\Domain\Finance\Models\FeePlanInstallment;
use App\Domain\Finance\Models\StudentFeeContract;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\AuthorizesFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fee plans (tuition schedules), student fee contracts and invoice
 * generation from them. Amounts and dates are entered by the school.
 */
class FeePlanController extends Controller
{
    use AuthorizesFinance;

    public function index(Request $request, CurrentTenant $currentTenant, ManageFeeBilling $billing): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $plans = FeePlan::query()->where('tenant_id', $tenant->id)
            ->with(['installments', 'academicYear', 'contracts' => fn ($q) => $q->with('student.schoolClass')->orderBy('status')])
            ->latest('id')->get();

        $billedCounts = FeeCharge::query()->where('tenant_id', $tenant->id)
            ->selectRaw('fee_plan_installment_id, count(*) as aggregate')->groupBy('fee_plan_installment_id')
            ->pluck('aggregate', 'fee_plan_installment_id');

        $pending = $billing->generate($tenant->id, null, true, null);

        return Inertia::render('portal/finance/fee-plans', [
            'plans' => $plans->map(fn (FeePlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'isActive' => $plan->is_active,
                'academicYearName' => $plan->academicYear?->name,
                'totalMinor' => (int) $plan->installments->sum('amount_minor'),
                'installments' => $plan->installments->map(fn (FeePlanInstallment $i) => [
                    'id' => $i->id,
                    'label' => $i->label,
                    'amountMinor' => $i->amount_minor,
                    'issueOn' => $i->issue_on->toDateString(),
                    'dueOn' => $i->due_on->toDateString(),
                    'billed' => (int) ($billedCounts[$i->id] ?? 0),
                ])->values()->all(),
                'activeContracts' => $plan->contracts->where('status', StudentFeeContract::STATUS_ACTIVE)->count(),
                'contracts' => $plan->contracts->map(fn (StudentFeeContract $c) => [
                    'id' => $c->id,
                    'studentId' => $c->student_id,
                    'studentName' => $c->student?->fullName(),
                    'className' => $c->student?->schoolClass?->name,
                    'status' => $c->status,
                    'discountPercent' => $c->discount_percent,
                    'discountReason' => $c->discount_reason,
                    'contractNumber' => $c->contract_number,
                    'signedOn' => $c->signed_on?->toDateString(),
                    'endedOn' => $c->ended_on?->toDateString(),
                ])->values()->all(),
            ])->values(),
            'pending' => $pending,
            'academicYears' => AcademicYear::query()->where('tenant_id', $tenant->id)->orderByDesc('starts_on')->get(['id', 'name']),
            'students' => Student::query()->where('tenant_id', $tenant->id)->where('is_active', true)->with('schoolClass')
                ->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Student $s) => ['id' => $s->id, 'name' => $s->fullName(), 'className' => $s->schoolClass?->name])->values(),
        ]);
    }

    public function store(Request $request, CurrentTenant $currentTenant, ManageFeeBilling $billing): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'academic_year_id' => ['nullable', 'integer'],
            'installments' => ['required', 'array', 'min:1', 'max:24'],
            'installments.*.label' => ['required', 'string', 'max:120'],
            'installments.*.amount' => ['required', 'string', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'installments.*.issue_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.due_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $year = isset($data['academic_year_id'])
            ? AcademicYear::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['academic_year_id'])->firstOrFail()
            : null;

        $billing->createPlan($tenant->id, $data['name'], $year, array_map(fn (array $i) => [
            'label' => $i['label'],
            'amount_minor' => self::moneyToMinor($i['amount']),
            'issue_on' => $i['issue_on'],
            'due_on' => $i['due_on'],
        ], $data['installments']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'საფასურის გეგმა შეიქმნა.']);
    }

    public function toggle(Request $request, CurrentTenant $currentTenant, FeePlan $feePlan, ManageFeeBilling $billing): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($feePlan->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $billing->setPlanActive($feePlan, ! $feePlan->is_active, $request->user());

        return back();
    }

    public function storeContract(Request $request, CurrentTenant $currentTenant, FeePlan $feePlan, ManageFeeBilling $billing): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($feePlan->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'signed_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = Student::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['student_id'])->firstOrFail();

        $billing->addContract(
            $feePlan,
            $student,
            (int) ($data['discount_percent'] ?? 0),
            $data['discount_reason'] ?? null,
            $data['contract_number'] ?? null,
            isset($data['signed_on']) ? Carbon::parse($data['signed_on']) : null,
            $data['notes'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'ხელშეკრულება შენახულია.']);
    }

    public function endContract(Request $request, CurrentTenant $currentTenant, StudentFeeContract $contract, ManageFeeBilling $billing): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($contract->tenant_id === $tenant->id, 404);
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $data = $request->validate(['ended_on' => ['required', 'date_format:Y-m-d']]);

        $billing->endContract($contract, Carbon::parse($data['ended_on']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ხელშეკრულება დასრულდა; შემდეგი შენატანები აღარ გამოიწერება.']);
    }

    public function generate(Request $request, CurrentTenant $currentTenant, ManageFeeBilling $billing): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());
        $request->validate(['installment_id' => ['nullable', 'integer']]);

        $installment = $request->filled('installment_id')
            ? FeePlanInstallment::query()->where('tenant_id', $tenant->id)->whereKey($request->integer('installment_id'))->firstOrFail()
            : null;

        $result = $billing->generate($tenant->id, $installment, false, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $result['invoices'] + $result['waived'] === 0
                ? 'გამოსაწერი ინვოისი არ არის.'
                : "გამოიწერა {$result['invoices']} ინვოისი".($result['waived'] > 0 ? ", {$result['waived']} სრულად შეღავათიანი" : '').'.',
        ]);
    }
}
