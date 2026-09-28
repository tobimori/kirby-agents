---
title: Other MCP clients
intro: Connect coding agents and other apps that support MCP
---

Kirby Agents works with MCP clients that support remote servers over HTTP and OAuth. Add the MCP URL from the **Agents** view as a remote or HTTP server in your client. The client opens the Kirby login and the consent screen in your browser.

Kirby Agents finds out who the client is in one of two ways. Apps like Claude and ChatGPT publish their identity at their own domain, and the consent screen shows this domain. Other clients register themselves, and the consent screen says that their identity is not verified. Only allow access if you started the connection yourself.

## Local sites

Kirby Agents requires HTTPS. On your own computer, plain HTTP works for addresses like `localhost` and `127.0.0.1`, for example `http://localhost:8000/panel/mcp`. Cloud apps like claude.ai and ChatGPT can't reach a local site. Use a client that runs on your computer, like Claude Code.

## Clients that can't ask for more permissions

Some clients can't ask for a new permission during a session. Tell such a client which permissions to ask for when it connects, if its settings support that. The names of the permissions are in the [options reference](2_reference/0_options#scopes). You can also add them to every connection with the `scopes` option, or add them later in the **Agents** view. See [Permissions](0_getting-started/4_permissions).

## Apps that open with their own URL

Some desktop apps get the result of the login through a URL of their own, like `cursor://`. The consent screen shows a warning for these apps, because any app on your computer can register such a URL. Only allow access if you started the connection in this app.
