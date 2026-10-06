<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use RuntimeException;

/**
 * A tool refusing an argument, in words meant for the model to read and
 * act on rather than for a log. The agent turns it into the tool's error
 * result, so the model can correct itself instead of the turn dying.
 */
class ToolFailure extends RuntimeException {}
