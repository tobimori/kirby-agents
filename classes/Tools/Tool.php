<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\OAuth\Access;
use tobimori\Agents\OAuth\Scope;

interface Tool
{
	public function name(): string;

	/**
	 * MCP tool definition without the name: title, description, inputSchema, annotations.
	 * The server adds a parameter guide from the inputSchema to the description.
	 */
	public function definition(): array;

	/**
	 * Scope the access token needs to see and call the tool
	 */
	public function scope(): Scope;

	/**
	 * Returns structured data, or plain text for results that are text already.
	 * Throws a ToolError the agent can fix.
	 */
	public function call(Arguments $arguments, Access $access): array|string;
}
