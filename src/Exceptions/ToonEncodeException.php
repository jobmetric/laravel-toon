<?php

namespace JobMetric\Toon\Exceptions;

use Exception;
use Throwable;

/**
 * Class ToonEncodeException
 *
 * Represents failures that occur while converting PHP data structures
 * into the TOON format. This exception is thrown when an unsupported
 * type is encountered, the data model violates TOON's structural rules,
 * or the encoder detects invalid tabular row consistency.
 */
class ToonEncodeException extends Exception
{
    /**
     * Create a new ToonEncodeException instance.
     *
     * @param string $message          Error message describing the encoding issue.
     * @param int $code                Optional error code.
     * @param Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
