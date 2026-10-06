<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One agent run: what went in, which SOP version and model, what came
 * out and why, who approved it and what happened. Lives in the app that
 * owns the data (PHI stays there); SSO only indexes a PHI-free row.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $app_slug
 * @property string $domain
 * @property int|null $company_id
 * @property string|null $company_sso_id
 * @property int|null $user_id
 * @property int|null $sop_version_id
 * @property string|null $model
 * @property array<string, mixed>|null $input_snapshot
 * @property string|null $prompt_hash
 * @property array<string, mixed>|null $recommendation
 * @property string|null $explanation
 * @property string|null $summary
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property RunStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $executed_at
 * @property array<string, mixed>|null $outcome
 * @property int|null $proposal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Run extends Model
{
    protected $table = 'ai_runs';

    protected $fillable = [
        'uuid',
        'app_slug',
        'domain',
        'company_id',
        'company_sso_id',
        'user_id',
        'sop_version_id',
        'model',
        'input_snapshot',
        'prompt_hash',
        'recommendation',
        'explanation',
        'summary',
        'prompt_tokens',
        'completion_tokens',
        'status',
        'approved_by',
        'approved_at',
        'executed_at',
        'outcome',
        'proposal_id',
    ];

    protected $casts = [
        'status' => RunStatus::class,
        'input_snapshot' => 'array',
        'recommendation' => 'array',
        'outcome' => 'array',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Run $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<RunStep, $this>
     */
    public function steps(): HasMany
    {
        /** @var class-string<RunStep> $model */
        $model = config('ai.models.run_step', RunStep::class);

        return $this->hasMany($model, 'run_id')->orderBy('seq');
    }

    public function totalTokens(): int
    {
        return $this->prompt_tokens + $this->completion_tokens;
    }

    /**
     * The state SSO's index shows: executed beats approved beats the
     * run's own status.
     */
    public function indexOutcome(): string
    {
        return match (true) {
            $this->executed_at !== null => 'executed',
            $this->approved_at !== null => 'approved',
            default => $this->status->value,
        };
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
