[contributors-shield]: https://img.shields.io/github/contributors/jobmetric/laravel-toon.svg?style=for-the-badge
[contributors-url]: https://github.com/jobmetric/laravel-toon/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/jobmetric/laravel-toon.svg?style=for-the-badge&label=Fork
[forks-url]: https://github.com/jobmetric/laravel-toon/network/members
[stars-shield]: https://img.shields.io/github/stars/jobmetric/laravel-toon.svg?style=for-the-badge
[stars-url]: https://github.com/jobmetric/laravel-toon/stargazers
[license-shield]: https://img.shields.io/github/license/jobmetric/laravel-toon.svg?style=for-the-badge
[license-url]: https://github.com/jobmetric/laravel-toon/blob/master/LICENCE.md
[linkedin-shield]: https://img.shields.io/badge/-LinkedIn-blue.svg?style=for-the-badge&logo=linkedin&colorB=555
[linkedin-url]: https://linkedin.com/in/majidmohammadian

[![Contributors][contributors-shield]][contributors-url]
[![Forks][forks-shield]][forks-url]
[![Stargazers][stars-shield]][stars-url]
[![MIT License][license-shield]][license-url]
[![LinkedIn][linkedin-shield]][linkedin-url]

# Laravel Toon Converter (json to toon for LLMs)

Laravel integration for TOON format: encode/decode JSON data into a token‑optimized notation for LLMs. This package provides first‑class TOON support in Laravel with spec‑conformant encoding/decoding, smart tabular formatting, safe key folding, and developer‑friendly APIs (Facade, helpers, DI).

## Requirements

- PHP >= 8.0.1
- Laravel >= 9.19
- Composer

## Installation

Install via Composer:

```bash
composer require jobmetric/laravel-toon
```

The service provider is auto‑discovered. The `Toon` facade and global helpers are registered.

## Configuration

Publish/override the `laravel-toon` config as needed. Available options (defaults in parentheses):

- `indent` (2): spaces per indent level (encoder/decoder)
- `delimiter` (','): array delimiter — one of `,`, `\t`, `|`
  - Non‑default delimiter appears in headers, e.g. `[3|]: 1|2|3` or `[2\t]{id\tname}:`
- `min_rows_tabular` (1): if list of uniform objects has ≥ this count, use tabular format
- `newline_final` (false): append trailing newline on encode
- `key_folding` ('off'): 'off' or 'safe' — fold single‑key chains into dotted path when safe
- `flatten_depth` (-1): max depth of folding (`-1` = unlimited)
- `folding_exclude` ([]): prefixes to opt‑out from folding
- `expand_paths` (false): expand dotted keys on decode (safe segments only)
- `throw_on_decode_error` (true): throw on invalid TOON; otherwise return `null`
- `numbers_as_strings` (false): decode numbers as strings instead of numeric types
- `spec_strict` (true): prefer strict spec behavior (no trailing newline, stricter normalization)

## Features

- Spec‑Conformant Headers
  - Primitive arrays: `[n]: v1,v2,v3`
  - Tabular arrays: `[n]{k1,k2}:` then rows indented one level
  - Delimiter suffix for non‑default delimiters in headers: `[n|]`, `[n\t]`
  - Empty arrays: `[0]:`
- Smart Tabular Encoding
  - Detects uniform lists of objects; emits compact tabular blocks with header fields
  - Human‑readable and token‑efficient (ideal for LLM prompts)
- Mixed Arrays with Nested Items
  - Renders `[n]:` then list items with `- `, and handles nested multi‑line blocks with correct indentation
- Safe Key Folding (optional)
  - `key_folding='safe'` flattens chains of single‑key nested objects into dot paths when safe; `flatten_depth` and `folding_exclude` supported
- Safe Path Expansion (optional)
  - `expand_paths=true` expands dotted keys into nested objects on decode (safe segments only)
- Robust Decoding and Validation
  - Strict indentation checks; array length and tabular row count validation
  - Configurable error handling via `throw_on_decode_error`
- Predictable String/Numeric Handling
  - Spec‑style quoting/escaping; optional `numbers_as_strings` mode
- First‑Class Laravel Integration
  - Facade `Toon::encode` / `Toon::decode`
  - Global helpers `toon_encode` / `toon_decode`
  - Container singleton and alias `'Toon'` for DI

## Usage

### Facade

```php
use JobMetric\Toon\Facades\Toon;

$data = [
    'id' => 42,
    'tags' => ['x', 'y'],
    'users' => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
];

$toon = Toon::encode($data);
// [2]{id,name}:
//   1,Alice
//   2,Bob

$decoded = Toon::decode($toon);
// same as $data
```

### Helpers

```php
$encoded = toon_encode(['numbers' => [1, 2, 3]]);
// [3]: 1,2,3

$decoded = toon_decode($encoded);
```

### Container/DI

```php
use JobMetric\Toon\ToonManager;

$toon = app(ToonManager::class)->encode(['items' => ['a', 'b']]);
// items[2]: a,b
```

### Custom Delimiter

```php
config()->set('laravel-toon.delimiter', '|');

echo Toon::encode(['nums' => [1,2,3]]);
// nums[3|]: 1|2|3
```

### Safe Key Folding + Path Expansion

```php
config()->set('laravel-toon.key_folding', 'safe');
config()->set('laravel-toon.expand_paths', true);

$encoded = Toon::encode(['a' => ['b' => ['c' => 1]]]);
// a.b.c: 1
$decoded = Toon::decode($encoded);
// ['a' => ['b' => ['c' => 1]]]
```

### Error Handling

```php
config()->set('laravel-toon.throw_on_decode_error', false);
$decoded = toon_decode("id:\n   name: Alice\n"); // null (no exception)
```

### Numbers as Strings

```php
config()->set('laravel-toon.numbers_as_strings', true);

$decoded = Toon::decode('n: 12345678901234567890');
// ['n' => '12345678901234567890']
```

## Spec Notes (Parity)

- Headers only include delimiter suffix when non‑default (`,` is implicit): `[3|]`, `[2\t]`.
- Tabular header fields are delimited using the same active delimiter.
- Strings are quoted only when necessary (whitespace, delimiter present, structural chars, looks like literal/number, control chars, backslash).
- Keys are unquoted when safe; dotted keys allowed when each segment is a safe identifier.
- Decoder enforces indentation width to be multiples of `indent` (default `2`).

## Why jobmetric/laravel-toon

- Spec‑accurate TOON for Laravel (delimiter‑aware headers, robust parsing)
- Token‑efficient formatting — especially tabular encoding
- Safety controls (folding/expand for safe identifiers, strict indentation)
- Predictable numeric/string handling (optional strings for large numbers)
- Developer experience: Facade, helpers, alias, simple config

## Contributing

Thank you for considering contributing to Laravel Toon! Please see [CONTRIBUTING.md](https://github.com/jobmetric/laravel-toon/blob/master/CONTRIBUTING.md).

## License

The MIT License (MIT). See [LICENCE.md][license-url] for details.
