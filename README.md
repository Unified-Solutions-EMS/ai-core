# unified/ai-core

Shared AI platform for Unified Solutions apps. OpenAI only (the vendor covered by the signed BAA). Extracted from Reporting's analyst chat, Bridge's disclosure rules, CloudPCR's dictation pipeline and Digital-Forms' structured detection.

| Module | What it gives you |
|---|---|
| `Client\OpenAiClient` | Chat completions with tools, Responses API with strict JSON schema and image inputs, Whisper (file, disk, chunked), realtime client secrets for live captions |
| `Agent\Agent` + `AgentDefinition` | The tool loop: SSE-ready step events, history repair, plan mode, daily token cap, SOP framing, automatic run ledger |
| `Proposal\ProposalService` | drafted → refined → approved → executing → executed → verified, or failed / rejected. Approval binds to the plan hash |
| `Ledger\RunRecorder` | `ai_runs` + `ai_run_steps`, PHI-free index row queued to SSO |
| `Replay` | `ReplayableAgent` + `ReplaysWithAgent` + `DryRunContext` for "test this SOP against a past run" |
| `Sop` | `Sops::active($domain, $companySsoId)` from SSO (cached 5 min) and the fixed `SopFrame` |
| `Phi` | `PhiPosture`, `ModelDisclosure`, `PurgeHooks` |

## Install

```bash
composer require unified/ai-core
php artisan vendor:publish --tag=ai-migrations   # only if the app records runs or proposals
php artisan migrate
```

Apps install from the GitHub repo (`Unified-Solutions-EMS/ai-core`), like sso-client. `composer.lock` must reference the GitHub dist, never a path repository.

## Configuration

No new environment variables are required. `config/ai.php` reads the names apps already use:

| Key | Env |
|---|---|
| `ai.openai.api_key` | `OPENAI_API_KEY` (unset = every AI feature off; nothing is sent) |
| `ai.openai.base_url` | `OPENAI_BASE_URL` (scheme optional) |
| `ai.openai.organization` / `project` | `OPENAI_ORGANIZATION` / `OPENAI_PROJECT` (sent as headers when set) |
| `ai.openai.timeout` | `OPENAI_TIMEOUT`, then `OPENAI_REQUEST_TIMEOUT` |
| `ai.openai.models.chat` / `extraction` / `transcription` / `captions` | `OPENAI_CHAT_MODEL`, `OPENAI_EXTRACTION_MODEL`, `OPENAI_TRANSCRIPTION_MODEL`, `OPENAI_CAPTIONS_MODEL` |
| `ai.openai.reasoning_effort` | `OPENAI_REASONING_EFFORT` (empty omits it) |
| `ai.sso.base_url` / `ai.sso.token` | `SSO_BASE_URL` / `CORE_APP_API_KEY` (falls back to sso-client config) |
| `ai.app_slug` | `AI_APP_SLUG`, then `sso.app_slug`, then `metrics.app_key` |
| `ai.token_caps` | `['default' => AI_DAILY_TOKEN_CAP, 'cad.dispatch' => …]`, keyed by domain |
| `ai.phi.posture` | `AI_PHI_POSTURE`, then `CHAT_PHI_POSTURE` |

Apps that need `HasCompanyScope` on `ai_runs` / `ai_proposals` extend `Ledger\Run` / `Proposal\Proposal` and point `ai.models.run` / `ai.models.proposal` at the subclass.

## An agent

```php
class DispatchAgent extends AgentDefinition
{
    public function domain(): string { return 'cad.dispatch'; }
    public function sopDomain(): ?string { return 'cad.dispatch'; }
    public function hardRules(AgentContext $c): array { return ['Never assign an out-of-service unit.']; }
    public function systemPrompt(AgentContext $c): string { return '…'; }
    public function tools(AgentContext $c): array { return [$this->candidates, $this->assign]; }
}

$outcome = app(Agent::class)->run($definition, $context, $input, $conversation, $emit, planMode: true);
$proposal = app(ProposalService::class)->draft('cad.dispatch', $outcome->plan('Assign units'), $companyId, $userId, $outcome->run);
```

- `Tool::execute(array $args, AgentContext $context): ToolResult`. `payload` goes to the model, `ui` to the browser. Throw `ToolFailure` for a message the model should read and correct.
- A tool that writes implements `WriteTool` (and optionally `DescribesChange`). In plan mode it is recorded as a `PlannedChange` and never executed.
- `ConversationStore` is how an app keeps chat history in its own tables; `InMemoryConversation` serves one-shot agents.
- Emitted events: `tool_call {name, label}`, `tool_result {name, ok, …ui}`, `message {content}`, `error {message}`.

## Approval

```php
$service->approve($proposal, $userId, 'Approve and apply', $hashShownOnTheButton);
$service->execute($proposal, fn (PlannedChange $change, int $i, Proposal $p) => $this->apply($change));
$service->verify($proposal, $userId);
```

The plan hash is taken over canonical JSON (keys sorted at every depth, whole floats written as integers), so a plan hashes the same before and after it is stored and reloaded. A refined plan clears the approval. Execution re-checks the hash and claims the proposal with a conditional update, so overlapping requests cannot both run it. The package never writes app data: the executor does.

## Run index and SOPs

`RegisterRunWithSso` posts `{app_slug, domain, run_id, company_sso_id, sop_version_id, summary, outcome, approved_by_sso_id, created_at}` to `{SSO}/api/internal/ai-runs/register`. One try, failures logged and swallowed, a 404 logged at debug. Keep `AgentDefinition::indexSummary()` free of PHI.

`Sops::active()` reads `{SSO}/api/internal/sops/{company}/{domain}/active`. Call `Sops::forget()` from the `sop.activated` webhook handler.

## Testing

```php
use Unified\AiCore\Testing\OpenAiFake;

OpenAiFake::chat([
    OpenAiFake::toolCalls(['call_1' => ['list_sources', []]]),
    OpenAiFake::message('You ran 42 transports.'),
]);
// …
OpenAiFake::sentChatBodies();
```

Set `OPENAI_API_KEY` to any value in `phpunit.xml`, otherwise the client reports itself unconfigured.

```bash
composer test      # phpunit (Testbench)
composer lint      # pint
composer analyse   # larastan level 5
```

`ai:prune-runs --days=365` deletes old ledger rows.
