<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use RuntimeException;

/**
 * An error in the tool input or in the content, which the agent can fix and retry
 */
final class ToolError extends RuntimeException {}
