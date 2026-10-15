<?php

namespace App\Domain\Documents\Models;

/**
 * Ordered access levels, so "at least" checks are integer comparisons.
 */
final class DocumentAccessLevel
{
    public const NONE = 0;

    /** Read the current (published) version and its metadata. */
    public const VIEW = 1;

    /** VIEW + working drafts, every version, new versions, metadata edits. */
    public const EDIT = 2;

    /** EDIT + access grants, visibility, archive and approval decisions. */
    public const MANAGE = 3;
}
