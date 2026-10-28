<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

/**
 * Pauses recording when writing history keeps failing or becomes slow (I-14): five
 * failures within five minutes, or a median of the last 20 sampled writes above
 * 500 ms. After 15 minutes one write is let through as a trial; it closes the
 * breaker, or a failure opens it again for twice as long (up to an hour).
 */
final class CaptureCircuitBreaker
{
    public const GAP_REASON = 'circuit_open';
    private const KEY = 'selective_undo_circuit';
    private const FAILURES = 5;
    private const WINDOW = 300;
    private const SLOW_MS = 500;
    private const SAMPLES = 20;
    private const SAMPLE_EVERY = 20;
    private const COOLDOWN = 900;
    private const MAX_COOLDOWN = 3600;
    /** Samples are saved at most once a minute when there is no persistent object cache. */
    private const SAVE_SAMPLES_EVERY = 60;

    /** @var array{failures: list<int>, samples: list<int>, open_until: int, cooldown: int, saved: int}|null */
    private ?array $state = null;

    public function __construct(private readonly GapRecorder $gaps)
    {
    }

    /** Whether recording is paused by the breaker right now (not yet time for a trial). */
    public function blocks(): bool
    {
        $s = $this->load();

        return $s['open_until'] > 0 && time() < $s['open_until'];
    }

    public function openSince(): ?int
    {
        $s = $this->load();

        return $s['open_until'] > 0 ? $s['open_until'] - $s['cooldown'] : null;
    }

    /** Whether to time this write (every 20th on average, and always the trial). */
    public function shouldSample(): bool
    {
        $s = $this->load();

        return $s['open_until'] > 0 || random_int(1, self::SAMPLE_EVERY) === 1;
    }

    public function success(?float $ms): void
    {
        if ($this->blocks()) {
            return; // bookkeeping while paused (sealing a change set) is no trial
        }

        $s = $this->load();

        if ($s['open_until'] > 0) {
            // The trial after the cooldown went through: record again.
            $this->save(self::fresh(), true);
            $this->gaps->closeGlobal(self::GAP_REASON);

            return;
        }

        if ($ms === null) {
            return;
        }

        $s['samples'] = array_slice([...$s['samples'], (int) round($ms)], -self::SAMPLES);
        $sorted = $s['samples'];
        sort($sorted);

        if (count($sorted) >= self::SAMPLES && $sorted[intdiv(self::SAMPLES, 2)] > self::SLOW_MS) {
            $this->open($s, 'slow');

            return;
        }

        $this->save($s, time() - $s['saved'] >= self::SAVE_SAMPLES_EVERY);
    }

    public function failure(): void
    {
        if ($this->blocks()) {
            return;
        }

        $s = $this->load();
        $now = time();

        if ($s['open_until'] > 0) {
            $this->open($s, 'failures'); // the trial failed

            return;
        }

        $s['failures'] = array_values(array_filter([...$s['failures'], $now], static fn (int $t): bool => $t > $now - self::WINDOW));

        if (count($s['failures']) >= self::FAILURES) {
            $this->open($s, 'failures');

            return;
        }

        $this->save($s, true);
    }

    /**
     * @param array{failures: list<int>, samples: list<int>, open_until: int, cooldown: int, saved: int} $s
     */
    private function open(array $s, string $why): void
    {
        $cooldown = $s['open_until'] > 0 ? min(self::MAX_COOLDOWN, $s['cooldown'] * 2) : self::COOLDOWN;
        $this->save(['failures' => [], 'samples' => [], 'open_until' => time() + $cooldown, 'cooldown' => $cooldown, 'saved' => 0], true);
        $this->gaps->openGlobal(self::GAP_REASON);

        /**
         * Recording of history was paused because writing it failed repeatedly or was slow.
         *
         * @param string $why "failures" or "slow".
         */
        do_action('selective_undo/circuit_opened', $why);
    }

    /**
     * @return array{failures: list<int>, samples: list<int>, open_until: int, cooldown: int, saved: int}
     */
    private function load(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $raw = wp_using_ext_object_cache() ? wp_cache_get(self::KEY, 'selective_undo') : get_option(self::KEY);
        $s = self::fresh();

        if (is_array($raw)) {
            $s['failures'] = array_values(array_map('intval', (array) ($raw['failures'] ?? [])));
            $s['samples'] = array_values(array_map('intval', (array) ($raw['samples'] ?? [])));
            $s['open_until'] = (int) ($raw['open_until'] ?? 0);
            $s['cooldown'] = (int) ($raw['cooldown'] ?? self::COOLDOWN);
            $s['saved'] = (int) ($raw['saved'] ?? 0);
        }

        return $this->state = $s;
    }

    /**
     * @param array{failures: list<int>, samples: list<int>, open_until: int, cooldown: int, saved: int} $s
     */
    private function save(array $s, bool $persist): void
    {
        $this->state = $s;

        if (!$persist) {
            return;
        }

        $s['saved'] = time();
        $this->state = $s;
        wp_using_ext_object_cache() ? wp_cache_set(self::KEY, $s, 'selective_undo') : update_option(self::KEY, $s, false);
    }

    /** Forgets the state read in this request (long-running processes, tests). */
    public function reset(): void
    {
        $this->state = null;
    }

    /**
     * @return array{failures: list<int>, samples: list<int>, open_until: int, cooldown: int, saved: int}
     */
    private static function fresh(): array
    {
        return ['failures' => [], 'samples' => [], 'open_until' => 0, 'cooldown' => self::COOLDOWN, 'saved' => 0];
    }
}
