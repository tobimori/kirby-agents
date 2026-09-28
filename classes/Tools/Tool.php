<?php

declare(strict_types=1);

namespace tobimori\Agents\Tools;

use tobimori\Agents\OAuth\Access;

/**
 * A tool for agents. Plugins add their own with the key `tobimori.agents.tools`
 */
interface Tool
{
	/**
	 * The name that agents call, like `newsletter_send`
	 */
	public function name(): string;

	/**
	 * The MCP tool definition without the name: `title`, `description`, `inputSchema` and `annotations`
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array;

	/**
	 * The scope that a connection needs for the tool
	 */
	public function scope(): string;

	/**
	 * Runs as the Kirby user of the connection. Throw a `ToolError` for errors that the agent can fix
	 *
	 * @return array<array-key, mixed>|string a text, or data that the agent gets as JSON
	 */
	public function call(Arguments $arguments, Access $access): array|string;
}
