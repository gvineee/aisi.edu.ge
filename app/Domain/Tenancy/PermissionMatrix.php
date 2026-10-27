<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Middleware\EnsureAdminMfa;

/**
 * Which role reaches which part of the portal — read from the same
 * PortalContext navigation the portal itself is built from, so the matrix
 * cannot drift from what people actually see. Each controller still checks
 * access itself (tests/Feature/Tenancy/PermissionMatrixTest holds the two
 * together). Row-level limits — "own classes only", "linked children only"
 * — are explained in SCOPES.
 */
class PermissionMatrix
{
    /**
     * What a role sees *within* a module, where that is narrower than all.
     *
     * @var array<string, string>
     */
    private const SCOPES = [
        'my-timetable' => 'მოსწავლე — საკუთარი, მშობელი — აქტიური კავშირის მქონე შვილის, მასწავლებელი — საკუთარი გაკვეთილები და ჩანაცვლებები.',
        'calendar' => 'მოსწავლე და მშობელი — მხოლოდ „ყველასთვის“ და საკუთარი (შვილის) კლასის ჩანაწერები. ცვლის ადმინისტრაცია.',
        'curriculum' => 'მასწავლებელი ადგენს მხოლოდ თავისი კლასისა და საგნის გეგმას, სანამ მონახაზია. ამტკიცებს ადმინისტრაცია.',
        'assignments' => 'მასწავლებელი — თავისი კლასები, მოსწავლე — საკუთარი დავალებები.',
        'gradebook' => 'მხოლოდ მინიჭებული კლასი და საგანი.',
        'report-cards' => 'მასწავლებელი — თავისი საგნის ხაზები და დამრიგებლის კლასი.',
        'absence-notes' => 'მშობელი — საკუთარი შვილის, მასწავლებელი — თავისი კლასის.',
        'appointments' => 'მშობელი — საკუთარი შვილის მასწავლებლებთან.',
        'finance' => 'მშობელი — მხოლოდ ფინანსური უფლების მქონე შვილის ინვოისები.',
        'health' => 'მხოლოდ მინიჭებული ჯანმრთელობის უფლებით ან კლასით.',
        'pickup' => 'მშობელი — საკუთარი შვილისთვის.',
        'consents' => 'მშობელი — საკუთარი შვილის თანხმობები.',
        'documents' => 'სივრცის წვდომის წესით.',
        'progress' => 'მხოლოდ საკუთარი.',
        'messages' => 'მხოლოდ ის საუბრები, რომელშიც მონაწილეობს.',
    ];

    public function __construct(private readonly PortalContext $portalContext) {}

    /**
     * @param  array<int, string>  $enabledFeatures
     * @param  array<string, int>  $membersByRole
     * @return array{roles: list<array{value: string, label: string, members: int, mfa: bool}>, rows: list<array{key: string, label: string, groupLabel: string|null, roles: array<string, bool>, scope: string|null}>}
     */
    public function build(array $enabledFeatures, array $membersByRole): array
    {
        $labels = $this->portalContext->roleLabels();
        $rows = [];

        foreach (array_keys($labels) as $role) {
            foreach ($this->portalContext->navItemsFor($role, $enabledFeatures) as $item) {
                $rows[$item['key']] ??= [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'groupLabel' => $item['groupLabel'],
                    'roles' => array_fill_keys(array_keys($labels), false),
                    'scope' => self::SCOPES[$item['key']] ?? null,
                ];
                $rows[$item['key']]['roles'][$role] = true;
            }
        }

        // The sidebar's own order.
        $sorted = array_values($rows);
        usort($sorted, fn (array $a, array $b) => $this->portalContext->navPosition($a['key']) <=> $this->portalContext->navPosition($b['key']));

        return [
            'roles' => array_map(fn (string $role, string $label) => [
                'value' => $role,
                'label' => $label,
                'members' => $membersByRole[$role] ?? 0,
                'mfa' => in_array($role, EnsureAdminMfa::PRIVILEGED_ROLES, true),
            ], array_keys($labels), $labels),
            'rows' => $sorted,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function membersByRole(int $tenantId): array
    {
        return TenantMembership::query()->where('tenant_id', $tenantId)->where('is_active', true)
            ->groupBy('role')->selectRaw('role, count(*) as total')->pluck('total', 'role')
            ->map(fn ($total) => (int) $total)->all();
    }
}
