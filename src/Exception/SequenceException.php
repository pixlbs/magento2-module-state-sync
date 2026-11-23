<?php

declare(strict_types=1);

namespace PixelLabs\ModuleStateSync\Exception;

use RuntimeException;

/**
 * Anything that stops the run before config.php is written; the message is shown to the user.
 */
class SequenceException extends RuntimeException
{
}
