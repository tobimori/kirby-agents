---
title: Blueprint Hints
intro: Explain fields to agents, or hide them
---

Before an agent changes a page, it reads the blueprint of the page. Kirby Agents turns the blueprint into a short list of fields, with the type, the rules and the options of each field. This is what an agent sees for a blog post:

```
blueprint post
  subtitle  text, max 120 characters
  intro     inline html without <p>, tags: <strong> <em> <a>
  text      blocks<heading | text | image | columns>
  date      date "YYYY-MM-DD", required
  category  one of "news" | "guide"
  source    url, only if category = "news"
  cover     files, list of UUIDs, max 1, from page.files
```

All of this comes from the blueprint. But a blueprint only says what Kirby accepts, not what you want. The agent doesn't know that the subtitle should be one short sentence, or that only your team may give a rating.

Custom fields are harder. Some plugins tell Kirby Agents the value format of their fields with a [field class](1_customization/1_field-types), and agents then see these fields like core fields. For all other custom fields, Kirby Agents doesn't know the value format, so it shows what it can find in the props:

```
blueprint product
  rating         custom field "rating", max 5, value int
  internalnotes  textarea, KirbyText with Markdown, label "Internal notes"
```

## Adding hints

Add an `agents` option to a field in your blueprint:

```yaml
# site/blueprints/pages/product.yml
fields:
  rating:
    type: rating
    max: 5
    agents:
      description: Our own test score. Only set it for products we tested ourselves
      example: 4
      as: number
  internalNotes:
    type: textarea
    agents:
      ignore: true
```

The agent now sees this:

```
blueprint product
  rating  number, max 5, note: Our own test score. Only set it for products we tested ourselves, example 4
```

The rating is a number with a note and an example, and the internal notes are gone. The Panel doesn't show the `agents` option, so editors see no difference.

## description

A note for the agent, up to 500 characters. It shows up as `note:` in the list of fields.

Write it like you would explain the field to a new editor: what the field is for, and what a good value looks like. The field already has a label and maybe a help text, so don't repeat them.

## example

An example value, shown as `example`. Agents follow the format of examples closely, so this helps most for fields with a special format, like a template with placeholders.

## as

Makes the field work like a field of another type. In the example above, `as: number` turns the custom `rating` field into a number field for agents. Kirby still stores the value with the `rating` field, like in the Panel.

Use a core field type of Kirby, or a type that has a [field class](1_customization/1_field-types). If a custom field type is used in many blueprints, a field class is less work than a hint on each field.

## ignore

Hides the field from agents. They don't see it when they read a page or its blueprint, and they can't change it. When an agent changes other fields of the page, Kirby keeps the value of the hidden field.

Don't hide required fields. Kirby checks all required fields when the changes are published, so publishing would fail with an error for a field that the agent doesn't know.

## Nested fields

Hints also work for fields in blocks, layouts, structures and objects. Here, the label of each link gets a note:

```yaml
links:
  type: structure
  fields:
    label:
      type: text
      required: true
      agents:
        description: Link text
```

There are two limits. When an agent replaces a whole blocks or structure field, the values of hidden fields in its blocks and rows are lost. Changes to single blocks and rows keep them. And Kirby removes the `agents` option from a blocks, layout or entries field inside another field, so hints on such a field have no effect.
