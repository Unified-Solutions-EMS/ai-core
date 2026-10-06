<?php

declare(strict_types=1);
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStep;
use Unified\AiCore\Proposal\Proposal;

/*
|--------------------------------------------------------------------------
| Unified AI core
|--------------------------------------------------------------------------
|
| One OpenAI client (the platform's BAA vendor), the agent loop, the
| proposal state machine, the run ledger and the SOP reader. Every value
| here falls back to the env names apps already use (OPENAI_API_KEY,
| OPENAI_CHAT_MODEL, OPENAI_REASONING_EFFORT, CORE_APP_API_KEY,
| SSO_BASE_URL), so adopting the package needs no new environment
| variables.
|
| With no OPENAI_API_KEY the client reports itself unconfigured and every
| AI feature is off: the agent answers with a "not available" event and
| nothing is sent anywhere. Booting never fails for want of a key.
|
*/

return [

    'enabled' => (bool) env('AI_ENABLED', true),

    // Registry slug of the hosting app, used when registering runs with
    // SSO. Falls back to sso.app_slug / metrics.app_key at runtime.
    'app_slug' => env('AI_APP_SLUG'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),

        // openai-php style hosts without a scheme ("api.openai.com/v1")
        // are accepted; the client adds https://.
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        'timeout' => (int) env('OPENAI_TIMEOUT', env('OPENAI_REQUEST_TIMEOUT', 120)),

        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 15),

        'models' => [
            'chat' => env('OPENAI_CHAT_MODEL', 'gpt-5.1'),
            'extraction' => env('OPENAI_EXTRACTION_MODEL', env('OPENAI_CHAT_MODEL', 'gpt-5.1')),
            'transcription' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
            'captions' => env('OPENAI_CAPTIONS_MODEL', 'gpt-4o-mini-transcribe'),
        ],

        // Some models reject a reasoning effort alongside function tools on
        // /chat/completions; 'none' is the safe default. An empty value
        // omits the parameter entirely.
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'none'),
    ],

    'agent' => [
        // Tool-call round trips per user message before the turn gives up.
        'max_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 10),

        // Stored messages replayed into the model per turn.
        'history_window' => (int) env('AI_HISTORY_MESSAGES', 40),
    ],

    /*
    | Daily token cap (prompt + completion) per company per domain. The
    | 'default' applies to any domain without its own entry; 0 disables.
    */
    'token_caps' => [
        'default' => (int) env('AI_DAILY_TOKEN_CAP', 2000000),
    ],

    'ledger' => [
        'enabled' => (bool) env('AI_LEDGER_ENABLED', true),

        // ai:prune-runs default retention.
        'retention_days' => (int) env('AI_RUN_RETENTION_DAYS', 365),

        // Register finished runs with SSO's run index (PHI-free row).
        'register_with_sso' => (bool) env('AI_REGISTER_RUNS', true),

        'queue_connection' => env('AI_QUEUE_CONNECTION', env('METRICS_QUEUE_CONNECTION')),
        'queue' => env('AI_QUEUE', env('METRICS_QUEUE')),
    ],

    'sso' => [
        // Falls back to config('sso.base_url') from unified/sso-client.
        'base_url' => env('SSO_BASE_URL'),
        'token' => env('CORE_APP_API_KEY'),
        'timeout' => (int) env('AI_SSO_TIMEOUT', 5),
        'verify_ssl' => (bool) env('AI_SSO_VERIFY_SSL', env('METRICS_VERIFY_SSL', true)),
    ],

    'sops' => [
        'cache_seconds' => (int) env('AI_SOP_CACHE_SECONDS', 300),
    ],

    'phi' => [
        // redacted | aggregates_only | rows. Anything else reads as
        // redacted. 'rows' requires the BAA to cover the deployed model.
        'posture' => env('AI_PHI_POSTURE', env('CHAT_PHI_POSTURE', 'redacted')),

        // Columns that name a person; never filtered, sorted or grouped
        // on below 'rows'. Apps add their own via ModelDisclosure::extend().
        'direct_identifiers' => [
            'patient_last_name',
            'patient_first_name',
            'patient_dob',
            'patient_ssn',
        ],
    ],

    /*
    | Model classes. Apps that need HasCompanyScope (DEV_GUIDELINES §4)
    | extend the package models and point these at the subclasses.
    */
    'models' => [
        'run' => Run::class,
        'run_step' => RunStep::class,
        'proposal' => Proposal::class,
        'company' => env('AI_COMPANY_MODEL', 'App\\Models\\Company'),
        'user' => env('AI_USER_MODEL', 'App\\Models\\User'),
        'company_sso_id_column' => 'sso_company_id',
        'user_sso_id_column' => 'sso_id',
    ],

];
