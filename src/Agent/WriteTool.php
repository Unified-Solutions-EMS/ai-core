<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Marker: this tool changes app data. In plan mode (and every replay) the
 * agent never calls execute(); it records the call as a PlannedChange and
 * tells the model the change was proposed, not made. Implement
 * DescribesChange as well to give the plan a readable before/after.
 */
interface WriteTool extends Tool {}
