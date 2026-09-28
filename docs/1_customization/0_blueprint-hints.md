---
title: Blueprint hints
intro: Tell agents how to fill a field, or hide it from them
---

Agents read your blueprints like the Panel does: labels, help texts, options, limits and required fields. For most fields, that's enough. When a field needs more explanation, add an `agents` option to the field in the blueprint.

```yaml
# site/blueprints/pages/product.yml
fields:
  rating:
    type: rating
    agents:
      description: Stars from 1 to 5, only for products we tested ourselves
      example: 4
      as: number
  internalNotes:
    type: textarea
    agents:
      ignore: true
```

## description

A note for agents. It can be longer than a help text, up to 500 characters, because only agents read it. Write what an editor would explain to a new colleague: what the field is for, and what a good value looks like.

## example

An example value. Agents copy the format of examples well, so this helps most for fields with a special format.

## as

Treat the field like a field of another type, for example `number` for a custom rating field. Use a core type of Kirby, or a type that has a [field class](1_customization/1_field-types).

## ignore

Hide the field from agents. They don't see it and can't change it, and Kirby keeps its value when an agent changes other fields. Don't hide required fields: publishing then fails with an error for a field that the agent doesn't know.

## Hints in nested fields

Hints also work for fields in blocks, structures and objects. When an agent replaces a whole blocks or structure field, the values of hidden fields in its blocks and rows are lost. Changes to single blocks and rows keep them.

Kirby has one limit: a blocks, layout or entries field inside another field loses its `agents` option, so hints on such a field have no effect.
