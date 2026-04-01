<?php

namespace App\DTOs;

use App\Enums\NodeType;
use App\Enums\Origin;
use Carbon\Carbon;

readonly class GraphNode
{
    public function __construct(
        public string $id,
        public string $label,
        public NodeType $type,
        public Origin $origin,
        public float $confidence,
        public Carbon $created_at,
        public Carbon $updated_at,
        public int $mention_count,
        /** @var array<string, mixed> */
        public array $properties,
        public float $decay_rate,
        public bool $anchored,
        public ?Carbon $last_reinforced_at = null,
        public bool $faded = false,
    ) {}
}
