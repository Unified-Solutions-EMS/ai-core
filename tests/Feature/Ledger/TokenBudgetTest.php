<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Ledger;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Tests\TestCase;
use Unified\AiCore\Usage\TokenBudget;

class TokenBudgetTest extends TestCase
{
    public function test_caps_are_per_domain_even_with_dotted_domain_names(): void
    {
        config()->set('ai.token_caps', ['default' => 500, 'cad.dispatch' => 100]);
        $budget = app(TokenBudget::class);

        $this->assertSame(100, $budget->cap('cad.dispatch'));
        $this->assertSame(500, $budget->cap('crew.scheduling'));
    }

    public function test_spend_is_today_only_per_company_and_domain(): void
    {
        Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => 7, 'status' => 'completed', 'prompt_tokens' => 10, 'completion_tokens' => 5]);
        Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => 7, 'status' => 'completed', 'prompt_tokens' => 1, 'completion_tokens' => 1]);
        Run::query()->create(['domain' => 'crew.scheduling', 'company_id' => 7, 'status' => 'completed', 'prompt_tokens' => 99]);
        Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => null, 'status' => 'completed', 'prompt_tokens' => 40]);
        $yesterday = Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => 7, 'status' => 'completed', 'prompt_tokens' => 1000]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();

        $budget = app(TokenBudget::class);

        $this->assertSame(17, $budget->spentToday('cad.dispatch', 7));
        $this->assertSame(40, $budget->spentToday('cad.dispatch', null));
    }
}
