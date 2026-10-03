<?php

/*
 * Private upload scanning (CLAUDE.md #5). Until an antivirus engine is
 * installed on the server the NullFileScanner marks files `skipped`.
 * Set UPLOADS_ALLOW_UNSCANNED=false to refuse serving unscanned files
 * entirely (recommended once real families upload into production).
 */
return [
    'allow_unscanned' => (bool) env('UPLOADS_ALLOW_UNSCANNED', true),

    // `null` (default, marks files `skipped`) or `clamd` (ClamdFileScanner).
    'scanner' => env('UPLOADS_SCANNER', 'null'),
    'clamd_address' => env('CLAMD_ADDRESS', 'tcp://127.0.0.1:3310'),
    'clamd_timeout' => (int) env('CLAMD_TIMEOUT', 30),
];
