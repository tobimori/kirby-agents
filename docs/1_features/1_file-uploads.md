---
title: File uploads
intro: Let agents upload images and other files
---

Agents can upload files to pages and to the site. The connection needs the permission **Upload files**, and the role of the user needs the Kirby permission `files.create`.

## How an upload works

The file doesn't go through the chat. The agent asks Kirby for an upload link with the tool `file_upload`, and then sends the file to this link with a shell command. The link is valid for 10 minutes, for one file name and one file template.

So the agent needs a shell with access to the file and to your site. Coding agents like Claude Code have one. Chat apps like claude.ai, ChatGPT and Langdock can get the link, but usually can't send a file from the chat.

## Where agents can upload

Agents can upload to the same places as the Panel: the files sections of a page, and files fields that upload to the page. Kirby checks the file type, the size and the contents with the rules of the file blueprint, like in the Panel.

## Metadata

An uploaded file is public at once, there is no review step. So the agent sends the metadata, like the alt text, together with the upload, and Kirby checks the required fields before it creates the link.

To tell agents what a good alt text is, add a [blueprint hint](2_customization/0_blueprint-hints) to the field in the file blueprint.

## Delete files

To delete a file, the connection needs the permission **Delete files**, and the role needs `files.delete`. Kirby also checks the option `delete` in the file blueprint. Fields that refer to the deleted file keep the reference, but show nothing.
