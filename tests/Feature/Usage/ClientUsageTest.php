<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Usage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Unified\AiCore\Client\AiException;
use Unified\AiCore\Testing\OpenAiFake;
use Unified\AiCore\Tests\Fixtures\SpyUsageRecorder;
use Unified\AiCore\Usage\UsageRecorder;
use Unified\AiCore\Usage\UsageSample;
use Unified\AiCore\Usage\UsageScope;

class ClientUsageTest extends UsageTestCase
{
    public function test_chat_records_one_sample_with_tokens_cached_tokens_and_reply_model(): void
    {
        $reply = OpenAiFake::message('ok', promptTokens: 120, completionTokens: 30);
        $reply['model'] = 'gpt-5.1-2026-09-01';
        $reply['usage']['prompt_tokens_details'] = ['cached_tokens' => 64];
        OpenAiFake::chat([$reply]);

        $this->client()->chat([['role' => 'user', 'content' => 'x']]);

        $sample = $this->onlySample();
        $this->assertSame('unscoped-chat', $sample->feature);
        $this->assertSame('chat', $sample->purpose);
        $this->assertSame('gpt-5.1-2026-09-01', $sample->model);
        $this->assertSame(120, $sample->inputTokens);
        $this->assertSame(30, $sample->outputTokens);
        $this->assertSame(64, $sample->cachedTokens);
        $this->assertNull($sample->audioSeconds);
        $this->assertSame('ok', $sample->outcome);
        $this->assertIsInt($sample->durationMs);
        $this->assertGreaterThanOrEqual(0, $sample->durationMs);
    }

    public function test_respond_and_extract_record_extraction_samples_with_response_usage(): void
    {
        $reply = OpenAiFake::response(['title' => 'Run sheet'], inputTokens: 900, outputTokens: 40);
        $reply['usage']['input_tokens_details'] = ['cached_tokens' => 512];
        Http::fake(['*/responses' => Http::response($reply)]);

        $this->client()->respond('Read this');
        $this->client()->extract('form', ['type' => 'object'], 'Read this');

        $this->assertCount(2, $this->spy->samples);

        foreach ($this->spy->samples as $sample) {
            $this->assertSame('extraction', $sample->purpose);
            $this->assertSame('unscoped-extraction', $sample->feature);
            $this->assertSame('gpt-fake', $sample->model);
            $this->assertSame(900, $sample->inputTokens);
            $this->assertSame(40, $sample->outputTokens);
            $this->assertSame(512, $sample->cachedTokens);
            $this->assertSame('ok', $sample->outcome);
        }
    }

    public function test_reply_without_a_model_falls_back_to_the_requested_model(): void
    {
        $reply = OpenAiFake::response('plain');
        unset($reply['model']);
        Http::fake(['*/responses' => Http::response($reply)]);

        $this->client()->respond('x', model: 'gpt-requested');

        $this->assertSame('gpt-requested', $this->onlySample()->model);
    }

