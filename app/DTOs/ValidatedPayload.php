<?php

namespace App\DTOs;

/**
 * The result returned by IntentValidatorService after validating a WritePayload.
 *
 * @property IntentDeclaration[] $flaggedContradictions
 * @property string[] $warnings
 */
readonly class ValidatedPayload
{
    public function __construct(
        /** The corrected payload with overrides applied and invalid intents removed. */
        public WritePayload $payload,
        /** @var IntentDeclaration[] Nodes with CONTRADICT intent pulled out for manual review. */
        public array $flaggedContradictions,
        /** @var string[] Human-readable log messages describing any overrides or rejections. */
        public array $warnings,
    ) {}
}
