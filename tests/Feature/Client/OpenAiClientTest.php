<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Unified\AiCore\Client\AiException;
use Unified\AiCore\Client\Input;
use Unified\AiCore\Client\JsonSchema;
use Unified\AiCore\Client\OpenAiClient;
use Unified\AiCore\Testing\OpenAiFake;
use Unified\AiCore\Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    private function client(): OpenAiClient
    {
        return app(OpenAiClient::class);
    }

    public function test_chat_sends_model_tools_and_reasoning_effort_and_parses_tool_calls(): void
    {
        OpenAiFake::chat([OpenAiFake::toolCalls(['call_1' => ['echo', ['text' => 'hi']]], promptTokens: 12, completionTokens: 4)]);

        $completion = $this->client()->chat(
            [['role' => 'user', 'content' => 'hi']],
            [['type' => 'function', 'function' => ['name' => 'echo']]],
        );

        $this->assertTrue($completion->hasToolCalls());
        $this->assertSame('call_1', $completion->toolCalls[0]->id);
        $this->assertSame(['text' => 'hi'], $completion->toolCalls[0]->args());
        $this->assertSame(12, $completion->promptTokens);
        $this->assertSame(4, $completion->completionTokens);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request['model'] === 'gpt-5.1'
                && $request['reasoning_effort'] === 'none'
                && $request['tools'][0]['function']['name'] === 'echo';
        });
    }

    public function test_empty_reasoning_effort_and_no_tools_are_omitted(): void
    {
        config()->set('ai.openai.reasoning_effort', '');
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->assertSame('ok', $this->client()->chat([['role' => 'user', 'content' => 'x']])->content);

        Http::assertSent(fn (Request $request): bool => ! isset($request['reasoning_effort']) && ! isset($request['tools']));
    }

    public function test_base_url_without_scheme_gets_https(): void
    {
        config()->set('ai.openai.base_url', 'api.openai.test/v1/');
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->client()->chat([['role' => 'user', 'content' => 'x']]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_fails_closed_without_an_api_key(): void
    {
        config()->set('ai.openai.api_key', null);

        $this->assertFalse($this->client()->isConfigured());
        $this->assertNull($this->client()->realtimeClientSecret());

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame(AiException::NOT_CONFIGURED, $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_disabled_flag_also_fails_closed(): void
    {
        config()->set('ai.enabled', false);

        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_http_errors_map_to_reasons(): void
    {
        $cases = [429 => AiException::BUSY, 500 => AiException::UNAVAILABLE, 400 => AiException::REJECTED, 408 => AiException::TIMED_OUT];
        $sequence = Http::sequence();
        foreach (array_keys($cases) as $status) {
            $sequence->push(['error' => ['message' => 'nope']], $status);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as $status => $reason) {

            try {
                $this->client()->chat([['role' => 'user', 'content' => 'x']]);
                $this->fail("Expected AiException for {$status}");
            } catch (AiException $e) {
                $this->assertSame($reason, $e->reason, "status {$status}");
                $this->assertStringContainsString('nope', $e->getMessage());
            }
        }
    }

    public function test_respond_sends_strict_schema_and_image_input_and_decodes(): void
    {
        Http::fake(['*/responses' => Http::response(OpenAiFake::response(['title' => 'Run sheet']))]);

        $schema = JsonSchema::object(['title' => ['type' => 'string']]);

        $data = $this->client()->extract('page', $schema, [
            Input::user([Input::text('Read this form'), Input::imageBytes('PNGDATA', 'image/png', 'high')]),
        ], instructions: 'Be exact.');

        $this->assertSame(['title' => 'Run sheet'], $data);

        Http::assertSent(function (Request $request): bool {
            $part = $request['input'][0]['content'][1];

            return $request->url() === 'https://api.openai.test/v1/responses'
                && $request['model'] === 'gpt-5.1'
                && $request['instructions'] === 'Be exact.'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['additionalProperties'] === false
                && $part['type'] === 'input_image'
                && $part['image_url'] === 'data:image/png;base64,'.base64_encode('PNGDATA')
                && $part['detail'] === 'high';
        });
    }

    public function test_respond_surfaces_refusals_and_incomplete_replies(): void
    {
        Http::fake(['*/responses' => Http::sequence()
            ->push(['status' => 'completed', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'cannot']]]]])
            ->push(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []])
            ->push(['status' => 'completed', 'output' => []]),
        ]);

        foreach ([AiException::REFUSED, AiException::INCOMPLETE, AiException::UNREADABLE] as $reason) {
            try {
                $this->client()->respond('x');
                $this->fail("Expected {$reason}");
            } catch (AiException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_structured_data_must_be_an_object(): void
    {
        Http::fake(['*/responses' => Http::response(OpenAiFake::response('[1,2]'))]);

        $this->expectException(AiException::class);

        $this->client()->respond('x')->data();
    }

    public function test_transcribe_posts_multipart_with_whisper_model(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => ' BP 120 over 80 ', 'segments' => [['start' => 0, 'text' => 'BP']], 'duration' => 3.2])]);

        $path = tempnam(sys_get_temp_dir(), 'aud').'.webm';
        file_put_contents($path, 'audio-bytes');

        $transcript = $this->client()->transcribe($path, prompt: 'EMS dictation');
        @unlink($path);

        $this->assertSame('BP 120 over 80', $transcript->text);
        $this->assertSame('whisper-1', $transcript->model);
        $this->assertCount(1, $transcript->segments);
        $this->assertSame(3.2, $transcript->duration);

        Http::assertSent(function (Request $request): bool {
            $names = array_column($request->data(), 'name');

            return $request->isMultipart()
                && in_array('file', $names, true)
                && in_array('model', $names, true)
                && in_array('prompt', $names, true);
        });
    }

    public function test_transcribe_chunks_primes_each_chunk_with_the_previous_tail_from_a_disk(): void
    {
        Storage::fake('audio');
        Storage::disk('audio')->put('a.webm', 'one');
        Storage::disk('audio')->put('b.webm', 'two');

        Http::fake(['*/audio/transcriptions' => Http::sequence()
            ->push(['text' => 'patient found supine'])
            ->push(['text' => 'and alert'])]);

        $transcript = $this->client()->transcribeChunks(['a.webm', 'b.webm'], 'EMS', disk: 'audio');

        $this->assertSame('patient found supine and alert', $transcript->text);

        $prompts = Http::recorded()->map(function (array $pair): ?string {
            foreach ($pair[0]->data() as $field) {
                if ($field['name'] === 'prompt') {
                    return (string) $field['contents'];
                }
            }

            return null;
        })->all();

        $this->assertSame(['EMS', 'EMS ...patient found supine'], array_values($prompts));
        $this->assertSame([], glob(sys_get_temp_dir().'/ai_audio_*') ?: []);
    }

    public function test_realtime_client_secret_uses_captions_model_and_returns_null_on_failure(): void
    {
        Http::fake(['*/realtime/client_secrets' => Http::sequence()
            ->push(['value' => 'ek_123'])
            ->push(['error' => ['message' => 'x']], 500)]);

        $this->assertSame('ek_123', $this->client()->realtimeClientSecret());
        $this->assertNull($this->client()->realtimeClientSecret());

        Http::assertSent(fn (Request $request): bool => $request['session']['audio']['input']['transcription']['model'] === 'gpt-4o-mini-transcribe');
    }
}
