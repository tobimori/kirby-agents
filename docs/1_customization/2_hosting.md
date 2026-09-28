---
title: Hosting
intro: Server settings for HTTPS, proxies, firewalls and custom URLs
---

Kirby Agents runs inside Kirby, so it works on most servers without extra setup. The main difference to a normal Kirby site is who connects to it. Cloud apps like claude.ai, ChatGPT and Langdock don't run in your browser. They call the MCP URL from their own servers, so your site must be reachable from the internet over HTTPS.

Only two steps happen in the browser of the user: the Panel login and the consent screen. Everything else goes from the servers of the app to your site.

## HTTPS

Agents send their access token with every request, so Kirby Agents only accepts requests over HTTPS. Over plain HTTP, it answers "HTTPS is required".

On your own computer, plain HTTP works for `localhost`, `127.0.0.1` and `::1`. This lets you connect a local agent like Claude Code to your development site.

## The Authorization header

Agents send their token in the `Authorization` header. Some servers don't pass this header to PHP. Kirby Agents then gets requests without a token and asks the agent to log in again and again.

On Apache, Kirby's `.htaccess` file already passes the header. On Nginx, add this line to the PHP location of your server block:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

## Behind a proxy

If a proxy like Caddy, Nginx or a load balancer handles HTTPS for your site, PHP only sees plain HTTP from the proxy. Kirby Agents then answers "HTTPS is required", and the addresses in its OAuth metadata start with `http://`.

Set Kirby's `url` option to the public URL of your site. Kirby then trusts the headers of the proxy for this URL:

```php
<?php
// site/config/config.php

return [
  'url' => ['https://example.com'],
];
```

Kirby Agents limits the number of requests per IP address, to slow down attacks on the login. Behind a proxy, all requests can come from the address of the proxy, so the limits are reached sooner. Raise the limits for `register`, `authorize` and `token` in the [options](2_reference/0_options#limits).

## A protected Panel

Some sites protect the Panel with an IP allowlist, or with a password on the web server. Both block the cloud apps: their servers aren't on your allowlist, and a password prompt of the web server uses the same `Authorization` header as the agents.

To keep the Panel protected, move the endpoints of Kirby Agents out of the Panel with the `path` option:

```php
<?php
// site/config/config.php

return [
  'tobimori.agents' => [
    'path' => 'agents',
  ],
];
```

The MCP URL is then `https://example.com/agents/mcp` instead of `https://example.com/panel/mcp`, and the Panel endpoints are turned off. The **Agents** view shows the new URL.

The consent screen stays in the Panel. That's fine for an IP allowlist, because the user opens it in their own browser, from the office or over a VPN.

With `'path' => ''`, the endpoints are at the root of your site, like `https://example.com/mcp`. They take priority over your pages, so a page with the id `mcp` can't be reached anymore.

A connection only works with the MCP URL it was made for. When you change `path`, all agents must connect again.

## Sites without a Panel

With `'panel' => false`, users can't log in, so they can't connect agents.

## More than one server

Kirby Agents stores the connections of each user in the folder of the user, in `site/accounts/<user>/.agents/`. When your site runs on more than one server, all servers must share the accounts folder, like they must for the users.

Kirby Agents also signs its tokens with a secret key. By default, it creates the key in `site/accounts/.agents-secret` the first time it needs one. To manage the key yourself, set the `secret` option. When the key changes, agents get new tokens on their own, but apps that registered themselves, like Langdock, must connect again.
