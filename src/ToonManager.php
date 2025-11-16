<?php

namespace JobMetric\Toon;

use JobMetric\Toon\Contracts\ToonManagerInterface;
use JobMetric\Toon\Support\ToonDecoder;
use JobMetric\Toon\Support\ToonEncoder;

/**
 * Class ToonManager
 *
 * High-level facade around ToonEncoder and ToonDecoder for application usage.
 */
class ToonManager implements ToonManagerInterface
{
    /**
     * Holds the encoder instance used for TOON encoding.
     *
     * @var ToonEncoder
     */
    protected ToonEncoder $encoder;

    /**
     * Holds the decoder instance used for TOON decoding.
     *
     * @var ToonDecoder
     */
    protected ToonDecoder $decoder;

    /**
     * ToonManager constructor.
     *
     * @param array<string,mixed> $config Configuration options for encoder and decoder.
     */
    public function __construct(array $config = [])
    {
        $this->encoder = new ToonEncoder($config);
        $this->decoder = new ToonDecoder($config);
    }

    /**
     * Encode PHP data into a TOON string.
     *
     * @param mixed $data The data to encode.
     *
     * @return string
     */
    public function encode(mixed $data): string
    {
        return $this->encoder->encode($data);
    }

    /**
     * Decode a TOON string back into PHP data.
     *
     * @param string $toon The TOON text to decode.
     *
     * @return mixed
     */
    public function decode(string $toon): mixed
    {
        return $this->decoder->decode($toon);
    }
}
