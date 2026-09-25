<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Domain\Contracts\AdapterInterface;

/**
 * Registry of adapters. Core adapters are registered first, then integrations
 * receive the registry through the selective_undo/register_adapters action.
 */
final class AdapterRegistry
{
    /** @var array<string, AdapterInterface> */
    private array $adapters = [];
    private bool $initialized = false;

    /**
     * @param \Closure(self): void $registerCore
     */
    public function __construct(private readonly \Closure $registerCore)
    {
    }

    public function register(AdapterInterface $adapter): void
    {
        $key = $adapter->key();

        if (!preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#', $key)) {
            throw new \InvalidArgumentException('Adapter keys look like "vendor/name".');
        }

        if (isset($this->adapters[$key])) {
            throw new \LogicException('Adapter already registered: ' . $key);
        }

        $this->adapters[$key] = $adapter;
    }

    public function find(string $key): ?AdapterInterface
    {
        $this->initialize();

        return $this->adapters[$key] ?? null;
    }

    /**
     * @return array<string, AdapterInterface>
     */
    public function all(): array
    {
        $this->initialize();

        return $this->adapters;
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        ($this->registerCore)($this);

        /**
         * Register additional adapters.
         *
         * @param AdapterRegistry $registry
         */
        do_action('selective_undo/register_adapters', $this);
    }
}
