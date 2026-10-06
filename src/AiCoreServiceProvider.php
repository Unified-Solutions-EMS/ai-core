<?php

declare(strict_types=1);

namespace Unified\AiCore;

use Illuminate\Support\ServiceProvider;
use Unified\AiCore\Agent\Agent;
use Unified\AiCore\Client\OpenAiClient;
use Unified\AiCore\Console\PruneRunsCommand;
use Unified\AiCore\Ledger\RunRecorder;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Proposal\ProposalService;
use Unified\AiCore\Sop\SopClient;
use Unified\AiCore\Usage\TokenBudget;

class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai.php', 'ai');

        $this->app->singleton(OpenAiClient::class);
        $this->app->singleton(SopClient::class);
        $this->app->singleton(TokenBudget::class);
        $this->app->singleton(PurgeHooks::class);
        $this->app->bind(RunRecorder::class);
        $this->app->bind(Agent::class);
        $this->app->bind(ProposalService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        // Publish-only, not auto-loaded: an app opts into the ledger and
        // proposals tables, and may extend them (HasCompanyScope) first.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'ai-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneRunsCommand::class]);
        }
    }
}
