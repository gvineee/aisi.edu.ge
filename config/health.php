<?php

/*
 * Health record settings (CLAUDE-PLATFORM-MODULES §8). Retention, deletion
 * and export rules are deliberately not hard-coded: until the school's
 * legal retention period is confirmed, records are kept and no bulk
 * export endpoint exists. See docs/implementation-status.md.
 */
return [
    // How long a reasoned "break glass" emergency view stays open.
    'emergency_access_minutes' => (int) env('HEALTH_EMERGENCY_ACCESS_MINUTES', 120),

    // Lifetime of a signed care-plan download link.
    'download_link_minutes' => (int) env('HEALTH_DOWNLOAD_LINK_MINUTES', 5),

    // null = keep until a legally confirmed retention period is configured.
    'retention_years' => env('HEALTH_RETENTION_YEARS') !== null ? (int) env('HEALTH_RETENTION_YEARS') : null,

    'care_plan_max_bytes' => 10 * 1024 * 1024,

    'care_plan_mime_types' => ['application/pdf', 'image/png', 'image/jpeg'],
];
