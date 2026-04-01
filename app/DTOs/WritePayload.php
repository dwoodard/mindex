<?php

namespace App\DTOs;

/**
 * The full structured output returned by the AI extraction call.
 *
 * @property GraphNodeDraft[] $nodes
 * @property GraphEdgeDraft[] $edges
 * @property IntentDeclaration[] $intents
 * @property string[]|null $open_questions
 */
readonly class WritePayload
{
    public function __construct(
        /** @var GraphNodeDraft[] */
        public array $nodes,
        /** @var GraphEdgeDraft[] */
        public array $edges,
        /** @var IntentDeclaration[] */
        public array $intents,
        public string $reply = '',
        public ?string $mood = null,
        /** @var string[]|null */
        public ?array $open_questions = null,
    ) {}
}
