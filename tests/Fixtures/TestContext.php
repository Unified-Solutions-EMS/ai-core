<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;

final readonly class TestContext implements AgentContext
{
    public function __construct(
        public ?int $company = 7,
        public int|string|null $companySso = 107,
        public ?int $user = 3,
    ) {}

    public function companyId(): ?int
    {
        return $this->company;
    }

    public function companySsoId(): int|string|null
    {
        return $this->companySso;
    }

    public function userId(): ?int
    {
        return $this->user;
    }
}
