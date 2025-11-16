<?php

namespace JobMetric\Toon\Exceptions;

use Exception;
use Throwable;

/**
 * Class ToonDecodeException
 *
 * Represents failures that occur while parsing a TOON string back into
 * PHP data. This exception is thrown when the decoder encounters invalid
 * syntax, inconsistent indentation, malformed tabular definitions,
 * or unexpected token patterns in the TOON text.
 */
class ToonDecodeException extends Exception
{
    /**
     * Create a new ToonDecodeException instance.
     *
     * @param string $message          Error message describing the decoding issue.
     * @param int $code                Optional error code.
     * @param Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
