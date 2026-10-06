<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Ledger;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunRecorder;
use Unified\AiCore\Tests\Fixtures\User;
use Unified\AiCore\Tests\TestCase;

class RegisterRunWithSsoTest extends TestCase
{
    private function payload(): array
    {
        return [
            'app_slug' => 'testapp',
            'domain' => 'cad.dispatch',
            'run_id' => 'uuid-1',
            'company_sso_id' => '107',
            'sop_version_id' => 55,
            'summary' => 'Proposed 1 change',
            'outcome' => 'completed',
            'approved_by_sso_id' => null,
            'created_at' => '2026-10-06T10:00:00+00:00',
        ];
    }

    public function test_posts_the_index_row_with_the_core_api_key(): void
    {
        Http::fake(['sso.test/*' => Http::response(['ok' => true])]);

        (new RegisterRunWithSso($this->payload()))->handle();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sso.test/api/internal/ai-runs/register'
            && $request->hasHeader('Authorization', 'Bearer core-key')
            && $request['run_id'] === 'uuid-1'
            && $request['sop_version_id'] === 55
            && ! isset($request['input_snapshot'])
            && ! isset($request['explanation']));
    }

    public function test_a_missing_sso_endpoint_is_tolerated_quietly(): void
    {
        Http::fake(['sso.test/*' => Http::response([], 404)]);
        $log = Log::spy();

        (new RegisterRunWithSso($this->payload()))->handle();

        $log->shouldNotHaveReceived('warning');
        $log->shouldHaveReceived('debug')->once();
    }

    public function test_failures_are_swallowed_and_logged(): void
    {
        Http::fake(['sso.test/*' => Http::response([], 500)]);
        $log = Log::spy();

        (new RegisterRunWithSso($this->payload()))->handle();

        $log->shouldHaveReceived('warning')->once();
    }

    public function test_connection_errors_are_swallowed(): void
    {
        Http::fake(['sso.test/*' => fn () => throw new ConnectionException('refused')]);
        $log = Log::spy();

        (new RegisterRunWithSso($this->payload()))->handle();

        $log->shouldHaveReceived('warning')->once();
    }

    public function test_falls_back_to_sso_client_base_url_and_skips_without_one(): void
    {
        config()->set('ai.sso.base_url', null);
        config()->set('sso.base_url', 'https://sso-client.test');
        Http::fake(['sso-client.test/*' => Http::response([])]);

        (new RegisterRunWithSso($this->payload()))->handle();
        Http::assertSentCount(1);

        config()->set('sso.base_url', null);
        (new RegisterRunWithSso($this->payload()))->handle();
        Http::assertSentCount(1);
    }

    public function test_recorder_resolves_the_approvers_sso_id(): void
    {
        Http::fake(['sso.test/*' => Http::response([])]);
        $user = User::query()->create(['name' => 'Dana', 'sso_id' => '9001']);
        $run = Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => 7, 'company_sso_id' => '107', 'status' => 'completed', 'summary' => 'Proposed 1 change']);

        app(RunRecorder::class)->markApproved($run, $user->id);

        Http::assertSent(fn (Request $request): bool => $request['approved_by_sso_id'] === '9001' && $request['outcome'] === 'approved');
    }

    public function test_registration_can_be_switched_off(): void
    {
        config()->set('ai.ledger.register_with_sso', false);
        Http::fake();
        $run = Run::query()->create(['domain' => 'cad.dispatch', 'status' => 'completed']);

        app(RunRecorder::class)->register($run);

        Http::assertNothingSent();
    }
}
