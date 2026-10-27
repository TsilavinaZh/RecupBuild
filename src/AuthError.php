<?php

declare(strict_types=1);

namespace RecupBuild;

/** The Google sign-in failed: the message is for the logs, the user sees a generic error. */
final class AuthError extends \RuntimeException
{
}
