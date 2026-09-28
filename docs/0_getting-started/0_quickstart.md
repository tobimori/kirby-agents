---
title: Quickstart
intro: Install Kirby Agents and get the URL you need to connect your AI assistant
---

**Kirby Agents** lets AI assistants edit your Kirby site the way your editors do.

- Works with Claude, ChatGPT, Langdock and other [MCP](https://modelcontextprotocol.io/) clients
- Understands your blueprints, including blocks, layouts and fields from other plugins
- Changes go to review in the Panel before they go live
- Every user connects with their own account and permissions

## Requirements

Kirby Agents requires

- Kirby 5 or later
- PHP 8.2 or later
- HTTPS on the site you want to connect

AI assistants like Claude and ChatGPT connect to your site from their own servers. This means your site must be reachable from the internet. It does not work with sites who lock the panel behind their intranet. A local development site only works with 'coding agents' that run on your own computer, like Claude Code/Codex.

## Installing Kirby Agents

In a terminal window, navigate to the folder of your Kirby installation. Then run the following command:

```bash
composer require tobimori/kirby-agents
```

<details>
<summary>Manual Installation</summary>

If you prefer not to use Composer, you can manually install Kirby Agents. Go to the [GitHub releases page](https://github.com/tobimori/kirby-agents/releases) and find the latest release. Click on "Assets" to expand it and select "Source code (zip)". Extract the contents of the zip file into the `site/plugins/kirby-agents` folder of your Kirby installation.
</details>

The plugin works without configuration and without changes to your blueprints.

## Find your MCP URL

Open the Panel and go to the new **Agents** view in the menu.

If your site has a [custom Panel menu](https://getkirby.com/docs/reference/system/options/panel#panel-menu), the entry doesn't show up automatically. Add `agents` to your menu in the place you want it:

```php
// site/config/config.php
return [
  'panel' => [
    'menu' => [
      'site',
      'users',
      'agents', // <--- add this
      'system',
    ],
  ],
];
```

At the top of the view is the **MCP URL** with a copy button. For most sites, it looks like this:

```
https://example.com/panel/mcp
```

Add this URL to your assistant. The assistant opens the Kirby login in your browser. Log in with your Kirby account, check the permissions and click **Allow**.

## Connect your assistant

Pick the assistant you use:

- [Claude](0_getting-started/1_claude) (claude.ai, Claude Desktop, Claude Code)
- [ChatGPT](0_getting-started/2_chatgpt)
- [Langdock](0_getting-started/3_langdock)

Each connection is listed in the **Agents** view. There you can change its permissions or revoke it.

## Review the changes

Changes by the assistant don't go live directly. Like your own edits in the Panel, they are saved as **unsaved changes** on the page. To publish them, open the page in the Panel and click **Save**. To remove them, click **Discard**.

You can give an assistant the permission to publish changes directly in the **Agents** view. We recommend to start without this permission.
