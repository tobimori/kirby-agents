---
title: Custom Tools
intro: Add your own tools and permissions from a plugin
---

The tools of Kirby Agents read and change pages, files and content. An agent can't use other features of your site, like a newsletter plugin, unless the plugin adds a tool for it.

A tool from a plugin works like a core tool. The agent gets it in the same list, and the connection needs a permission for it. The plugin can use a core permission or add its own, which users then allow on the consent screen.

## Writing a tool

A tool is a class that implements `tobimori\Agents\Tools\Tool`. This tool sends a page as a newsletter:

```php
<?php
// site/plugins/newsletter/classes/Agents/SendNewsletter.php

namespace Acme\Newsletter\Agents;

use Acme\Newsletter\Newsletter;
use tobimori\Agents\OAuth\Access;
use tobimori\Agents\Tools\Arguments;
use tobimori\Agents\Tools\Tool;
use tobimori\Agents\Tools\ToolError;

class SendNewsletter implements Tool
{
  public function name(): string
  {
    return 'newsletter_send';
  }

  public function definition(): array
  {
    return [
      'title' => 'Send newsletter',
      'description' => 'Sends a published page to all subscribers. This cannot be undone, so ask the user first.',
      'inputSchema' => [
        'type' => 'object',
        'properties' => [
          'page' => ['type' => 'string', 'description' => 'The id of the page, like `newsletter/may`'],
        ],
        'required' => ['page'],
        'additionalProperties' => false,
      ],
      'annotations' => [
        'readOnlyHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => true,
      ],
    ];
  }

  public function scope(): string
  {
    return 'newsletter:send';
  }

  public function call(Arguments $arguments, Access $access): array|string
  {
    $page = kirby()->page($arguments->string('page'));

    if ($page === null || $page->isPublished() === false) {
      throw new ToolError('Send the id of a published page');
    }

    $count = Newsletter::send($page);

    return "Sent to {$count} subscribers.";
  }
}
```

`name()` is the name that the agent calls. Use a prefix of your plugin, like `newsletter_`, so the names don't collide with other tools. Names can have letters, digits, `_`, `-` and `.`.

`definition()` is the [MCP tool definition](https://modelcontextprotocol.io/specification/2025-11-25/server/tools) without the name. The agent only knows what the description says, so write when to use the tool and what to check before. `annotations` tell the client whether the tool only reads, and whether it deletes or changes something outside of your site.

`scope()` is the permission that a connection needs for the tool. It can be one of the [core permissions](2_reference/0_options#scopes), like `content:read`, or one of your own.

`call()` runs the tool. Kirby Agents logs in the user of the connection first, so Kirby checks the permissions of the role like in the Panel. `$arguments` has the input of the agent, with checks for the types: `string()`, `int()`, `bool()` and more. `$access->user` is the user.

Return a text, or an array that the agent gets as JSON. When the agent can fix a problem, throw a `ToolError` with a message that says how. The agent gets the message and can try again.

## Adding a permission

Sending a newsletter is not a content change, so none of the core permissions fits. Add a permission of your own:

```php
<?php
// site/plugins/newsletter/index.php

load([
  'Acme\\Newsletter\\Agents\\SendNewsletter' => __DIR__ . '/classes/Agents/SendNewsletter.php',
]);

Kirby::plugin('acme/newsletter', [
  'permissions' => [
    'send' => true,
  ],
  'tobimori.agents.scopes' => [
    'newsletter:send' => [
      'label' => ['en' => 'Send newsletters', 'de' => 'Newsletter versenden'],
      'short' => 'Newsletter',
      'permissions' => ['acme.newsletter.send'],
    ],
  ],
  'tobimori.agents.tools' => [
    Acme\Newsletter\Agents\SendNewsletter::class,
  ],
]);
```

The name of the permission has two parts, like `newsletter:send`: lowercase letters, digits, `_` and `-`, with a `:` between them.

`label` is the text on the consent screen and in the **Agents** view. It can be a text, an array with translations, or a translation key of your plugin:

```php
'tobimori.agents.scopes' => [
  'newsletter:send' => [
    'label' => 'acme.newsletter.scope.send',
    'short' => 'acme.newsletter.scope.send.short',
  ],
],
'translations' => [
  'en' => [
    'acme.newsletter.scope.send' => 'Send newsletters',
    'acme.newsletter.scope.send.short' => 'Newsletter',
  ],
  'de' => [
    'acme.newsletter.scope.send' => 'Newsletter versenden',
    'acme.newsletter.scope.send.short' => 'Newsletter',
  ],
],
```

`short` is the shorter label in the list of connections, in the same formats. Without it, the list shows the label.

`permissions` lists the Kirby permissions that the role of the user needs. If there are more than one, the role needs all of them. In the example, the plugin registers its own permission `send` for this, so you can deny it per role:

```yaml
# site/blueprints/users/editor.yml

title: Editor
permissions:
  acme.newsletter:
    send: false
```

Editors then can't give an agent the permission to send newsletters. Without `permissions`, every user who may connect agents can give it.

## How users get the permission

A new connection gets only the core permissions to read and prepare changes. The agent still sees your tool, with a note that it needs another permission. When the agent calls it, Kirby Agents asks the client to get the permission from the user, the same as for a core permission. See [Permissions](0_getting-started/4_permissions#asking-during-a-chat).

Users can also add the permission to a connection in the **Agents** view. And a site can ask for it on every new connection with the [`scopes` option](2_reference/0_options#scopes).

## Loading the classes

Register the tool as a class name, and load the class lazily with Kirby's `load()` or with Composer. Your plugin then works without Kirby Agents too: Kirby ignores the `tobimori.agents.*` keys, and the class is never loaded. A `require` in `index.php` would fail on these sites, because the class implements an interface of Kirby Agents.

Kirby Agents checks the tools and permissions of all plugins before it answers an agent. A tool with a name that exists already, or with a permission that doesn't exist, stops the request with an error that names your plugin.

## Classes for plugins

Your plugin can use these classes. They only change in major releases:

| Class                             | Use                                                                                                                                                                                  |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tobimori\Agents\Tools\Tool`      | The interface of a tool                                                                                                                                                              |
| `tobimori\Agents\Tools\Arguments` | The input of the agent: `string()`, `strings()`, `int()`, `bool()`, `enum()`, `list()`, `object()` and `has()`                                                                       |
| `tobimori\Agents\Tools\ToolError` | An error that the agent gets as the result                                                                                                                                           |
| `tobimori\Agents\OAuth\Access`    | The connection: `$access->user`, `$access->scopes` and `$access->allows()`                                                                                                           |
| `tobimori\Agents\OAuth\Scope`     | The names of the core permissions as constants, like `Scope::CONTENT_WRITE`                                                                                                          |
| `tobimori\Agents\Content\Models`  | `Models::find()` gets a page or the site from an id, with drafts, and `Models::content()` also files. Both check the access                                                          |
| `tobimori\Agents\Fields\Field`    | The base class for [custom field types](1_customization/1_field-types), with the core field classes and the objects that their methods get: `Compiler`, `InputCheck` and `Presenter` |

All other classes are internal. They can change in minor releases.
