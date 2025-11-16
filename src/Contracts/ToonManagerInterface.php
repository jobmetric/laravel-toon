<?php

namespace JobMetric\Toon\Contracts;

/**
 * Interface ToonManagerInterface
 *
 * Defines the high-level contract for TOON encoding and decoding operations.
 */
interface ToonManagerInterface
{
    /**
     * Encode PHP data into a TOON string.
     *
     * @param mixed $data The data to encode.
     *
     * @return string
     */
    public function encode(mixed $data): string;

    /**
     * Decode a TOON string back into PHP data.
     *
     * @param string $toon The TOON text to decode.
     *
     * @return mixed
     */
    public function decode(string $toon): mixed;
}
