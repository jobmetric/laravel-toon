<?php

use JobMetric\Toon\Contracts\ToonManagerInterface;

if (! function_exists('toon_encode')) {
    /**
     * Encode PHP data into a TOON string using the application Toon manager.
     *
     * @param mixed $data The data to encode.
     *
     * @return string
     */
    function toon_encode(mixed $data): string
    {
        return app(ToonManagerInterface::class)->encode($data);
    }
}

if (! function_exists('toon_decode')) {
    /**
     * Decode a TOON string back into PHP data using the application Toon manager.
     *
     * @param string $toon The TOON text to decode.
     *
     * @return mixed
     */
    function toon_decode(string $toon): mixed
    {
        return app(ToonManagerInterface::class)->decode($toon);
    }
}
