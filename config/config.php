<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum rows for tabular encoding
    |--------------------------------------------------------------------------
    |
    | When an array of associative arrays is detected and the number of rows
    | is greater than or equal to this value, the encoder may choose to use
    | the compact tabular TOON representation.
    |
    */

    'min_rows_tabular' => 2,

    /*
    |--------------------------------------------------------------------------
    | Throw on decode error
    |--------------------------------------------------------------------------
    |
    | When enabled, invalid TOON input will result in a ToonDecodeException.
    | When disabled, the decoder should return null on invalid input.
    |
    */

    'throw_on_decode_error' => true,
];
