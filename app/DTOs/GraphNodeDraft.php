<?php

namespace App\DTOs;

use App\Enums\NodeType;
use App\Enums\Origin;

/**
 * What the AI returns for a node before Laravel validates and writes it.
 */
readonly class GraphNodeDraft
{
    public function __construct(
        public string $id,
        public string $label,
        public NodeType $type,
        public Origin $origin,
        public float $confidence,
        public float $decay_rate = 0.02,
        public bool $anchored = false,
        /** @var array<string, mixed> */
        public array $properties = [],
    ) {}
}
