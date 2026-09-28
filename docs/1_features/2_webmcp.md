---
title: WebMCP
intro: Give agents in the browser the same tools as in the Panel
---

WebMCP is a draft browser API. With it, a website gives its tools to an agent in the browser. Kirby Agents registers its tools in the Panel, so a browser agent can edit the site while you are logged in.

WebMCP is experimental. Chrome tests it in an origin trial, and other browsers don't support it yet. In browsers without WebMCP, Kirby Agents does nothing.

## What the agent can do

The agent gets the same tools as over MCP, and one more: `panel_view` tells it which page, file or language you have open in the Panel. So you can ask it to change "this page".

There is no consent screen, because you are logged in and at the browser. The agent gets all permissions that your role allows. Your role needs the plugin permission `tobimori.agents.connect`, see [Permissions](3_reference/1_permissions).

When the agent changes content, the Panel reloads the view, so you see the unsaved changes at once.

## Turn it off

```php
// site/config/config.php
return [
  'tobimori.agents' => [
    'webmcp' => false,
  ],
];
```
