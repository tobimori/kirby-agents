---
title: Custom field types
intro: Teach agents the value format of a field type from your site or plugin
---

Kirby Agents knows all core field types. For each field, it shows agents a short description of the value, checks their input, and shows the value in a short form when an agent reads a page.

Custom field types often need no code. A field that extends a core field, like `'extends' => 'writer'` or a subclass of Kirby's `BlocksField`, works like that core field. Other custom fields show their options, limits and default value, and a [blueprint hint](2_customization/0_blueprint-hints) can add a description and an example.

Write a field class when agents need a value format that the blueprint can't tell, or when their input needs a check or a conversion.

## Register a field class

In a plugin, add the key `tobimori.agents.fields` to the plugin definition:

```php
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

Your plugin doesn't depend on Kirby Agents: without it, Kirby ignores the key. Kirby Agents loads the class only when it needs it, so the order of the plugins doesn't matter. Load the class lazily, with Kirby's `load()` or with Composer, and not with `require`, because it extends a class of Kirby Agents.

On a site, use the `fields` option instead. It also replaces the class of a plugin:

```php
// site/config/config.php
return [
  'tobimori.agents' => [
    'fields' => [
      'alt-text' => Acme\Agents\AltTextField::class,
      'rating' => tobimori\Agents\Fields\NumberField::class,
    ],
  ],
];
```

The second line uses the class of a core type. This has the same effect as the blueprint hint `as: number`, but for all fields of the type.

## Write a field class

Extend `tobimori\Agents\Fields\Field`, or a core class like `TextField`, `WriterField` or `ObjectField`. `$this->props` has the props of the field, as the Panel gets them. Only `describe()` is required:

```php
namespace Acme\Agents;

use tobimori\Agents\Content\InputCheck;
use tobimori\Agents\Content\Presenter;
use tobimori\Agents\Fields\Field;
use tobimori\Agents\Schema\Compiler;

class AltTextField extends Field
{
  // the value format that agents see
  public function describe(Compiler $schema): string
  {
    return 'alt text, object {"text": string, "decorative": boolean}, or only the text as a string';
  }

  // converts the input of the agent before Kirby gets it
  public function input(mixed $value, mixed $current): mixed
  {
    $value = self::json($value);

    return is_string($value) ? ['text' => $value, 'decorative' => false] : $value;
  }

  // finds input that Kirby would store without an error
  public function check(mixed $value, InputCheck $check, string $where): void
  {
    if (is_array($value) && ($value['decorative'] ?? false) !== true && trim((string) ($value['text'] ?? '')) === '') {
      $check->error("{$where}: send a text, or `decorative: true` for images without meaning");
    }
  }

  // the short form of the value when an agent reads the page
  public function summary(mixed $value, Presenter $presenter): string
  {
    return is_array($value) ? Presenter::short($value['text'] ?? '') : '(empty)';
  }
}
```

Kirby still validates and stores the value with your Kirby field, like in the Panel. The class only adds what agents need.

## Methods

| Method                               | Default                   | Override it when                                                                                                                                    |
| ------------------------------------ | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `describe(Compiler $schema)`         | required                  | always. `$schema->expression($props)` describes a nested field, `$schema->type($kind, $name, $fields)` adds a named type like `block image`         |
| `input($value, $current)`            | the value                 | the value needs a conversion. `self::json()` decodes lists and objects that some clients send as JSON text                                          |
| `check($value, $check, $where)`      | nothing                   | Kirby would store invalid input without an error. Call `$check->error()`. Skip values that were in the content before with `!$check->isNew($value)` |
| `present($value, $path, $presenter)` | the value                 | the full value should look different                                                                                                                |
| `summary($value, $presenter)`        | short text of `present()` | the short form should look different                                                                                                                |
| `preview($value, $presenter)`        | `summary()`               | the value in previews of blocks and rows should look different, or `null` to leave it out                                                           |
| `prominent()`                        | `false`                   | the field says what a block or row is about, so previews show it first                                                                              |
| `fieldSets()`                        | none                      | the field has nested fields                                                                                                                         |

Helpers for `describe()`: `$this->type()`, `$this->options()`, `$this->length()`, `$this->limits()`, `$this->count()` and `self::quote()`.

For fields with items that agents insert, move and number, like blocks or structures, extend `BlocksField` or `StructureField`.
