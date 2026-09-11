<?php

namespace App\Domain\Tenancy\Actions;

use RuntimeException;

/**
 * Thrown when someone tries to accept a TenantInvitation a second time
 * (or two concurrent accepts race for the same row). The controller maps
 * this to an honest "this invitation was already used" page, never a
 * silent failure or a duplicate membership/GuardianLink/TeacherAssignment.
 */
class InvitationAlreadyAcceptedException extends RuntimeException {}
