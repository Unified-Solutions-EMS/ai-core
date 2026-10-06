<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Where a conversation's turns live between requests. Apps with chat
 * history (Reporting's chat_messages) implement this over their own
 * tables; one-shot agents use InMemoryConversation.
 */
interface ConversationStore
{
    /**
     * The most recent $limit steps, oldest first.
     *
     * @return list<Step>
     */
    public function recent(int $limit): array;

    public function record(Step $step): void;
}
