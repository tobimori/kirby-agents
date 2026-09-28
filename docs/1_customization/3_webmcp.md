---
title: WebMCP
intro: Let AI agents in the browser work with the Panel
---

Browsers start to ship their own AI agents. These agents can use a website like a person does: they read the page, click buttons and fill in forms. This is slow and breaks easily, so there is a draft for a better way. With [WebMCP](https://github.com/webmachinelearning/webmcp), a website gives the agent in the browser a list of tools, like an MCP server does.

Kirby Agents registers its tools in the Panel for this. When you use a browser with WebMCP and open the Panel, the agent of the browser can read and change your content with the same tools that Claude or ChatGPT use.

WebMCP is experimental. Chrome tests it in an origin trial, and other browsers don't support it yet. In browsers without WebMCP, Kirby Agents does nothing.

## How it works

The agent works in your Panel session. There is no consent screen and no connection in the **Agents** view, because you are logged in and at the browser yourself. The agent can do everything your role allows, and your role needs the plugin permission `tobimori.agents.connect`, see [Permissions](2_reference/1_permissions).

The agent also gets one more tool: `panel_view` tells it which page, file and language you have open. So you can say "shorten the intro of this page", and the agent knows which page you mean.

When the agent changes content, the Panel reloads the view. You see the changes at once as unsaved changes, and you can save or discard them like your own.

## Turning it off

WebMCP is on by default. To turn it off:

```php
<?php
// site/config/config.php

return [
  'tobimori.agents' => [
    'webmcp' => false,
  ],
];
```
