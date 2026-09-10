<?php

namespace App\Domain\Communications\Actions;

use RuntimeException;

/**
 * Thrown when an action is attempted by a user who is not (or no longer) a
 * participant of the conversation — never inferred from role alone.
 */
class NotAConversationParticipant extends RuntimeException {}
