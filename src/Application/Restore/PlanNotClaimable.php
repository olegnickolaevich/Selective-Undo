<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

defined('ABSPATH') || exit;

/** @internal The plan changed state between the check and the claim. */
final class PlanNotClaimable extends \RuntimeException
{
}