    public function test_transcribe_records_audio_seconds(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => 'BP 120 over 80', 'duration' => 42.5])]);

        $path = tempnam(sys_get_temp_dir(), 'aud').'.webm';
        file_put_contents($path, 'audio-bytes');

        try {
            $this->client()->transcribe($path);
        } finally {
            @unlink($path);
        }

        $sample = $this->onlySample();
        $this->assertSame('transcription', $sample->purpose);
        $this->assertSame('unscoped-transcription', $sample->feature);
        $this->assertSame('whisper-1', $sample->model);
        $this->assertSame(42.5, $sample->audioSeconds);
        $this->assertSame(0, $sample->inputTokens);
        $this->assertSame('ok', $sample->outcome);
    }

    public function test_transcribe_chunks_records_one_sample_per_chunk(): void
    {
        Storage::fake('audio');
        Storage::disk('audio')->put('a.webm', 'one');
        Storage::disk('audio')->put('b.webm', 'two');

        Http::fake(['*/audio/transcriptions' => Http::sequence()
            ->push(['text' => 'patient found supine', 'duration' => 10.0])
            ->push(['text' => 'and alert', 'duration' => 4.0])]);

        $this->client()->transcribeChunks(['a.webm', 'b.webm'], 'EMS', disk: 'audio');

        $this->assertSame([10.0, 4.0], array_map(fn (UsageSample $s): ?float => $s->audioSeconds, $this->spy->samples));
    }

    public function test_realtime_client_secret_records_a_captions_sample_on_success_and_failure(): void
    {
        Http::fake(['*/realtime/client_secrets' => Http::sequence()
            ->push(['value' => 'ek_123'])
            ->push(['error' => ['message' => 'bad']], 400)
            ->push(['nothing' => true])]);

        $this->assertSame('ek_123', $this->client()->realtimeClientSecret());
        $this->assertNull($this->client()->realtimeClientSecret());
        $this->assertNull($this->client()->realtimeClientSecret());

        $this->assertSame(['captions', 'captions', 'captions'], array_map(fn (UsageSample $s): string => $s->purpose, $this->spy->samples));
        $this->assertSame(['ok', 'rejected', 'error'], array_map(fn (UsageSample $s): string => $s->outcome, $this->spy->samples));
        $this->assertSame('gpt-4o-mini-transcribe', $this->spy->samples[0]->model);
        $this->assertSame('unscoped-captions', $this->spy->samples[0]->feature);
    }

    public function test_http_failures_record_their_outcome_and_still_throw(): void
    {
        $cases = [429 => 'busy', 500 => 'error', 400 => 'rejected', 408 => 'timeout'];
        $sequence = Http::sequence();
        foreach (array_keys($cases) as $status) {
            $sequence->push(['error' => ['message' => 'nope']], $status);
        }
        Http::fake(['*' => $sequence]);

        foreach (array_keys($cases) as $status) {
            try {
                $this->client()->chat([['role' => 'user', 'content' => 'x']], model: 'gpt-asked');
                $this->fail("Expected AiException for {$status}");
            } catch (AiException) {
            }
        }

        $this->assertSame(array_values($cases), array_map(fn (UsageSample $s): string => $s->outcome, $this->spy->samples));
        $this->assertSame('gpt-asked', $this->spy->samples[0]->model);
        $this->assertSame(0, $this->spy->samples[0]->inputTokens);
    }

    public function test_connection_timeout_records_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(AiException::class);

        try {
            $this->client()->respond('x');
        } finally {
            $this->assertSame('timeout', $this->onlySample()->outcome);
            $this->assertSame('extraction', $this->onlySample()->purpose);
        }
    }

    public function test_refused_incomplete_and_unreadable_replies_record_outcomes_with_the_tokens_spent(): void
    {
        $refusal = OpenAiFake::response('x', inputTokens: 70, outputTokens: 7);
        $refusal['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'I cannot help with that.']];
        $incomplete = OpenAiFake::response('x');
        $incomplete['status'] = 'incomplete';

        Http::fake(['*/responses' => Http::sequence()
            ->push($refusal)
            ->push($incomplete)
            ->push(OpenAiFake::response('[1,2]'))]);

        foreach ([fn () => $this->client()->respond('x'), fn () => $this->client()->respond('x'), fn () => $this->client()->extract('s', ['type' => 'object'], 'x')] as $call) {
            try {
                $call();
                $this->fail('Expected AiException');
            } catch (AiException) {
            }
        }

        $this->assertSame(['refused', 'error', 'error'], array_map(fn (UsageSample $s): string => $s->outcome, $this->spy->samples));
        $this->assertSame(70, $this->spy->samples[0]->inputTokens);
        $this->assertSame(7, $this->spy->samples[0]->outputTokens);
    }

    public function test_a_call_refused_for_want_of_configuration_is_not_recorded(): void
    {
        config()->set('ai.openai.api_key', null);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame(AiException::NOT_CONFIGURED, $e->reason);
        }

        $this->assertNull($this->client()->realtimeClientSecret());
        $this->assertSame([], $this->spy->samples);
    }

    public function test_the_current_scope_is_stamped_on_the_sample(): void
    {
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        UsageScope::within(
            ['feature' => 'run-explain', 'local_company_id' => 7, 'local_user_id' => 3, 'sso_company_id' => '107', 'sso_user_id' => 900, 'surface' => 'web'],
            fn () => $this->client()->chat([['role' => 'user', 'content' => 'x']]),
        );

        $sample = $this->onlySample();
        $this->assertSame('run-explain', $sample->feature);
        $this->assertSame('web', $sample->surface);
        $this->assertSame(
            ['local_company_id' => 7, 'local_user_id' => 3, 'sso_company_id' => 107, 'sso_user_id' => 900],
            $sample->attribution(),
        );
    }

    public function test_a_failing_recorder_never_reaches_the_caller(): void
    {
        $this->app->instance(UsageRecorder::class, new class implements UsageRecorder
        {
            public function record(UsageSample $sample): void
            {
                throw new RuntimeException('metrics down');
            }
        });
        OpenAiFake::chat([OpenAiFake::message('still answered')]);

        $this->assertSame('still answered', $this->client()->chat([['role' => 'user', 'content' => 'x']])->content);
    }

    public function test_recording_is_off_under_unit_tests_unless_enabled(): void
    {
        config()->set('ai.usage.enabled', null);
        OpenAiFake::chat([OpenAiFake::message('a'), OpenAiFake::message('b'), OpenAiFake::message('c')]);

        $this->client()->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertSame([], $this->spy->samples, 'null config means off while running unit tests');

        config()->set('ai.usage.enabled', false);
        $this->client()->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertSame([], $this->spy->samples);

        config()->set('ai.usage.enabled', true);
        $this->client()->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertCount(1, $this->spy->samples);
    }

    public function test_the_package_default_config_leaves_usage_on_outside_tests(): void
    {
        $config = require __DIR__.'/../../../config/ai.php';

        $this->assertArrayHasKey('usage', $config);
        $this->assertNotFalse($config['usage']['enabled']);
        $this->assertInstanceOf(SpyUsageRecorder::class, app(UsageRecorder::class));
    }
}
