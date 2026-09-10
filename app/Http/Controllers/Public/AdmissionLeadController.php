<?php

namespace App\Http\Controllers\Public;

use App\Domain\Admissions\Models\AdmissionLead;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAdmissionLeadRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AdmissionLeadController extends Controller
{
    public function store(StoreAdmissionLeadRequest $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $data = $request->validated();

        // Duplicate-lead warning (docs/02 section 5.1): flag, don't block —
        // the school still wants to see repeat interest, just merged for
        // follow-up instead of treated as unrelated new contacts.
        $existing = AdmissionLead::query()
            ->where('tenant_id', $tenant->id)
            ->where('contact_value', $data['contact_value'])
            ->where('created_at', '>=', now()->subDay())
            ->latest('created_at')
            ->first();

        $tenant->admissionLeads()->create([
            'guardian_name' => $data['guardian_name'],
            'contact_method' => $data['contact_method'],
            'contact_value' => $data['contact_value'],
            'desired_grade' => $data['desired_grade'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'consent_given' => true,
            'stage' => AdmissionLead::STAGE_NEW,
            'duplicate_of_lead_id' => $existing?->id,
            'duplicate_of_checked_at' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'მოთხოვნა მივიღეთ. სკოლის გუნდი დაგიკავშირდებათ თქვენ მიერ მითითებულ ნომერზე.',
        ]);

        return back();
    }
}
