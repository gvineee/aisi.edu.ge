<?php

/*
 * Private upload scanning (CLAUDE.md #5). Until an antivirus engine is
 * installed on the server the NullFileScanner marks files `skipped`.
 * Set UPLOADS_ALLOW_UNSCANNED=false to refuse serving unscanned files
 * entirely (recommended once real families upload into production).
 */
return [
    'allow_unscanned' => (bool) env('UPLOADS_ALLOW_UNSCANNED', true),
];
