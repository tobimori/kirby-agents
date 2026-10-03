---
title: Custom Field Types
intro: Teach agents the value format of your own field types
---

Kirby Agents knows the value format of every core field type. It tells agents that a date is `YYYY-MM-DD`, which options a select field has, and which HTML tags a writer field allows. It also checks the input of agents, because Kirby stores some wrong values without an error. A select field, for example, drops a value that isn't one of its options.

A field type from a plugin is unknown to Kirby Agents. Take the `alt-text` field of [Kirby SEO](https://github.com/tobimori/kirby-seo): it stores an object with a text and a toggle for decorative images. The agent only sees this:

```
file blueprint image
  alttext  custom field "alt-text", label "Alt text"
```

The agent has to guess the format. If it guesses wrong, Kirby may store a value that the field can't show.

## When you need a field class

Many custom fields work without one. A field that extends a core field, like `'extends' => 'writer'` or a subclass of Kirby's `BlocksField`, works like that core field. If a custom field only needs a short explanation on a few blueprints, a [blueprint hint](1_customization/0_blueprint-hints) is enough.

Write a field class when the value has a format of its own, or when agents send input that Kirby would store without an error. With the class from the example below, the agent sees this:

```
file blueprint image
  alttext  alt text, object {"text": string, "decorative": boolean}, or only the text as a string, label "Alt text"
```

And when it sends an empty text for an image that isn't decorative, it gets an error before anything is saved:

```
Nothing was saved. Invalid values:
- alttext: send a text, or `decorative: true` for images without meaning
```

## Writing a field class

Extend `tobimori\Agents\Fields\Field`, or a core class like `TextField`, `WriterField` or `ObjectField` if your field is close to one of them. `$this->props` has the props of the field, as the Panel gets them.

Only `describe()` is required. It returns the format that agents see:

```php
<?php
// site/plugins/alt-text/classes/Agents/AltTextField.php

namespace Acme\Agents;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Fields\Field;
use tobimori\Agents\Schema\Compiler;

class AltTextField extends Field
{
  public function describe(Compiler $schema): string
  {
    return 'alt text, object {"text": string, "decorative": boolean}, or only the text as a string';
  }

  // agents like to send only the text, so accept that too
  public function input(mixed $value, mixed $current): mixed
  {
    $value = self::json($value);

    return is_string($value) ? ['text' => $value, 'decorative' => false] : $value;
  }

  // Kirby would store an empty alt text without an error
  public function check(mixed $value, InputCheck $check, string $where): void
  {
    if (is_array($value) && ($value['decorative'] ?? false) !== true && trim((string) ($value['text'] ?? '')) === '') {
      $check->error("{$where}: send a text, or `decorative: true` for images without meaning");
    }
  }

  // the short form when an agent reads the file
  public function summary(mixed $value, Presenter $presenter): string
  {
    return is_array($value) ? Presenter::short($value['text'] ?? '') : '(empty)';
  }
}
```

The class only adds what agents need. Kirby still validates and stores the value with your field, like in the Panel.

## Registering the class

If the field type comes from your plugin, register the class in the plugin with the key `tobimori.agents.fields`:

```php
<?php
// site/plugins/alt-text/index.php

load([
  'Acme\\Agents\\AltTextField' => __DIR__ . '/classes/Agents/AltTextField.php',
]);

Kirby::plugin('acme/alt-text', [
  'fields' => [
    'alt-text' => Acme\AltTextField::class,
  ],
  'tobimori.agents.fields' => [
    'alt-text' => Acme\Agents\AltTextField::class,
  ],
]);
```

Your plugin doesn't need Kirby Agents for this. Without it, Kirby ignores the key, and the class is never loaded. That's also why the class must be loaded lazily, with Kirby's `load()` or with Composer: a `require` in `index.php` would fail on sites without Kirby Agents, because the class extends a class of Kirby Agents.

For a field type from someone else's plugin, register the class in your `config.php` instead. This also replaces the class that a plugin registers:

```php
<?php
// site/config/config.php

return [
  'tobimori.agents' => [
    'fields' => [
      'alt-text' => Acme\Agents\AltTextField::class,
    ],
  ],
];
```

Sometimes a core class fits already. A custom rating field that stores a number can use the class of the number field, for all rating fields at once:

```php
'fields' => [
  'rating' => tobimori\Agents\Fields\NumberField::class,
],
```

## Methods

| Method                               | Default                   | Override it when                                                                                                                                    |
| ------------------------------------ | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `describe(Compiler $schema)`         | required                  | always. `$schema->expression($props)` describes a nested field, `$schema->type($kind, $name, $fields)` adds a named type like `block image`         |
| `input($value, $current)`            | the value                 | the value needs a conversion. `self::json()` decodes lists and objects that some clients send as JSON text                                          |
| `check($value, $check, $where)`      | nothing                   | Kirby would store invalid input without an error. Call `$check->error()`. Skip values that were in the content before with `!$check->isNew($value)` |
| `present($value, $path, $presenter)` | the value                 | the full value should look different. `replace` operations search in this value, so `input()` must accept it                                        |
| `summary($value, $presenter)`        | short text of `present()` | the short form should look different                                                                                                                |
| `preview($value, $presenter)`        | `summary()`               | the value in previews of blocks and rows should look different, or `null` to leave it out                                                           |
| `prominent()`                        | `false`                   | the field says what a block or row is about, so previews show it first                                                                              |
| `fieldSets()`                        | none                      | the field has nested fields                                                                                                                         |

Helpers for `describe()`: `$this->type()`, `$this->options()`, `$this->length()`, `$this->limits()`, `$this->count()` and `self::quote()`.

For fields with items that agents insert, move and number, like blocks or structures, extend `BlocksField` or `StructureField`. Look at the core classes in `site/plugins/kirby-agents/classes/Fields/` to see how they work.
