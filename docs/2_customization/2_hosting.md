---
title: Hosting
intro: Server settings for HTTPS, proxies, firewalls and custom URLs
---

Cloud apps like claude.ai, ChatGPT and Langdock call your site from their own servers. So the MCP URL must be reachable from the internet over HTTPS. Only the login and the consent screen open in the browser of the user.

## HTTPS

Kirby Agents answers "HTTPS is required" to requests over plain HTTP. On your own computer, plain HTTP works for `localhost`, `127.0.0.1` and `::1`.

## Apache and Nginx

On Apache, the `.htaccess` file of Kirby already has all that Kirby Agents needs.

Nginx doesn't pass the `Authorization` header to PHP by default. Without it, every request fails as if the agent weren't logged in. Add this line to the PHP location of your server block:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

## Behind a proxy

When a proxy like Caddy, Nginx or a load balancer handles HTTPS, PHP only sees plain HTTP. Set Kirby's `url` option to the public URL of your site. Kirby then reads the headers of the proxy for this URL:

```php
// site/config/config.php
return [
  'url' => ['https://example.com'],
];
```

Without it, Kirby Agents answers "HTTPS is required", and the addresses in the OAuth metadata are wrong.

The [rate limits](3_reference/0_options#limits) count per IP address. Behind a proxy, all requests can come from the address of the proxy, so raise the limits for `register`, `authorize` and `token`.

## Firewalls and password protection

Cloud apps can't pass an IP allowlist on `/panel`. A password protection of the web server on `/panel` also blocks them, because it uses the same `Authorization` header as the agents.

If the Panel must stay protected, move the endpoints out of the Panel with the `path` option:

```php
// site/config/config.php
return [
  'tobimori.agents' => [
    'path' => 'agents',
  ],
];
```

The MCP URL is then `https://example.com/agents/mcp`, and the OAuth endpoints move to `https://example.com/agents/oauth/…`. The Panel endpoints are off. The consent screen stays in the Panel, so the browser of the user still needs access to it.

With `'path' => ''`, the endpoints are at the root of the site, for example `https://example.com/mcp`. The endpoints come before your pages, so a page with the id `mcp` is then hidden.

A connection only works with the MCP URL it was made for. When you change `path`, all agents must connect again.

## Sites without a Panel

With `'panel' => false`, users can't log in, so they can't connect agents.

## More than one server

Kirby Agents stores the connections of each user in the user folder, in `site/accounts/<user>/.agents/`. All servers must share the accounts folder, like they must for the users.

Kirby Agents signs its tokens with a secret key. By default, it creates the key once in `site/accounts/.agents-secret`. To set the key yourself, use the `secret` option. When the key changes, apps that registered themselves, like Langdock, must connect again.
