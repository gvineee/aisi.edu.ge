<?php

return [
    /*
     * CLAUDE.md invariant #4: tenant admins must enrol two-factor
     * authentication before using the private portal (EnsureAdminMfa).
     * Disable only for automated tests or an explicit, documented
     * emergency.
     */
    'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),

    /*
     * §15 privileged re-auth: role/invitation, health permission and
     * support-access changes need a recent password confirmation
     * (RequireRecentPassword).
     */
    'privileged_reauth' => (bool) env('PRIVILEGED_REAUTH', true),

    /*
     * Content-Security-Policy (SecurityHeaders): `enforce` (default; no
     * violations on any page in a browser check, 2026-10-25),
     * `report-only` (violations only reported in the browser console, a
     * quick rollback), or `off`.
     */
    'csp' => env('SECURITY_CSP', 'enforce'),
];
