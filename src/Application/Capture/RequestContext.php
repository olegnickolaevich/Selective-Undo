<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Capture;

/**
 * Per-request capture state: request UUID, source, sequence counter,
 * active explicit operation and changesets opened by this request.
 */
final class RequestContext
{
    private ?string $uuid = null;
    private ?Source $source = null;
    private int $sequence = 0;

    /** @var array{id: int, uuid: string, depth: int}|null */
    private ?array $operation = null;

    /** @var array<string, int> */
    private array $changesets = [];

    public function __construct(private readonly SourceDetector $detector)
    {
    }

    public function requestUuid(): string
    {
        return $this->uuid ??= wp_generate_uuid4();
    }

    public function source(): Source
    {
        return $this->source ??= $this->detector->detect();
    }

    /** Overrides detection (WP-CLI commands, integrations that know better, tests). */
    public function setSource(?Source $source): void
    {
        $this->source = $source;
    }

    /** Reserved when a capture frame opens, so nested writes keep journal order. */
    public function reserveSequence(): int
    {
        return ++$this->sequence;
    }

    /**
     * @return array{id: int, uuid: string, depth: int}|null
     */
    public function operation(): ?array
    {
        return $this->operation;
    }

    /**
     * @param array{id: int, uuid: string, depth: int}|null $operation
     */
    public function setOperation(?array $operation): void
    {
        $this->operation = $operation;
    }

    public function changesetId(string $key): ?int
    {
        return $this->changesets[$key] ?? null;
    }

    public function rememberChangeset(string $key, int $id): void
    {
        $this->changesets[$key] = $id;
    }

    public function forgetChangeset(string $key): void
    {
        unset($this->changesets[$key]);
    }

    /**
     * @return array<string, int>
     */
    public function changesets(): array
    {
        return $this->changesets;
    }

    /** Starts a new logical request (WP-CLI loops, tests). */
    public function reset(): void
    {
        $this->uuid = null;
        $this->source = null;
        $this->sequence = 0;
        $this->operation = null;
        $this->changesets = [];
    }
}
