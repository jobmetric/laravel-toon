<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Indentation (spaces per level)
    |--------------------------------------------------------------------------
    |
    | Number of spaces used per logical indentation level when encoding.
    | The decoder also validates indentation widths against this value.
    | Typical value is 2. Using other values requires your TOON inputs
    | to strictly follow the same indentation width.
    |
    */
    'indent' => 2,

    /*
    |--------------------------------------------------------------------------
    | Array delimiter
    |--------------------------------------------------------------------------
    |
    | Delimiter used between primitive values in arrays and tabular rows.
    | Supported values: ',', "\t" (tab), '|' (pipe).
    | When a non-default delimiter is used, it will be surfaced in headers,
    | e.g. "[3|]: 1|2|3" or "[2\t]{id\tname}: ...".
    |
    */
    'delimiter' => ',',

    /*
    |--------------------------------------------------------------------------
    | Minimum rows for tabular encoding
    |--------------------------------------------------------------------------
    |
    | When a list of associative arrays is detected and the number of rows
    | is greater than or equal to this value, the encoder may format it as
    | a compact tabular block: "[n]{k1,k2}:\n  v1,v2". Set to 1 to always
    | choose tabular for uniform object rows.
    |
    */
    'min_rows_tabular' => 1,

    /*
    |--------------------------------------------------------------------------
    | Throw on decode error
    |--------------------------------------------------------------------------
    |
    | When enabled, invalid TOON input raises ToonDecodeException.
    | When disabled, decoder returns null on invalid input instead of throwing.
    |
    */
    'throw_on_decode_error' => true,

    /*
    |--------------------------------------------------------------------------
    | Spec strict mode
    |--------------------------------------------------------------------------
    |
    | When enabled, encoder/decoder adhere closely to the official TOON spec
    | (e.g., no trailing newline, stricter normalization). This can also
    | affect defaults like key folding and path expansion.
    |
    */
    'spec_strict' => true,

    /*
    |--------------------------------------------------------------------------
    | Final newline in encoder output
    |--------------------------------------------------------------------------
    |
    | When true, encoder appends a trailing newline to output strings.
    | Set to false to align with fixtures that avoid trailing newlines.
    |
    */
    'newline_final' => false,

    /*
    |--------------------------------------------------------------------------
    | Key folding options
    |--------------------------------------------------------------------------
    |
    | key_folding: 'off' | 'safe'
    |   - off  : do not fold nested single-key objects
    |   - safe : fold chains of single-key objects with safe identifier segments
    |
    | flatten_depth: maximum depth of folding (use -1 for unlimited)
    |
    | folding_exclude: list of key prefixes which should be excluded
    | from folding when key_folding is 'safe'. Useful to opt-out
    | specific paths that must remain expanded.
    |
    */
    'key_folding' => 'off',
    'flatten_depth' => -1,
    'folding_exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Expand dot paths on decode
    |--------------------------------------------------------------------------
    |
    | When enabled, keys like "a.b.c" will expand to nested objects on decode.
    | Only safe identifier segments are expanded (A-Za-z_ followed by
    | alphanumerics/underscores). Otherwise, the dotted key is assigned literally.
    |
    */
    'expand_paths' => false,

    /*
    |--------------------------------------------------------------------------
    | Numbers as strings
    |--------------------------------------------------------------------------
    |
    | When enabled, numeric tokens (e.g., 123, 1.5) are decoded as strings
    | instead of PHP integers/floats. Useful when preserving large integers
    | without precision loss or when exact string forms are required.
    |
    */
    'numbers_as_strings' => false,
];
