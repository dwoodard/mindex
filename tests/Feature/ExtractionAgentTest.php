<?php

use App\Services\ExtractionService;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;

// Ensure ExtractionAgent (file-scoped in ExtractionService.php) is autoloaded
beforeEach(fn () => class_exists(ExtractionService::class));

it('targets the lmstudio provider', function (): void {
    $attrs = (new ReflectionClass('App\Services\ExtractionAgent'))
        ->getAttributes(Provider::class);

    expect($attrs)->toHaveCount(1)
        ->and($attrs[0]->newInstance()->value)->toBe('lmstudio');
});

it('targets the gpt-oss-20b model', function (): void {
    $attrs = (new ReflectionClass('App\Services\ExtractionAgent'))
        ->getAttributes(Model::class);

    expect($attrs)->toHaveCount(1)
        ->and($attrs[0]->newInstance()->value)->toBe('openai/gpt-oss-20b');
});
