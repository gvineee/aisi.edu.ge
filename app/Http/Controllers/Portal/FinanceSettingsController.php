<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Finance\Models\FinanceSetting;
use App\Domain\Governance\AuditLogger;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\AuthorizesFinance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school's payment instructions (recipient, bank accounts) shown to
 * families on invoices. Entered by the school — never seeded or guessed.
 */
class FinanceSettingsController extends Controller
{
    use AuthorizesFinance;

    public function edit(Request $request, CurrentTenant $currentTenant): Response
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        return Inertia::render('portal/finance/settings', [
            'settings' => FinanceSetting::forTenant($tenant->id)?->toPublicArray(),
        ]);
    }

    public function update(Request $request, CurrentTenant $currentTenant, AuditLogger $audit): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $this->authorizeFinanceStaff($tenant->id, $request->user());

        // Accept IBANs typed in lower case or with spaces.
        $request->merge(['bank_accounts' => array_map(
            fn ($a) => is_array($a) ? ['bank' => $a['bank'] ?? null, 'iban' => strtoupper(preg_replace('/\s+/', '', (string) ($a['iban'] ?? '')) ?? '')] : $a,
            (array) $request->input('bank_accounts', []),
        )]);

        $data = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_code' => ['nullable', 'string', 'max:32'],
            'bank_accounts' => ['nullable', 'array', 'max:5'],
            'bank_accounts.*.bank' => ['required', 'string', 'max:100'],
            // Georgian IBAN: GE + 2 digits + 2 letters + 16 digits.
            'bank_accounts.*.iban' => ['required', 'string', 'regex:/^GE\d{2}[A-Z]{2}\d{16}$/'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ], [
            'bank_accounts.*.iban.regex' => 'IBAN უნდა იყოს ფორმატით GE00XX0000000000000000 (ჰარეების გარეშე).',
        ]);

        $settings = FinanceSetting::forTenant($tenant->id) ?? new FinanceSetting;
        $settings->tenant_id = $tenant->id;
        $settings->fill([
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_code' => $data['recipient_code'] ?? null,
            'bank_accounts' => array_values(array_map(fn (array $a) => ['bank' => trim($a['bank']), 'iban' => strtoupper(trim($a['iban']))], $data['bank_accounts'] ?? [])),
            'instructions' => $data['instructions'] ?? null,
        ]);
        $settings->save();

        $audit->record($tenant->id, 'finance.settings_updated', $settings, $request->user()->id, ['bank_accounts' => count($settings->bank_accounts ?? [])]);

        return back()->with('toast', ['type' => 'success', 'message' => 'რეკვიზიტები შენახულია.']);
    }
}
