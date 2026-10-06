<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use Unified\AiCore\Support\SsoEndpoint;

/**
 * POSTs a PHI-free index row for one run to SSO's
 * /api/internal/ai-runs/register so the SOP page can list executions
 * across apps. SSO upserts by (app_slug, run_id), so the job is sent
 * again when a run is approved or executed.
 *
 * Best-effort, same contract as the sso-client metrics job: one try, every
 * failure logged and swallowed, because on a sync queue this runs inside
 * the request. A 404 (SSO not yet serving the route) is expected while
 * the index is rolled out and is logged at debug only.
 */
class RegisterRunWithSso implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array{app_slug: string, domain: string, run_id: string, company_sso_id: int|string|null, sop_version_id: int|null, summary: string, outcome: string, approved_by_sso_id: int|string|null, created_at: string|null}  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $url = SsoEndpoint::url('/api/internal/ai-runs/register');
        $token = SsoEndpoint::token();

        if ($url === null || $token === '') {
            Log::debug('AI run not registered with SSO: missing base URL or key', ['run_id' => $this->payload['run_id']]);

            return;
        }

        try {
            $response = SsoEndpoint::request()->post($url, $this->payload);
        } catch (Throwable $e) {
            Log::warning('AI run registration failed', ['run_id' => $this->payload['run_id'], 'error' => $e->getMessage()]);

            return;
        }

        if ($response->status() === 404) {
            Log::debug('AI run registration endpoint not found on SSO', ['run_id' => $this->payload['run_id']]);

            return;
        }

        if ($response->failed()) {
            Log::warning('AI run registration rejected', ['run_id' => $this->payload['run_id'], 'status' => $response->status()]);
        }
    }
}
