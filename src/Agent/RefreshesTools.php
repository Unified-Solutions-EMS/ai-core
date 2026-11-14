<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Marker for an AgentDefinition whose tool list may change between
 * iterations (a tool loaded more tools); re-read it before each model call.
 * Definitions without it have their tools read once per run.
 */
interface RefreshesTools {}
