<?php

namespace App\Domain\Tenancy\Actions;

use RuntimeException;

/**
 * Thrown when someone tries to accept a TenantInvitation past its
 * expires_at. The controller maps this to an honest "this link expired"
 * page, never a silent failure or a generic 500.
 */
class InvitationExpiredException extends RuntimeException {}
