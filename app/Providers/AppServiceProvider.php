<?php

namespace App\Providers;

use App\Services\Contracts\GraphServiceInterface;
use App\Services\GraphService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientInterface::class, function () {
            return ClientBuilder::create()
                ->withDriver(
                    'default',
                    config('mindex.neo4j.uri'),
                    Authenticate::basic(
                        config('mindex.neo4j.username'),
                        config('mindex.neo4j.password'),
                    ),
                )
                ->withDefaultDriver('default')
                ->build();
        });

        $this->app->bind(
            GraphServiceInterface::class,
            GraphService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
