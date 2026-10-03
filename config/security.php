<?php

return [
    /*
     * CLAUDE.md invariant #4: tenant admins must enrol two-factor
     * authentication before using the private portal (EnsureAdminMfa).
     * Disable only for automated tests or an explicit, documented
     * emergency.
     */
    'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', true),
];
