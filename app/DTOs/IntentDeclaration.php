<?php

namespace App\DTOs;

use App\Enums\WriteIntent;

/**
 * The AI's declared intent for a single node in the payload.
 * Laravel validates this before any write occurs.
 */
readonly class IntentDeclaration
{
    public function __construct(
        public string $node_id,
        public WriteIntent $intent,
        public ?string $replaces_id = null,
        public ?string $reason = null,
    ) {}
}
