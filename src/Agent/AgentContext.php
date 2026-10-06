<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Who an agent run acts for. Built server-side from the session or the
 * authoritative payload; tool arguments from the model never carry
 * tenant identity. Apps implement this on their own context object and
 * add whatever their tools need.
 */
interface AgentContext
{
    /** Local company id; null for staff/platform runs. */
    public function companyId(): ?int;

    /** The company's SSO id, for SOP lookups and the SSO run index. */
    public function companySsoId(): int|string|null;

    public function userId(): ?int;
}
