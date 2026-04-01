<?php

use App\Services\Contracts\GraphServiceInterface;
use App\Services\GraphService;
use Laudis\Neo4j\Contracts\ClientInterface;

it('resolves GraphServiceInterface to GraphService', function (): void {
    $mock = Mockery::mock(ClientInterface::class);
    $this->app->instance(ClientInterface::class, $mock);

    $service = $this->app->make(GraphServiceInterface::class);

    expect($service)->toBeInstanceOf(GraphService::class);
});

it('binds Neo4j ClientInterface as a singleton', function (): void {
    $mock = Mockery::mock(ClientInterface::class);
    $this->app->instance(ClientInterface::class, $mock);

    $a = $this->app->make(ClientInterface::class);
    $b = $this->app->make(ClientInterface::class);

    expect($a)->toBe($b);
});

it('builds Neo4j client from mindex config', function (): void {
    config([
        'mindex.neo4j.uri' => 'bolt://localhost:7687',
        'mindex.neo4j.username' => 'neo4j',
        'mindex.neo4j.password' => 'test',
    ]);

    $this->app->forgetInstance(ClientInterface::class);

    $client = $this->app->make(ClientInterface::class);

    expect($client)->toBeInstanceOf(ClientInterface::class);
});
