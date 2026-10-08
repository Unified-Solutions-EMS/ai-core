<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An AI-drafted plan moving through
 * drafted → refined → approved → executing → executed → verified
 * (or failed / rejected). Nothing in the package writes app data: the
 * executor passed to ProposalService::execute() does.
 *
 * Apps that need HasCompanyScope extend this model and point
 * config('ai.models.proposal') at the subclass.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $app_slug
 * @property string $domain
 * @property int|null $company_id
 * @property int|null $created_by
 * @property ProposalStatus $status
 * @property array<string, mixed> $plan
 * @property string $plan_hash
 * @property string|null $approved_plan_hash
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_instruction
 * @property Carbon|null $executing_at
 * @property int|null $executing_by
 * @property Carbon|null $executed_at
 * @property array<int, array<string, mixed>>|null $results
 * @property string|null $failed_reason
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $rejected_by
 * @property string|null $rejected_reason
 * @property int|null $run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Proposal extends Model
{
    protected $table = 'ai_proposals';

    protected $fillable = [
        'uuid',
        'app_slug',
        'domain',
        'company_id',
        'created_by',
        'status',
        'plan',
        'plan_hash',
        'approved_plan_hash',
        'approved_by',
        'approved_at',
        'approval_instruction',
        'executing_at',
        'executing_by',
        'executed_at',
        'results',
        'failed_reason',
        'verified_by',
        'verified_at',
        'rejected_by',
        'rejected_reason',
        'run_id',
    ];

    protected $casts = [
        'status' => ProposalStatus::class,
        'plan' => 'array',
        'results' => 'array',
        'approved_at' => 'datetime',
        'executing_at' => 'datetime',
        'executed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Proposal $proposal): void {
            $proposal->uuid ??= (string) Str::uuid();
        });
    }

    public function planObject(): Plan
    {
        return Plan::fromArray($this->plan);
    }

    /**
     * Claimed for execution longer ago than $minutes and never finished:
     * the worker most likely died. ai:recover-stuck-proposals moves these
     * to failed.
     */
    public function isStale(int $minutes): bool
    {
        return $this->status === ProposalStatus::Executing
            && $this->executing_at !== null
            && $this->executing_at->lt(now()->subMinutes($minutes));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
