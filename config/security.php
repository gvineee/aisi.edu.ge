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
];
