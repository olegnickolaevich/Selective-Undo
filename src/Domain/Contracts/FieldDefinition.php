<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Merge\MergeStrategy;

/**
 * One restorable field as the interface and the planner see it.
 *
 * @api
 */
final readonly class FieldDefinition
{
    /**
     * @param string $key Field key with its namespace: "post_title", "meta:<key>", "tax:<taxonomy>".
     * @param 'text'|'html'|'int'|'int_list'|'json'|'raw'|'attachment' $valueType How values are compared and shown;
     *                                                                     "attachment": a media library ID, shown as an image.
     * @param bool $sensitive Values are never shown in comparisons (only that they changed).
     * @param string|null $group Heading the field is listed under ("SEO", "Fields").
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $valueType,
        public MergeStrategy $merge = MergeStrategy::Replace,
        public bool $sensitive = false,
        public ?string $group = null,
    ) {
    }
}
