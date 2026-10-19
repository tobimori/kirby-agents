---
title: Retour
intro: Let agents fix 404 errors with redirects
---

[Retour](https://github.com/distantnative/retour-for-kirby) manages redirects in the Panel and logs the requests that end in a 404 error. Retour doesn't store them as content, but in `site/config/retour.yml` and in a database, so the content tools of Kirby Agents can't see them. When Retour is installed, agents get extra tools for them.

You don't have to set anything up. Install Retour 5 or later, and the tools are there with the next request of an agent.

## The permission

The tools need the permission **See 404 errors, and create, change, and delete redirects** (`retour:redirects`). Select it on the consent screen when you connect an agent, or add it later in the **Agents** view, see [Permissions](0_getting-started/4_permissions).

The role of the user needs access to the **Retour** view in the Panel. If you deny it, the role can't give agents this permission:

```yaml
# site/blueprints/users/editor.yml

title: Editor
permissions:
  access:
    retour: false
```

## Tools

| Tool                     | What it does                                                             |
| ------------------------ | ------------------------------------------------------------------------ |
| `retour_failures_find`   | Lists the paths that got a 404 error, with hits and referrers            |
| `retour_redirects_find`  | Lists the redirects, with their hits                                     |
| `retour_redirect_create` | Creates a redirect, and marks the 404 errors that it handles as resolved |
| `retour_redirect_update` | Changes a redirect                                                       |
| `retour_redirect_delete` | Deletes a redirect                                                       |

A typical task is "Look at the 404 errors and fix them". The agent lists the 404 errors, finds the matching pages with `pages_find`, and creates the redirects. It leaves paths that bots try on every site, like `wp-login.php`, alone.

## Redirects go live at once

Redirects are not content, so they don't wait as unsaved changes for you to review. A redirect works as soon as the agent creates it. The agent tells you which redirects it creates, changes or deletes, and you see them in the Retour view in the Panel, with the agent's user as their creator.

Agents use status 301 for pages that moved for good. Browsers keep a 301 redirect in their cache, so ask for 302 if the old path might come back.

## Patterns

One redirect can handle many paths with Kirby's placeholders. Agents use them when several old URLs follow the same scheme:

| Placeholder   | Matches                                |
| ------------- | -------------------------------------- |
| `(:any)`      | One part of the path, between slashes  |
| `(:all)`      | The rest of the path, with its slashes |
| `(:num)`      | A number                               |
| `(:alpha)`    | Letters                                |
| `(:alphanum)` | Letters and digits                     |

The target uses the matches as `$1`, `$2` and so on. A redirect from `blog/(:num)/(:any)` to `notes/$2` sends `blog/2024/my-post` to `notes/my-post`. Kirby Agents rejects patterns with `*` or regular expressions, which agents sometimes try.

## Good to know

- Agents save redirects to a page with the UUID of the page, like the Panel does. The redirect still works when the page moves.
- Pages win over redirects. If a page exists at the old path, the redirect only works with **Priority**, and the agent gets a note about it.
- MCP clients look for their login at addresses like `/.well-known/oauth-authorization-server` when they connect. These requests show up as 404 errors in Retour too. To hide them, use the `ignore` option of Retour:

```php
// site/config/config.php

return [
  'distantnative.retour.ignore' => ['.well-known/(:all)'],
];
```
