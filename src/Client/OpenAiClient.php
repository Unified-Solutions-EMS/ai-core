<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Unified\AiCore\Usage\CallUsage;
use Unified\AiCore\Usage\UsageRecorder;
use Unified\AiCore\Usage\UsageSample;
use Unified\AiCore\Usage\UsageScope;

/**
 * The platform's one client for the model vendor (OpenAI, under the
 * signed BAA).
 *
 * A thin wrapper over Laravel's HTTP client rather than a vendor SDK: the
 * calls are a handful of POSTs with stable shapes, an SDK's typed replies
 * reject output item types newer than the installed version, and
 * Http::fake() covers every call in tests so nothing reaches the network.
 *
 * Fails closed: with no API key isConfigured() is false and every call
 * throws AiException::notConfigured() instead of sending anything.
 *
 * Every call that reaches the vendor records exactly one UsageSample
 * (the `ai.request` metric) through the bound UsageRecorder: on success
 * and on every failure, with the purpose, model, tokens, audio seconds,
 * duration and outcome, stamped with the current UsageScope. Recording
 * is best-effort and never throws into the caller. A call refused for
 * want of configuration never left the process and is not recorded.
 */
class OpenAiClient
{
    public const PURPOSE_CHAT = 'chat';

    public const PURPOSE_EXTRACTION = 'extraction';

    public const PURPOSE_TRANSCRIPTION = 'transcription';

