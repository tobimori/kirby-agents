<?php

declare(strict_types=1);

return [
	// endpoints outside the Panel, for example `agents` for `/agents/mcp`, or `''` for `/mcp`.
	// For hosts that block the Panel from outside. The consent stays in the Panel.
	'path' => null,
	// register the tools with WebMCP (`document.modelContext`) in the Panel, for agents in the browser
	'webmcp' => true,
	// client metadata documents
	'cache' => true,
	// rate limit counters
	'cache.limits' => true,
	// requests per window in seconds, for example `'mcp' => [300, 60]`. `false` turns a limit off, or all limits
	// defaults: register [20, 3600], authorize [30, 60], token [60, 60] per IP; mcp [120, 60], upload [30, 60] per agent
	'limits' => [],
	// field classes for custom field types, for example `['rating' => \tobimori\Agents\Fields\NumberField::class]`.
	// A class extends \tobimori\Agents\Fields\Field. Types without a class show what their props tell
	'fields' => [],
	// extra origins that may call the MCP endpoint from a browser
	'origins' => [],
	// scopes to ask for on each authorization, in addition to the ones the client requests
	'scopes' => [],
	// key to sign tokens, generated into `site/accounts/.agents-secret` if not set
	'secret' => null,
];
