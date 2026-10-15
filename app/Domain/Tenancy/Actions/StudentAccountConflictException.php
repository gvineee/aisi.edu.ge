<?php

namespace App\Domain\Tenancy\Actions;

use RuntimeException;

/**
 * Thrown when a student invitation cannot be accepted because the roster
 * row already belongs to another account, or the accepting account is
 * already linked to a different student in this school. The controller
 * shows an honest error; nothing is half-applied (the transaction rolls
 * back).
 */
class StudentAccountConflictException extends RuntimeException {}
