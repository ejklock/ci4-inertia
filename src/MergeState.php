<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use InvalidArgumentException;

/**
 * The merge mode and the item fields the client dedupes array items on, shared by `MergeProp`
 * and `DeferProp`. An instance is always valid: the match fields are a list of non-blank
 * strings, checked on construction.
 *
 * @internal Not part of the package's public API.
 */
final readonly class MergeState
{
    /**
     * @var list<string>
     */
    public array $matchFields;

    /**
     * @param array<array-key, mixed> $matchFields
     *
     * @throws InvalidArgumentException When the fields are not a list, or one is not a string or is empty or whitespace only.
     */
    public function __construct(
        public MergeMode $mode = MergeMode::Append,
        array $matchFields = [],
    ) {
        if (! array_is_list($matchFields)) {
            throw new InvalidArgumentException('Merge match fields must be a list.');
        }

        $valid = [];

        foreach ($matchFields as $field) {
            if (! is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException('A merge match field must be a string that is not empty or whitespace only.');
            }

            $valid[] = $field;
        }

        $this->matchFields = $valid;
    }

    public function withMode(MergeMode $mode): self
    {
        return new self($mode, $this->matchFields);
    }

    /**
     * @param array<array-key, mixed> $matchFields
     *
     * @throws InvalidArgumentException When the fields are not a valid list.
     */
    public function withMatchFields(array $matchFields): self
    {
        return new self($this->mode, $matchFields);
    }
}
