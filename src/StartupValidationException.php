<?php

declare(strict_types=1);

namespace TelegramMirror;

use RuntimeException;

/** A local account, permission, binding or storage policy needs human repair. */
final class StartupValidationException extends RuntimeException
{
}
