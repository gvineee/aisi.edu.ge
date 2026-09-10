<?php

namespace App\Domain\Documents\Actions;

use RuntimeException;

/**
 * Thrown by DecideApproval when a second decision targets an approval
 * request that a concurrent (or earlier) call already resolved. The
 * controller maps this to HTTP 409 rather than a generic 500/422.
 */
class ApprovalAlreadyDecidedException extends RuntimeException {}
