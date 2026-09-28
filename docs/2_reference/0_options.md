---
title: Options
intro: All configuration options
---

All options are set under `tobimori.agents` in your `config.php`:

```php
// site/config/config.php
return [
  'tobimori.agents' => [
    'webmcp' => false,
  ],
];
```

| Option    | Default | Description                                                                                      |
| --------- | ------- | ------------------------------------------------------------------------------------------------ |
| `scopes`  | `[]`    | Permissions to ask for on every new connection, see [below](#scopes)                             |
| `path`    | `null`  | Moves the endpoints out of the Panel, see [Hosting](1_customization/2_hosting#a-protected-panel) |
| `origins` | `[]`    | More origins that may call the endpoints from a browser, like `'https://app.example.com'`        |
| `limits`  | `[]`    | Rate limits, see [below](#limits)                                                                |
| `fields`  | `[]`    | Field classes for custom field types, see [Custom field types](1_customization/1_field-types)    |
| `webmcp`  | `true`  | Registers the tools for browser agents in the Panel, see [WebMCP](1_customization/3_webmcp)      |
| `secret`  | `null`  | Key to sign tokens. If not set, Kirby Agents creates one in `site/accounts/.agents-secret`       |

## scopes

A new connection gets `content:read` and `content:write`. To ask for more permissions on every new connection, list them in the `scopes` option:

```php
return [
  'tobimori.agents' => [
    'scopes' => ['content:publish', 'pages:manage', 'files:manage'],
  ],
];
```

The consent screen then lists these permissions too. The role of the user still applies: a permission that the role doesn't allow shows as "Not available for your role".

The names of all permissions, for this option and for the settings of MCP clients:

| Scope             | Permission                                             | Kirby permissions of the role (one is needed)                                                               |
| ----------------- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| `content:read`    | Read pages, files, content, and blueprints             |                                                                                                             |
| `content:write`   | Prepare content changes for review                     | `pages.update`, `site.update`, `files.update`                                                               |
| `content:publish` | Publish content changes and change the status of pages | `pages.update`, `site.update`, `files.update`, `pages.changeStatus`                                         |
| `pages:manage`    | Create, rename, move, and sort pages                   | `pages.create`, `pages.changeTitle`, `pages.changeSlug`, `pages.changeTemplate`, `pages.move`, `pages.sort` |
| `pages:delete`    | Delete pages                                           | `pages.delete`                                                                                              |
| `files:manage`    | Upload files                                           | `files.create`                                                                                              |
| `files:delete`    | Delete files                                           | `files.delete`                                                                                              |

`content:publish`, `pages:manage` and `files:manage` include `content:write`, and `content:write` includes `content:read`. Kirby still checks each single action with the role of the user.

## limits

Kirby Agents limits the requests per time window. Each limit is a list of the number of requests and the window in seconds:

| Limit       | Default      | Counts                                               |
| ----------- | ------------ | ---------------------------------------------------- |
| `register`  | `[20, 3600]` | Registrations of new apps, per IP address            |
| `authorize` | `[30, 60]`   | Starts of the login and consent flow, per IP address |
| `token`     | `[60, 60]`   | Token requests and revocations, per IP address       |
| `mcp`       | `[120, 60]`  | MCP requests, per connection                         |
| `upload`    | `[30, 60]`   | File uploads, per connection                         |

```php
return [
  'tobimori.agents' => [
    'limits' => [
      'mcp' => [300, 60],
      'register' => false,
    ],
  ],
];
```

`false` turns off one limit. `'limits' => false` turns off all limits.

The counters are in the cache `tobimori.agents.limits`. With the default file cache, a limit can let a few more requests through under load. To use APCu or Redis, configure the cache like any [Kirby plugin cache](https://getkirby.com/docs/guide/cache#plugin-caches):

```php
return [
  'tobimori.agents.cache.limits' => [
    'type' => 'apcu',
  ],
];
```
