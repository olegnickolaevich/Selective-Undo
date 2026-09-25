<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

/** Another worker took over the job or the job reached a terminal state. */
final class LeaseLost extends \RuntimeException
{
}
