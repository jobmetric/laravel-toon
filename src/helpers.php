<?php

use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Exceptions\ToonEncodeException;
use JobMetric\Toon\ToonManager;

if (! function_exists('toon_encode')) {
    /**
     * Encode PHP data into a TOON string using the application Toon manager.
     *
     * @param mixed $data The data to encode.
     *
     * @return string
     * @throws ToonEncodeException
     */
    function toon_encode(mixed $data): string
    {
        return app(ToonManager::class)->encode($data);
    }
}

if (! function_exists('toon_decode')) {
    /**
     * Decode a TOON string back into PHP data using the application Toon manager.
     *
     * @param string $toon The TOON text to decode.
     *
     * @return mixed
     * @throws ToonDecodeException
     */
    function toon_decode(string $toon): mixed
    {
        return app(ToonManager::class)->decode($toon);
    }
}
