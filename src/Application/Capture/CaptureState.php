<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

use SelectiveUndo\Infrastructure\Database\SchemaManager;
use SelectiveUndo\Infrastructure\Settings\Settings;

/**
 * Site-wide capture state. Any exit from "active" opens a global history gap.
 */
final class CaptureState
{
    public const OPTION = 'selective_undo_capture_state';

    public function __construct(
        private readonly Settings $settings,
        private readonly SchemaManager $schema,
        private readonly GapRecorder $gaps,
    ) {
    }

    public function isActive(): bool
    {
        return $this->settings->captureEnabled() && $this->schema->isCurrent() && $this->pausedReason() === null;
    }

    /**
     * @return array{state: string, reason: string|null, since: int|null}
     */
    public function describe(): array
    {
        if (!$this->schema->isCurrent()) {
            return ['state' => 'paused', 'reason' => 'schema_outdated', 'since' => null];
        }

        if (!$this->settings->captureEnabled()) {
            return ['state' => 'disabled', 'reason' => 'capture_disabled', 'since' => null];
        }

        $stored = $this->stored();

        return $stored['reason'] === null
            ? ['state' => 'active', 'reason' => null, 'since' => null]
            : ['state' => 'paused', 'reason' => $stored['reason'], 'since' => $stored['since']];
    }

    public function pausedReason(): ?string
    {
        return $this->stored()['reason'];
    }

    public function pause(string $reason): void
    {
        if ($this->pausedReason() === $reason) {
            return;
        }

        update_option(self::OPTION, ['reason' => $reason, 'since' => time()], true);
        $this->gaps->openGlobal($reason);
    }

    public function resume(): void
    {
        $reason = $this->pausedReason();

        if ($reason === null) {
            return;
        }

        update_option(self::OPTION, ['reason' => null, 'since' => null], true);
        $this->gaps->closeGlobal($reason);
    }

    /**
     * @return array{reason: string|null, since: int|null}
     */
    private function stored(): array
    {
        $value = get_option(self::OPTION, []);
        $value = is_array($value) ? $value : [];

        return [
            'reason' => isset($value['reason']) && is_string($value['reason']) ? $value['reason'] : null,
            'since' => isset($value['since']) ? (int) $value['since'] : null,
        ];
    }
}
