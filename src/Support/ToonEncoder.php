<?php

namespace JobMetric\Toon\Support;

use DateTimeInterface;
use JobMetric\Toon\Exceptions\ToonEncodeException;

/**
 * Class ToonEncoder
 *
 * Encodes PHP data structures into the TOON notation.
 * This encoder focuses on providing a token-efficient representation
 * suitable for LLM prompts while preserving the original JSON data model.
 */
class ToonEncoder
{
    /**
     * Holds configuration options that tune the encoding behavior.
     *
     * Supported options:
     * - indent: int (default: 2)          Number of spaces per indentation level.
     * - delimiter: string (default: ",")  Delimiter used between tabular values.
     * - min_rows_tabular: int (default: 2) Minimum row count to switch to tabular encoding.
     *
     * @var array<string,mixed>
     */
    protected array $config;

    /**
     * ToonEncoder constructor.
     *
     * Stores the configuration that shapes how TOON output is produced.
     *
     * @param array<string,mixed> $config Configuration options for encoding.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'indent'           => 2,
            'delimiter'        => ',',
            'min_rows_tabular' => 2,
        ], $config);
    }

    /**
     * Encode arbitrary PHP data into a TOON string.
     *
     * This is the main entry point for converting JSON-decoded data or native
     * PHP values into TOON text. The output is trimmed and ends with a newline
     * so it is safe to print directly.
     *
     * @param mixed $data The data to encode.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    public function encode(mixed $data): string
    {
        $result = rtrim($this->encodeValue($data, 0));

        return $result . "\n";
    }

    /**
     * Recursively encodes a PHP value into TOON notation.
     *
     * The encoder dispatches based on value type:
     * - Scalars: encoded with minimal quoting rules.
     * - Associative arrays: encoded as indented key/value blocks.
     * - Indexed arrays: encoded as primitive arrays, tabular arrays, or mixed arrays.
     *
     * @param mixed $value The value to encode.
     * @param int $indent  Current indentation level.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    protected function encodeValue(mixed $value, int $indent): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->encodeString($value->format(DATE_ATOM));
        }

        if ($this->isJsonScalarCompatible($value)) {
            return $this->encodeScalar($value);
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            if ($this->isAssoc($value)) {
                return $this->encodeAssocObject($value, $indent);
            }

            return $this->encodeListArray($value, $indent);
        }

        throw new ToonEncodeException('Unsupported data type for TOON encoding.');
    }

    /**
     * Encodes a scalar value using minimal quoting rules.
     *
     * @param mixed $value Scalar value (null, bool, int, float, string).
     *
     * @return string
     */
    protected function encodeScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return 'null';
            }

            if ($value == 0.0) {
                return '0';
            }

            return (string) $value;
        }

        return $this->encodeString((string) $value);
    }

    /**
     * Encodes a string while applying TOON-style minimal quoting.
     *
     * Strings are quoted only when necessary, such as when they:
     * - are empty
     * - contain whitespace
     * - contain delimiters or structural characters
     * - match reserved keywords or numeric patterns
     *
     * @param string $value The raw string value.
     *
     * @return string
     */
    protected function encodeString(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        $delimiter = (string) $this->config['delimiter'];

        $needsQuotes = false;

        if (preg_match('/\s/', $value)) {
            $needsQuotes = true;
        }

        if (str_contains($value, ':') || str_contains($value, '{') || str_contains($value, '}') || str_contains($value, '[') || str_contains($value, ']') || str_contains($value, $delimiter)) {
            $needsQuotes = true;
        }

        if ($value === 'null' || $value === 'true' || $value === 'false') {
            $needsQuotes = true;
        }

        if (is_numeric($value)) {
            $needsQuotes = true;
        }

        if (preg_match('/[[:cntrl:]]/', $value)) {
            $needsQuotes = true;
        }

        if (! $needsQuotes) {
            return $value;
        }

        $escaped = str_replace('"', '\"', $value);

        return '"' . $escaped . '"';
    }

    /**
     * Encodes an associative array as an indented TOON object.
     *
     * Each key becomes a line with "key: value" when the value is scalar,
     * or "key:" followed by an indented block when the value is a nested structure.
     *
     * @param array<string,mixed> $data Associative array data.
     * @param int $indent               Current indentation level.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    protected function encodeAssocObject(array $data, int $indent): string
    {
        $lines = [];
        $prefix = $this->indent($indent);

        foreach ($data as $key => $value) {
            $encodedValue = $this->encodeValue($value, $indent + 1);

            if ($this->isMultiline($encodedValue)) {
                $lines[] = $prefix . $key . ':';
                $lines[] = $this->indentBlock($encodedValue, $indent + 1);
            }
            else {
                $lines[] = $prefix . $key . ': ' . $encodedValue;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Encodes an indexed array, choosing between primitive, tabular, or mixed representation.
     *
     * - Primitive array: all elements are scalars → "[n]: v1,v2,v3"
     * - Tabular array: list of uniform associative arrays → "[n,]{k1,k2}:" + rows
     * - Mixed array: anything else → "[n]:\n  - value"
     *
     * @param array<int,mixed> $data Indexed array data.
     * @param int $indent            Current indentation level.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    protected function encodeListArray(array $data, int $indent): string
    {
        if ($this->allScalars($data)) {
            return $this->encodePrimitiveArray($data, $indent);
        }

        if ($this->isListOfUniformAssocArrays($data)) {
            $minRows = (int) $this->config['min_rows_tabular'];

            if (count($data) >= $minRows) {
                return $this->encodeTabularArray($data, $indent);
            }
        }

        return $this->encodeMixedArray($data, $indent);
    }

    /**
     * Encodes an indexed array of scalar values in compact form.
     *
     * Example:
     * [1, 2, 3] -> "[3]: 1,2,3"
     *
     * @param array<int,scalar|null> $data Indexed scalar values.
     * @param int $indent                  Current indentation level.
     *
     * @return string
     */
    protected function encodePrimitiveArray(array $data, int $indent): string
    {
        $prefix = $this->indent($indent);
        $count = count($data);
        $delimiter = (string) $this->config['delimiter'];

        $encodedValues = [];

        foreach ($data as $value) {
            $encodedValues[] = $this->encodeScalar($value);
        }

        $joined = implode($delimiter, $encodedValues);

        return $prefix . '[' . $count . ']:' . ' ' . $joined;
    }

    /**
     * Encodes a tabular array of uniform associative arrays.
     *
     * Example:
     * [
     *   ['id' => 1, 'name' => 'Alice'],
     *   ['id' => 2, 'name' => 'Bob'],
     * ]
     * ->
     * [2,]{id,name}:
     *   1,Alice
     *   2,Bob
     *
     * @param array<int,array<string,scalar|null>> $rows List of row arrays.
     * @param int $indent                                Current indentation level.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    protected function encodeTabularArray(array $rows, int $indent): string
    {
        if ($rows === []) {
            throw new ToonEncodeException('Cannot encode an empty tabular array.');
        }

        $keys = array_keys($rows[0]);
        $count = count($rows);
        $delimiter = (string) $this->config['delimiter'];

        $prefix = $this->indent($indent);
        $childPrefix = $this->indent($indent + 1);

        $header = $prefix . '[' . $count . ',]{' . implode(',', $keys) . '}:';
        $lines = [$header];

        foreach ($rows as $row) {
            $values = [];

            foreach ($keys as $key) {
                if (! array_key_exists($key, $row)) {
                    throw new ToonEncodeException('Tabular row is missing expected key: ' . $key);
                }

                $cell = $row[$key];

                if (! $this->isScalarOrNull($cell)) {
                    throw new ToonEncodeException('Tabular arrays must contain only scalar values.');
                }

                $values[] = $this->encodeScalar($cell);
            }

            $lines[] = $childPrefix . implode($delimiter, $values);
        }

        return implode("\n", $lines);
    }

    /**
     * Encodes a mixed array into a block with item markers.
     *
     * Example:
     * [ {"x": 1}, 42, "hi" ]
     * ->
     * [3]:
     *   - x: 1
     *   - 42
     *   - hi
     *
     * @param array<int,mixed> $data Indexed mixed values.
     * @param int $indent            Current indentation level.
     *
     * @return string
     *
     * @throws ToonEncodeException
     */
    protected function encodeMixedArray(array $data, int $indent): string
    {
        $prefix = $this->indent($indent);
        $childPrefix = $this->indent($indent + 1);
        $count = count($data);

        $lines = [];
        $lines[] = $prefix . '[' . $count . ']:';

        foreach ($data as $value) {
            $encodedValue = $this->encodeValue($value, $indent + 1);

            if ($this->isMultiline($encodedValue)) {
                $firstLine = $childPrefix . '- ' . $this->firstLine($encodedValue);
                $rest = $this->restLines($encodedValue);

                $lines[] = $firstLine;

                if ($rest !== '') {
                    $lines[] = $this->indentBlock($rest, $indent + 2);
                }
            }
            else {
                $lines[] = $childPrefix . '- ' . $encodedValue;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Determines whether the given array is associative.
     *
     * @param array<mixed> $array The array to inspect.
     *
     * @return bool
     */
    protected function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Checks whether all elements in the array are scalar or null.
     *
     * @param array<mixed> $array The array to inspect.
     *
     * @return bool
     */
    protected function allScalars(array $array): bool
    {
        foreach ($array as $value) {
            if (! $this->isScalarOrNull($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines whether the given value is scalar or null.
     *
     * @param mixed $value The value to inspect.
     *
     * @return bool
     */
    protected function isScalarOrNull(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * Determines whether the array is a list of associative arrays
     * with identical keys in the same order.
     *
     * @param array<mixed> $array The array to inspect.
     *
     * @return bool
     */
    protected function isListOfUniformAssocArrays(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        $first = $array[0];

        if (! is_array($first) || ! $this->isAssoc($first)) {
            return false;
        }

        $firstKeys = array_keys($first);

        foreach ($array as $item) {
            if (! is_array($item) || ! $this->isAssoc($item)) {
                return false;
            }

            if (array_keys($item) !== $firstKeys) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds an indentation string for the given level.
     *
     * @param int $indent The indentation level.
     *
     * @return string
     */
    protected function indent(int $indent): string
    {
        $size = (int) $this->config['indent'];

        if ($indent <= 0 || $size <= 0) {
            return '';
        }

        return str_repeat(' ', $indent * $size);
    }

    /**
     * Determines whether a string spans multiple lines.
     *
     * @param string $value The text to inspect.
     *
     * @return bool
     */
    protected function isMultiline(string $value): bool
    {
        return str_contains($value, "\n");
    }

    /**
     * Indents every line of a block by the given indentation level.
     *
     * @param string $block The text block to indent.
     * @param int $indent   The indentation level.
     *
     * @return string
     */
    protected function indentBlock(string $block, int $indent): string
    {
        $lines = explode("\n", $block);
        $prefix = $this->indent($indent);

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $lines[$index] = $prefix . $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Returns the first line of a multi-line string.
     *
     * @param string $value The text to inspect.
     *
     * @return string
     */
    protected function firstLine(string $value): string
    {
        $parts = explode("\n", $value);

        return $parts[0];
    }

    /**
     * Returns all lines except the first one from a multi-line string.
     *
     * @param string $value The text to inspect.
     *
     * @return string
     */
    protected function restLines(string $value): string
    {
        $parts = explode("\n", $value);

        array_shift($parts);

        return implode("\n", $parts);
    }

    /**
     * Determines whether a value is a JSON-compatible scalar type.
     *
     * @param mixed $value The value to inspect.
     *
     * @return bool
     */
    protected function isJsonScalarCompatible(mixed $value): bool
    {
        return $value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }
}
