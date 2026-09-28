---
title: Other MCP Clients
intro: Connect coding agents and other apps that support MCP
---

Claude, ChatGPT and Langdock have their own guides, but Kirby Agents works with any MCP client that supports remote servers and OAuth. That includes most coding agents and many desktop apps.

In the settings of your client, add a remote MCP server (some clients call it an HTTP server) with the MCP URL from the **Agents** view. When the client connects, it opens the Kirby login and the consent screen in your browser, like in the other guides.

## Who is connecting

The consent screen tells you where the name of the app comes from. Large apps like Claude and ChatGPT publish their identity on their own domain, and Kirby checks it. The consent screen then shows this domain.

Most other clients register themselves when they connect. They choose their own name, so the consent screen says that their identity is not verified. This is normal for coding agents and desktop apps. Only allow access if you started the connection yourself.

Some desktop apps return to themselves after the login with a URL of their own, like `cursor://`. Any app on your computer can register such a URL, so the consent screen shows a warning for them.

## Local sites

Kirby Agents requires HTTPS, with one exception: on your own computer, plain HTTP works for `localhost` and `127.0.0.1`. So you can connect a coding agent to your development site:

```
http://localhost:8000/panel/mcp
```

Cloud apps like claude.ai and ChatGPT can't reach a site on your computer. For a local site, use a client that runs on your computer too, like Claude Code.

## Clients that can't ask for permissions

When an agent needs a permission its connection doesn't have, Kirby asks the client to get it from you. Not every client supports this. Some show an error, and some ask you to log in again with the same permissions as before.

With these clients, add the permission in the **Agents** view, see [Permissions](0_getting-started/4_permissions). If your client lets you set the scopes it asks for, you can also add them there before you connect. The names of the scopes are in the [options reference](2_reference/0_options#scopes).
