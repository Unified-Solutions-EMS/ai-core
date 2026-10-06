<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

final class InMemoryConversation implements ConversationStore
{
    /** @var list<Step> */
    private array $steps = [];

    /**
     * @param  list<Step>  $steps
     */
    public function __construct(array $steps = [])
    {
        $this->steps = $steps;
    }

    public function recent(int $limit): array
    {
        return array_slice($this->steps, -max(0, $limit));
    }

    public function record(Step $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
