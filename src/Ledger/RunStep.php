<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a run: the user input, each model reply, each tool call
 * with its arguments and result.
 *
 * @property int $id
 * @property int $run_id
 * @property int $seq
 * @property string $role
 * @property string|null $tool_name
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $result
 * @property int $tokens
 */
class RunStep extends Model
{
    protected $table = 'ai_run_steps';

    protected $fillable = [
        'run_id',
        'seq',
        'role',
        'tool_name',
        'arguments',
        'result',
        'tokens',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'seq' => 'integer',
        'tokens' => 'integer',
    ];

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        /** @var class-string<Run> $model */
        $model = config('ai.models.run', Run::class);

        return $this->belongsTo($model, 'run_id');
    }
}
