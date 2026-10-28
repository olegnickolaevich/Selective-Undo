<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\CapturingAdapter;
use SelectiveUndo\Domain\Contracts\DescribesFields;

/**
 * Registry of adapters. Core adapters are registered first, then integrations
 * receive the registry through the selective_undo/register_adapters action.
 */
final class AdapterRegistry
{
    /**
     * Restore order within one object's transaction, which is also the lock order:
     * the post row is created first, then its fields, meta and terms. Others: 100.
     */
    private const PRIORITY = [
        'core/post-deletion' => 5,
        'core/post-fields' => 10,
        'core/post-meta' => 20,
        'core/terms' => 30,
    ];

    /** @var array<string, AdapterInterface> */
    private array $adapters = [];
    private bool $initialized = false;
    private bool $capturing = false;

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

    /**
     * Translated label of a field key of any adapter ("Title", "Featured image", "Tags").
     * With the object, the label is the one its adapter describes for that kind of item
     * (a media file's excerpt is its "Caption").
     */
    public function fieldLabel(string $field, ?string $adapterKey = null, ?ObjectRef $object = null): string
    {
        $adapter = $adapterKey !== null ? $this->find($adapterKey) : null;

        if ($object !== null) {
            foreach ($adapter !== null ? [$adapter] : $this->all() as $candidate) {
                if (!$candidate instanceof DescribesFields) {
                    continue;
                }

                foreach ($candidate->fields($object) as $definition) {
                    if ($definition->key === $field) {
                        return $definition->group !== null ? $definition->group . ': ' . $definition->label : $definition->label;
                    }
                }
            }
        }

        if ($adapter !== null) {
            return $adapter->fieldLabel($field);
        }

        foreach ($this->all() as $candidate) {
            $label = $candidate->fieldLabel($field);

            if ($label !== $field) {
                return $label;
            }
        }

        return $field;
    }

    public function priority(string $key): int
    {
        return self::PRIORITY[$key] ?? 100;
    }

    /**
     * Lets every capturing adapter hook into WordPress. Once per request, after
     * integrations had the chance to register their adapters.
     */
    public function registerCapture(CaptureSink $sink): void
    {
        if ($this->capturing) {
            return;
        }

        $this->capturing = true;

        foreach ($this->all() as $adapter) {
            if ($adapter instanceof CapturingAdapter) {
                $adapter->registerCapture($sink);
            }
        }
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