    public const PURPOSE_CAPTIONS = 'captions';

    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled', true) && filled(config('ai.openai.api_key'));
    }

    public function model(string $purpose): string
    {
        return (string) config("ai.openai.models.{$purpose}", config('ai.openai.models.chat'));
    }

    /**
     * POST /chat/completions with optional function tools.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options  extra body keys (temperature, tool_choice, ...)
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $model = null,
        ?string $reasoningEffort = null,
        array $options = [],
    ): ChatCompletion {
        $model ??= $this->model(self::PURPOSE_CHAT);
        $effort = $reasoningEffort ?? config('ai.openai.reasoning_effort');

        $body = ['model' => $model, 'messages' => $messages, ...$options];

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if (is_string($effort) && $effort !== '') {
            $body['reasoning_effort'] = $effort;
        }

        return $this->metered(self::PURPOSE_CHAT, $model, function (CallUsage $usage) use ($body, $model): ChatCompletion {
            $response = $this->timedSend($usage, fn (PendingRequest $http): Response => $http->asJson()->post($this->url('chat/completions'), $body));

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $completion = ChatCompletion::fromResponse($json, $model);

            $usage->model = $completion->model;
            $usage->inputTokens = $completion->promptTokens;
            $usage->outputTokens = $completion->completionTokens;
            $usage->cachedTokens = (int) ($json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);

            return $completion;
        });
    }

    /**
     * POST /responses. Pass a strict schema format (JsonSchema::format())
     * to hold the reply to a shape; image inputs go in through Input.
     *
     * @param  list<array<string, mixed>>|string  $input
     * @param  array<string, mixed>|null  $format
     * @param  array<string, mixed>  $options
     */
    public function respond(
        array|string $input,
        ?array $format = null,
        ?string $instructions = null,
        ?string $model = null,
        ?string $reasoningEffort = null,
        ?int $timeoutSeconds = null,
        array $options = [],
    ): StructuredResponse {
        $model ??= $this->model(self::PURPOSE_EXTRACTION);
        $body = $this->responsesBody($input, $format, $instructions, $model, $reasoningEffort, $options);

        return $this->metered(
            self::PURPOSE_EXTRACTION,
            $model,
            fn (CallUsage $usage): StructuredResponse => $this->sendResponses($usage, $body, $model, $timeoutSeconds),
        );
    }

    /**
     * Strict structured output in one call: returns the decoded object.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array<string, mixed>>|string  $input
     * @return array<string, mixed>
     */
    public function extract(string $schemaName, array $schema, array|string $input, ?string $instructions = null, ?string $model = null, ?int $timeoutSeconds = null): array
    {
        $model ??= $this->model(self::PURPOSE_EXTRACTION);
        $body = $this->responsesBody($input, JsonSchema::format($schemaName, $schema), $instructions, $model, null, []);

        // data() runs inside the metered call so an unreadable reply is
        // recorded as a failure rather than a success.
        return $this->metered(
            self::PURPOSE_EXTRACTION,
            $model,
            fn (CallUsage $usage): array => $this->sendResponses($usage, $body, $model, $timeoutSeconds)->data(),
        );
    }

    /**
     * @param  list<array<string, mixed>>|string  $input
     * @param  array<string, mixed>|null  $format
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function responsesBody(array|string $input, ?array $format, ?string $instructions, string $model, ?string $reasoningEffort, array $options): array
    {
        $body = ['model' => $model, 'input' => $input, ...$options];

        if ($instructions !== null) {
            $body['instructions'] = $instructions;
        }

        if ($format !== null) {
            $body['text'] = ['format' => $format];
        }

        if ($reasoningEffort !== null && $reasoningEffort !== '') {
            $body['reasoning'] = ['effort' => $reasoningEffort];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendResponses(CallUsage $usage, array $body, string $model, ?int $timeoutSeconds): StructuredResponse
    {
        $response = $this->timedSend(
            $usage,
            fn (PendingRequest $http): Response => $http->asJson()->post($this->url('responses'), $body),
            $timeoutSeconds,
        );

        /** @var array<string, mixed> $reply */
        $reply = $response->json() ?? [];

        $usage->model = is_string($reply['model'] ?? null) ? $reply['model'] : $model;
        $usage->inputTokens = (int) ($reply['usage']['input_tokens'] ?? 0);
        $usage->outputTokens = (int) ($reply['usage']['output_tokens'] ?? 0);
        $usage->cachedTokens = (int) ($reply['usage']['input_tokens_details']['cached_tokens'] ?? 0);

        foreach ($this->contentParts($reply) as $part) {
            if (($part['type'] ?? null) === 'refusal') {
                throw AiException::refused(mb_substr((string) ($part['refusal'] ?? ''), 0, 300));
            }
        }

        $status = $reply['status'] ?? 'completed';

        if ($status === 'incomplete') {
            throw AiException::incomplete((string) json_encode($reply['incomplete_details'] ?? null));
        }

        if ($status === 'failed') {
            throw AiException::unavailable('reply failed: '.json_encode($reply['error'] ?? null));
        }

        $text = $this->outputText($reply);

        if ($text === null) {
            throw AiException::unreadable('no text in reply (status '.json_encode($status).')');
        }

        return new StructuredResponse(
            text: $text,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            model: $usage->model,
        );
    }

    /**
     * Whisper transcription of one local audio file.
     */
    public function transcribe(
        string $filePath,
        ?string $prompt = null,
        ?string $language = 'en',
        ?string $model = null,
        string $responseFormat = 'verbose_json',
    ): Transcript {
        $model ??= $this->model(self::PURPOSE_TRANSCRIPTION);

        $fields = array_filter([
            'model' => $model,
            'response_format' => $responseFormat,
            'language' => $language,
            'prompt' => $prompt,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        return $this->metered(self::PURPOSE_TRANSCRIPTION, $model, function (CallUsage $usage) use ($filePath, $fields, $model): Transcript {
            $response = $this->timedSend($usage, function (PendingRequest $http) use ($filePath, $fields): Response {
                $handle = fopen($filePath, 'r');

                try {
                    return $http->attach('file', $handle, basename($filePath))->post($this->url('audio/transcriptions'), $fields);
                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            });

            $json = $response->json();

            if (! is_array($json)) {
                return new Transcript(trim($response->body()), [], $model);
            }

            $transcript = new Transcript(
                text: trim((string) ($json['text'] ?? '')),
                segments: array_values(array_filter((array) ($json['segments'] ?? []), 'is_array')),
                model: $model,
                duration: isset($json['duration']) ? (float) $json['duration'] : null,
            );

            $usage->audioSeconds = $transcript->duration;

            return $transcript;
        });
    }

    /**
     * Transcribe a file on a filesystem disk. The audio is copied to a
     * local temp file first (Whisper needs a real file handle and
     * $disk->path() is local-only), and the temp copy is always removed:
     * audio is PHI.
     */
    public function transcribeStored(string $disk, string $path, ?string $prompt = null, ?string $language = 'en', ?string $model = null): Transcript
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'webm';
        $tmpPath = sys_get_temp_dir().'/ai_audio_'.bin2hex(random_bytes(8)).'.'.$extension;

        try {
            file_put_contents($tmpPath, Storage::disk($disk)->get($path));

            return $this->transcribe($tmpPath, $prompt, $language, $model);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Transcribe a recording uploaded in chunks. Each chunk is primed with
     * the tail of the previous chunk's text so sentences split across a
     * boundary transcribe cleanly.
     *
     * @param  list<string>  $paths  local paths, or disk paths when $disk is given
     */
    public function transcribeChunks(array $paths, string $prompt = '', ?string $disk = null, ?string $language = 'en', int $tailChars = 200): Transcript
    {
        $texts = [];
        $tail = '';
        $model = $this->model(self::PURPOSE_TRANSCRIPTION);

        foreach ($paths as $path) {
            $chunkPrompt = trim($prompt.($tail !== '' ? ' ...'.$tail : ''));

            $transcript = $disk === null
                ? $this->transcribe($path, $chunkPrompt, $language)
                : $this->transcribeStored($disk, $path, $chunkPrompt, $language);

            if ($transcript->text !== '') {
                $texts[] = $transcript->text;
                $tail = mb_substr($transcript->text, -$tailChars);
            }

            $model = $transcript->model;
        }

        return new Transcript(trim(implode(' ', $texts)), [], $model);
    }

    /**
     * Mint a short-lived realtime client secret for live captions in the
     * browser (WebRTC). The real key never leaves the server. Returns null
     * on any failure: captions are display-only and callers fall back to
     * chunked transcription.
     *
     * @param  array<string, mixed>|null  $session
     */
    public function realtimeClientSecret(?array $session = null): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $session ??= [
            'type' => 'transcription',
            'audio' => [
                'input' => [
                    // No priming prompt: on near-silence the model can echo
                    // the prompt into the captions, which reads as a leak.
                    'transcription' => [
                        'model' => $this->model(self::PURPOSE_CAPTIONS),
                        'language' => 'en',
                    ],
                    'noise_reduction' => ['type' => 'near_field'],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'silence_duration_ms' => 400,
                    ],
                ],
            ],
        ];

        $model = $session['audio']['input']['transcription']['model'] ?? null;
        $model = is_string($model) && $model !== '' ? $model : $this->model(self::PURPOSE_CAPTIONS);

        try {
            return $this->metered(self::PURPOSE_CAPTIONS, $model, function (CallUsage $usage) use ($session): string {
                $response = $this->timedSend(
                    $usage,
                    fn (PendingRequest $http): Response => $http->asJson()->post($this->url('realtime/client_secrets'), ['session' => $session]),
                    8,
                );

                $secret = $response->json('value');

                return is_string($secret) && $secret !== '' ? $secret : throw AiException::unreadable('no client secret in reply');
            });
        } catch (AiException) {
            return null;
        }
    }

    /**
     * Run one vendor call and record its usage sample, whatever happens.
     *
     * @template T
     *
     * @param  callable(CallUsage): T  $call
     * @return T
     */
    private function metered(string $purpose, string $model, callable $call): mixed
    {
        $usage = new CallUsage($purpose, $model);

        try {
            $result = $call($usage);
        } catch (AiException $e) {
            if ($e->reason !== AiException::NOT_CONFIGURED) {
                $this->recordUsage($usage, self::outcomeFor($e));
            }

            throw $e;
        } catch (Throwable $e) {
            if ($usage->durationMs !== null) {
                $this->recordUsage($usage, UsageSample::OUTCOME_ERROR);
            }

            throw $e;
        }

        $this->recordUsage($usage, UsageSample::OUTCOME_OK);

        return $result;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function timedSend(CallUsage $usage, callable $call, ?int $timeoutSeconds = null): Response
    {
        $started = hrtime(true);

        try {
            return $this->send($call, $timeoutSeconds);
        } finally {
            $usage->durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        }
    }

    private static function outcomeFor(AiException $e): string
    {
        return match ($e->reason) {
            AiException::TIMED_OUT => UsageSample::OUTCOME_TIMEOUT,
            AiException::BUSY => UsageSample::OUTCOME_BUSY,
            AiException::REJECTED => UsageSample::OUTCOME_REJECTED,
            AiException::REFUSED => UsageSample::OUTCOME_REFUSED,
            default => UsageSample::OUTCOME_ERROR,
        };
    }

    private function recordUsage(CallUsage $usage, string $outcome): void
    {
        try {
            if (! self::usageEnabled()) {
                return;
            }

            app(UsageRecorder::class)->record($usage->toSample($outcome, UsageScope::current()));
        } catch (Throwable) {
            // Metrics are best-effort: a recorder failure must never fail
            // (or slow down) the AI call the user is waiting on.
        }
    }

    /**
     * Null (the default) means on everywhere except while running unit
     * tests, where every call would otherwise queue a metric send from
     * inside app test suites. Tests of the pipeline opt back in with
     * config(['ai.usage.enabled' => true]).
     */
    public static function usageEnabled(): bool
    {
        $enabled = config('ai.usage.enabled');

        if ($enabled !== null) {
            return (bool) $enabled;
        }

        return ! app()->runningUnitTests();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, ?int $timeoutSeconds = null): Response
    {
        if (! $this->isConfigured()) {
            throw AiException::notConfigured();
        }

        $http = Http::withToken((string) config('ai.openai.api_key'))
            ->acceptJson()
            ->timeout($timeoutSeconds ?? (int) config('ai.openai.timeout', 120))
            ->connectTimeout((int) config('ai.openai.connect_timeout', 15))
            ->withHeaders(array_filter([
                'OpenAI-Organization' => (string) config('ai.openai.organization', ''),
                'OpenAI-Project' => (string) config('ai.openai.project', ''),
            ], fn (string $value): bool => $value !== ''));

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw $timedOut ? AiException::timedOut($e->getMessage(), $e) : AiException::unavailable($e->getMessage(), $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = "HTTP {$response->status()}: ".mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 300);

        throw match (true) {
            $response->serverError() => AiException::unavailable($detail),
            $response->status() === 408 => AiException::timedOut($detail),
            $response->status() === 429 => AiException::busy($detail),
            default => AiException::rejected($detail),
        };
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');

        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }

        return $base.'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return list<array<string, mixed>>
     */
    private function contentParts(array $reply): array
    {
        $parts = [];

        foreach ((array) ($reply['output'] ?? []) as $item) {
            foreach (is_array($item) ? (array) ($item['content'] ?? []) : [] as $part) {
                if (is_array($part)) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function outputText(array $reply): ?string
    {
        if (is_string($reply['output_text'] ?? null) && $reply['output_text'] !== '') {
            return $reply['output_text'];
        }

        foreach ($this->contentParts($reply) as $part) {
            if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                return $part['text'];
            }
        }

        return null;
    }
}
