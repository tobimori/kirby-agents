---
title: Permissions
intro: Control per user role who can connect agents and what they can allow
---

Kirby Agents registers permissions that you can restrict per [user role](https://getkirby.com/docs/guide/users/permissions). By default, all permissions are granted.

| Permission                | Controls                                                                                  |
| ------------------------- | ----------------------------------------------------------------------------------------- |
| `access.agents`           | The **Agents** view in the Panel and the consent screen                                   |
| `tobimori.agents.connect` | Connecting agents at all, and the tools for browser agents                                |
| `tobimori.agents.publish` | Giving an agent the permission to publish (`content:publish`)                             |
| `tobimori.agents.delete`  | Giving an agent the permission to delete pages and files (`pages:delete`, `files:delete`) |

Set a permission to `false` in the blueprint of a role to deny it:

```yaml
# site/blueprints/users/editor.yml

title: Editor
permissions:
  tobimori.agents:
    publish: false
    delete: false
```

Editors can then still connect agents, but these agents can't publish or delete. Their changes wait as unsaved changes until a user publishes them in the Panel.

## Kirby permissions

The permissions of the role for pages and files also apply. An agent can never do more than its user in the Panel, and permissions that the role can't use are not available on the consent screen. The [options reference](3_reference/0_options#scopes) lists which Kirby permissions each agent permission needs.

Kirby Agents checks the role on every request. When you change a role blueprint, existing connections lose the permissions that the role no longer allows. When you give a user another role, Kirby Agents revokes all connections of the user.
