---
title: Tools
intro: The tools that agents use, and the permissions they need
---

Agents see the tools that the role of their user allows. A tool that needs a permission the connection doesn't have is listed too, so the agent can ask for it. See [Permissions](0_getting-started/4_permissions).

Plugins can add more tools, see [Custom Tools](1_customization/4_custom-tools). To turn off a tool, use the [`tools` option](2_reference/0_options#tools).

## Read

These tools need the permission to read (`content:read`), which every connection has.

| Tool             | What it does                                                                                            |
| ---------------- | ------------------------------------------------------------------------------------------------------- |
| `site_overview`  | The site title, languages, the page tree with drafts, the templates, and the pages with unsaved changes |
| `pages_find`     | Finds pages by parent, template, status and text                                                        |
| `files_find`     | Lists the files of a page or the site, with filters for template, type and text                         |
| `schema_get`     | The fields of a page, a blueprint, the site or a file, in a short notation                              |
| `content_get`    | The content of a page, the site or a file: an outline first, then single fields or blocks               |
| `page_rules`     | What the blueprints and the role allow for a page: new pages, uploads, status, templates, move targets  |
| `relations_find` | The pages, files or users that a pages, files or users field accepts                                    |

## Change content

| Tool              | What it does                                                          | Permission                                              |
| ----------------- | --------------------------------------------------------------------- | ------------------------------------------------------- |
| `content_update`  | Changes fields, blocks, layouts and structure rows as unsaved changes | `content:write`, and `content:publish` to save directly |
| `changes_discard` | Deletes the unsaved changes of a page, the site or a file             | `content:write`                                         |
| `changes_publish` | Publishes the unsaved changes, like the **Save** button in the Panel  | `content:publish`                                       |

Agents read a page before they change it, and each change refers to the version they read. If an editor changes the page in the meantime, the change fails, and the agent must read the page again. A page that another user is editing in the Panel is locked for agents too.

## Pages

| Tool          | What it does                                                           | Permission                                                      |
| ------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------- |
| `page_create` | Creates a page, like the create dialog in the Panel                    | `pages:manage`, and `content:publish` if the page isn't a draft |
| `page_update` | Changes the title, URL, template, parent, position or status of a page | `pages:manage`, and `content:publish` for the status            |
| `page_delete` | Deletes a page with its subpages and files                             | `pages:delete`                                                  |

`page_delete` takes two calls. The first shows what would be deleted and returns a code. The agent shows this to you and uses the code in a second call.

## Files

| Tool          | What it does                                        | Permission     |
| ------------- | --------------------------------------------------- | -------------- |
| `file_upload` | Returns a link to upload one file with its metadata | `files:manage` |
| `file_delete` | Deletes one file                                    | `files:delete` |

The file doesn't go through the chat. `file_upload` returns a link, and the agent sends the file to it with a shell command. The link is valid for 10 minutes, for one file name and one file template. So agents with a shell, like Claude Code, can upload files. Chat apps like claude.ai, ChatGPT and Langdock get the link, but usually can't send a file from the chat.

Agents can upload to the same places as the Panel, and Kirby checks the file with the rules of the file blueprint. An uploaded file is public at once, so the agent sends its metadata, like the alt text, with the upload. Later, agents read and change the metadata with `content_get` and `content_update`.

`file_delete` also checks the option `delete` of the file blueprint. Fields that refer to the deleted file keep the reference, but show nothing.

## Tools in the browser

Browser agents in the Panel get one more tool: `panel_view` returns the page, file or language that you have open. See [WebMCP](1_customization/3_webmcp).
