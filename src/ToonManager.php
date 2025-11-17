<?php

namespace JobMetric\Toon;

use DateTimeInterface;
use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Exceptions\ToonEncodeException;

/**
 * ToonManager
 *
 * Single service responsible for encoding PHP values to TOON format and
 * decoding TOON text back to PHP values. Behavior aligns with the upstream
 * toon-format spec (header shapes, delimiters, quoting, tabular arrays,
 * mixed arrays, path folding/expansion when enabled).
 */
class ToonManager
{
    /**
     * Runtime configuration for encoding/decoding.
     *
     * Known keys (all optional):
     * - indent: int (default 2)
     * - delimiter: string ',', "\t" or '|'
     * - min_rows_tabular: int (default 1)
     * - newline_final: bool (default false)
     * - key_folding: 'off'|'safe' (default 'off')
     * - flatten_depth: int (default -1 → unlimited)
     * - folding_exclude: array<string> (prefixes not to fold)
     * - throw_on_decode_error: bool (default true)
     * - expand_paths: bool (default false)
     * - numbers_as_strings: bool (default false)
     * - spec_strict: bool (default true)
     *
     * @var array<string,mixed>
     */
    protected array $config = [];

    /**
     * Parsed input lines with logical indentation and trimmed text.
     *
     * Each item: ['indent' => int, 'text' => string]
     *
     * @var array<int,array{indent:int,text:string}>
     */
    protected array $lines = [];

    /**
     * Count of parsed, non-empty logical lines.
     *
     * @var int
     */
    protected int $lineCount = 0;

    /**
     * Construct the manager with optional configuration.
     *
     * Important options (with defaults):
     * - indent: 2
     * - delimiter: "," (supports ',', "\t", '|')
     * - newline_final: false
     * - min_rows_tabular: 1
     * - key_folding: 'off' | 'safe'
     * - flatten_depth: -1 (unlimited)
     * - expand_paths: false (when true, safe dotted keys expand on decode)
     * - throw_on_decode_error: true
     *
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        if ($config === []) {
            $config = (array) config('laravel-toon', []);
        }
        if (($config['spec_strict'] ?? false) === true) {
            $config = array_replace([
                'newline_final' => false,
                'key_folding'   => 'off',
                'flatten_depth' => -1,
                'expand_paths'  => false,
            ], $config);
        }

        $this->config = array_merge([
            'indent'                => 2,
            'delimiter'             => ',',
            'min_rows_tabular'      => 1,
            'newline_final'         => false,
            'key_folding'           => 'off',
            'flatten_depth'         => -1,
            'folding_exclude'       => [],
            'throw_on_decode_error' => true,
            'expand_paths'          => false,
            'numbers_as_strings'    => false,
            'spec_strict'           => true,
        ], $config);
        $this->config['delimiter'] = $this->sanitizeDelimiter((string) ($this->config['delimiter'] ?? ','));
    }

    /**
     * Encode PHP data into a TOON string.
     *
     * @param mixed $data Any PHP value (scalars, arrays, associative arrays)
     *
     * @return string TOON-formatted string (no trailing newline by default)
     * @throws ToonEncodeException On unsupported types or structural violations
     */
    public function encode(mixed $data): string
    {
        $this->refreshRuntimeConfig();

        return $this->encodeInternal($data);
    }

    /**
     * Decode TOON text into a PHP value.
     *
     * @param string $toon TOON-formatted string
     *
     * @return mixed PHP value (array, associative array, scalar or null)
     * @throws ToonDecodeException When throw_on_decode_error=true and input is invalid
     */
    public function decode(string $toon): mixed
    {
        $this->refreshRuntimeConfig();

        return $this->decodeInternal($toon);
    }

    /**
     * Refresh runtime configuration from config('laravel-toon').
     * Normalizes delimiter to a spec-supported value.
     *
     * @return void
     */
    protected function refreshRuntimeConfig(): void
    {
        $latest = (array) config('laravel-toon', []);
        if ($latest) {
            $this->config = array_replace($this->config, $latest);
            $this->config['delimiter'] = $this->sanitizeDelimiter((string) ($this->config['delimiter'] ?? ','));
        }
    }

    /**
     * Ensure delimiter is one of ',', "\t", or '|'.
     *
     * @param string $delimiter Proposed delimiter
     *
     * @return string Normalized delimiter
     */
    protected function sanitizeDelimiter(string $delimiter): string
    {
        return ($delimiter === '\t' || $delimiter === '|' || $delimiter === ',') ? $delimiter : ',';
    }

    // Internal method placeholders (implemented in subsequent patches)
    /**
     * Encode dispatcher that appends optional trailing newline per config.
     *
     * @param mixed $data Any PHP value
     *
     * @return string TOON string
     * @throws ToonEncodeException
     */
    protected function encodeInternal(mixed $data): string
    {
        $result = rtrim($this->encodeValue($data, 0));
        $finalNewline = $this->config['newline_final'] ?? true;

        return $finalNewline ? ($result . "\n") : $result;
    }
    /**
     * Decode dispatcher that prepares lines and parses at root indent.
     *
     * @param string $toon TOON text
     *
     * @return mixed Decoded PHP value
     * @throws ToonDecodeException On invalid input when throwing is enabled
     */
    protected function decodeInternal(string $toon): mixed
    {
        $trimmed = trim($toon);
        if ($trimmed === '') {
            if (($this->config['spec_strict'] ?? false) === true)
                return [];

            return null;
        }
        try {
            $this->prepareLines($trimmed);
            $index = 0;

            return $this->parseValueAtIndent(0, $index);
        } catch (ToonDecodeException $e) {
            if (! ($this->config['throw_on_decode_error'] ?? true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Encode any PHP value into a TOON fragment recursively.
     *
     * @param mixed $value Value to encode
     * @param int $indent  Current indent level (logical levels)
     *
     * @return string TOON fragment
     * @throws ToonEncodeException On unsupported types or invalid structures
     */
    protected function encodeValue(mixed $value, int $indent): string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->encodeStringStrict($value->format(DATE_ATOM));
        }
        if ($this->isJsonScalarCompatible($value)) {
            return $this->encodeScalar($value);
        }
        if (is_array($value)) {
            if ($value === []) {
                $delimiter = (string) ($this->config['delimiter'] ?? ',');
                $bracket = '[0' . ($delimiter !== ',' ? $delimiter : '') . ']:';

                return $bracket;
            }
            if ($this->isAssoc($value)) {
                return $this->encodeAssocObject($value, $indent);
            }

            return $this->encodeListArray($value, $indent);
        }
        throw new ToonEncodeException('Unsupported data type for TOON encoding.');
    }

    /**
     * Encode a JSON-compatible scalar (null, bool, int, float, string).
     *
     * @param mixed $value Scalar value
     *
     * @return string Encoded token
     */
    protected function encodeScalar(mixed $value): string
    {
        if ($value === null)
            return 'null';
        if ($value === true)
            return 'true';
        if ($value === false)
            return 'false';
        if (is_int($value))
            return (string) $value;
        if (is_float($value)) {
            if (! is_finite($value))
                return 'null';
            if ($value == 0.0)
                return '0';

            return (string) $value;
        }

        return $this->encodeStringStrict((string) $value);
    }

    /**
     * Encode string with quoting/escaping per spec to avoid ambiguities.
     *
     * @param string $value Raw string
     *
     * @return string Quoted or unquoted literal
     */
    protected function encodeStringStrict(string $value): string
    {
        if ($value === '')
            return '""';
        $delimiter = (string) $this->config['delimiter'];
        $needsQuotes = false;
        if (preg_match('/\s/', $value))
            $needsQuotes = true;
        if (str_contains($value, ':') || str_contains($value, '{') || str_contains($value, '}') || str_contains($value, '[') || str_contains($value, ']') || str_contains($value, $delimiter))
            $needsQuotes = true;
        if ($value === 'null' || $value === 'true' || $value === 'false')
            $needsQuotes = true;
        if (is_numeric($value))
            $needsQuotes = true;
        if (preg_match('/[[:cntrl:]]/', $value))
            $needsQuotes = true;
        if (preg_match('/^\[\d+.*\]:/', $value) === 1)
            $needsQuotes = true;
        if (str_starts_with($value, '- ') || $value === '-')
            $needsQuotes = true;
        if (! $needsQuotes)
            return $value;
        $replacements = ["\\" => "\\\\", "\"" => "\\\"", "\n" => "\\n", "\r" => "\\r", "\t" => "\\t",];
        $escaped = strtr($value, $replacements);

        return '"' . $escaped . '"';
    }

    /**
     * Encode indexed array as primitive, tabular (uniform objects), or mixed list.
     *
     * @param array<int,mixed> $data Indexed values
     * @param int $indent            Indent level
     *
     * @return string TOON fragment
     * @throws ToonEncodeException
     */
    protected function encodeListArray(array $data, int $indent): string
    {
        if ($this->allScalars($data))
            return $this->encodePrimitiveArray($data, $indent);
        if ($this->isListOfUniformAssocArrays($data)) {
            $minRows = (int) $this->config['min_rows_tabular'];
            if (count($data) >= $minRows)
                return $this->encodeTabularArray($data, $indent);
        }

        return $this->encodeMixedArray($data, $indent);
    }

    /**
     * Encode array of scalars inline with header and delimiter.
     *
     * @param array<int,scalar|null> $data Scalar values
     * @param int $indent                  Indent level
     *
     * @return string TOON fragment
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
        $bracket = '[' . $count . ($delimiter !== ',' ? $delimiter : '') . ']:';

        return $prefix . $bracket . ' ' . $joined;
    }

    /**
     * Encode array of scalars under an object key header.
     *
     * @param string $key                  Object key
     * @param array<int,scalar|null> $data Scalar values
     * @param int $indent                  Indent level
     *
     * @return string TOON fragment
     */
    protected function encodePrimitiveArrayWithKey(string $key, array $data, int $indent): string
    {
        $prefix = $this->indent($indent);
        $count = count($data);
        $delimiter = (string) $this->config['delimiter'];
        $encodedValues = [];
        foreach ($data as $value) {
            $encodedValues[] = $this->encodeScalar($value);
        }
        $joined = implode($delimiter, $encodedValues);
        $header = $this->encodeKey($key) . '[' . $count . ($delimiter !== ',' ? $delimiter : '') . ']:';

        return $prefix . $header . ' ' . $joined;
    }

    /**
     * Encode mixed array as block with list item markers.
     *
     * @param array<int,mixed> $data Values
     * @param int $indent            Indent level
     *
     * @return string TOON fragment
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
            if (str_contains($encodedValue, "\n")) {
                $parts = explode("\n", $encodedValue);
                $first = ltrim(array_shift($parts));
                $lines[] = $childPrefix . '- ' . $first;
                $strip = $this->indent($indent + 1);
                $stripLen = strlen($strip);
                foreach ($parts as $restLine) {
                    if ($restLine === '')
                        continue;
                    if (str_starts_with($restLine, $strip)) {
                        $restLine = substr($restLine, $stripLen);
                    }
                    $lines[] = $this->indent($indent + 2) . $restLine;
                }
            }
            else {
                $lines[] = $childPrefix . '- ' . ltrim($encodedValue);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Encode associative array with key: value lines and nested blocks.
     *
     * @param array<string,mixed> $data Object map
     * @param int $indent               Indent level
     *
     * @return string TOON fragment
     * @throws ToonEncodeException
     */
    protected function encodeAssocObject(array $data, int $indent): string
    {
        $lines = [];
        $prefix = $this->indent($indent);
        foreach ($data as $key => $value) {
            [$foldedKey, $foldedValue] = $this->maybeFoldKey((string) $key, $value);
            $key = $foldedKey;
            $value = $foldedValue;
            if (is_array($value)) {
                if ($value === []) {
                    $lines[] = $prefix . $this->encodeKey((string) $key) . '[0]:';
                    continue;
                }
                if ($this->isAssoc($value)) {
                    $lines[] = $prefix . $this->encodeKey((string) $key) . ':';
                    $lines[] = $this->encodeAssocObject($value, $indent + 1);
                    continue;
                }
                if ($this->allScalars($value)) {
                    $lines[] = $this->encodePrimitiveArrayWithKey((string) $key, $value, $indent);
                    continue;
                }
                if ($this->isListOfUniformAssocArrays($value)) {
                    $minRows = (int) $this->config['min_rows_tabular'];
                    if (count($value) >= $minRows) {
                        $lines[] = $this->encodeTabularArrayWithKey((string) $key, $value, $indent);
                        continue;
                    }
                }
                $lines[] = $this->encodeMixedArrayWithKey((string) $key, $value, $indent);
            }
            else {
                $encodedValue = $this->encodeValue($value, $indent + 1);
                if (str_contains($encodedValue, "\n")) {
                    $lines[] = $prefix . $this->encodeKey((string) $key) . ':';
                    $lines[] = $encodedValue;
                }
                else {
                    $lines[] = $prefix . $this->encodeKey((string) $key) . ': ' . $encodedValue;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Optionally fold chain of single-key nested objects into dotted key.
     *
     * @param string $key  Root key
     * @param mixed $value Value to inspect for folding
     *
     * @return array{0:string,1:mixed} Folded key and remainder value
     */
    protected function maybeFoldKey(string $key, mixed $value): array
    {
        $mode = (string) ($this->config['key_folding'] ?? 'off');
        if ($mode !== 'safe')
            return [$key, $value];
        $maxDepth = (int) ($this->config['flatten_depth'] ?? -1);
        $depth = 0;
        $segments = [$key];
        $current = $value;
        $excluded = (array) ($this->config['folding_exclude'] ?? []);
        foreach ($excluded as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix))
                return [$key, $value];
        }
        while (is_array($current) && $this->isAssoc($current) && count($current) === 1) {
            $k = array_key_first($current);
            if (! $this->isSafeIdentifier($k))
                break;
            $segments[] = $k;
            $current = $current[$k];
            $depth++;
            if ($maxDepth >= 0 && $depth >= $maxDepth)
                break;
            if (is_array($current) && ! $this->isAssoc($current))
                break;
        }
        if (count($segments) === 1)
            return [$key, $value];

        return [implode('.', $segments), $current];
    }

    /**
     * Encode uniform array of objects as tabular block with header fields.
     *
     * @param array<int,array<string,scalar|null>> $rows Rows
     * @param int $indent                                Indent level
     *
     * @return string TOON fragment
     * @throws ToonEncodeException On empty rows or non-scalar cells
     */
    protected function encodeTabularArray(array $rows, int $indent): string
    {
        if ($rows === [])
            throw new ToonEncodeException('Cannot encode an empty tabular array.');
        $keys = array_keys($rows[0]);
        $count = count($rows);
        $delimiter = (string) $this->config['delimiter'];
        $prefix = $this->indent($indent);
        $childPrefix = $this->indent($indent + 1);
        $headerKeys = array_map(fn ($k) => $this->encodeHeaderKey($k), $keys);
        $bracket = '[' . $count . ($delimiter !== ',' ? $delimiter : '') . ']';
        $header = $prefix . $bracket . '{' . implode($delimiter, $headerKeys) . '}:';
        $lines = [$header];
        foreach ($rows as $row) {
            $values = [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $row))
                    throw new ToonEncodeException('Tabular row is missing expected key: ' . $key);
                $cell = $row[$key];
                if (! ($cell === null || is_scalar($cell)))
                    throw new ToonEncodeException('Tabular arrays must contain only scalar values.');
                $values[] = $this->encodeScalar($cell);
            }
            $lines[] = $childPrefix . implode($delimiter, $values);
        }

        return implode("\n", $lines);
    }

    /**
     * Encode tabular array under an object key, adding key to header.
     *
     * @param string $key                                Object key
     * @param array<int,array<string,scalar|null>> $rows Rows
     * @param int $indent                                Indent level
     *
     * @return string TOON fragment
     * @throws ToonEncodeException
     */
    protected function encodeTabularArrayWithKey(string $key, array $rows, int $indent): string
    {
        if ($rows === [])
            throw new ToonEncodeException('Cannot encode an empty tabular array.');
        $keys = array_keys($rows[0]);
        $count = count($rows);
        $delimiter = (string) $this->config['delimiter'];
        $prefix = $this->indent($indent);
        $childPrefix = $this->indent($indent + 1);
        $headerKeys = array_map(fn ($k) => $this->encodeHeaderKey($k), $keys);
        $bracket = $this->encodeKey($key) . '[' . $count . ($delimiter !== ',' ? $delimiter : '') . ']';
        $header = $prefix . $bracket . '{' . implode($delimiter, $headerKeys) . '}:';
        $lines = [$header];
        foreach ($rows as $row) {
            $values = [];
            foreach ($keys as $k) {
                if (! array_key_exists($k, $row))
                    throw new ToonEncodeException('Tabular row is missing expected key: ' . $k);
                $cell = $row[$k];
                if (! ($cell === null || is_scalar($cell)))
                    throw new ToonEncodeException('Tabular arrays must contain only scalar values.');
                $values[] = $this->encodeScalar($cell);
            }
            $lines[] = $childPrefix . implode($delimiter, $values);
        }

        return implode("\n", $lines);
    }

    /**
     * Encode mixed array under an object key as list block.
     *
     * @param string $key            Object key
     * @param array<int,mixed> $data Values
     * @param int $indent            Indent level
     *
     * @return string TOON fragment
     * @throws ToonEncodeException
     */
    protected function encodeMixedArrayWithKey(string $key, array $data, int $indent): string
    {
        $prefix = $this->indent($indent);
        $childPrefix = $this->indent($indent + 1);
        $count = count($data);
        $lines = [];
        $lines[] = $prefix . $this->encodeKey($key) . '[' . $count . ']:';
        foreach ($data as $value) {
            $encodedValue = $this->encodeValue($value, $indent + 1);
            if (str_contains($encodedValue, "\n")) {
                $parts = explode("\n", $encodedValue);
                $first = ltrim(array_shift($parts));
                $lines[] = $childPrefix . '- ' . $first;
                $strip = $this->indent($indent + 1);
                $stripLen = strlen($strip);
                foreach ($parts as $restLine) {
                    if ($restLine === '')
                        continue;
                    if (str_starts_with($restLine, $strip)) {
                        $restLine = substr($restLine, $stripLen);
                    }
                    $lines[] = $this->indent($indent + 2) . $restLine;
                }
            }
            else {
                $lines[] = $childPrefix . '- ' . ltrim($encodedValue);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Encode a header field name inside braces, quoting when needed.
     *
     * @param string $key Field name
     *
     * @return string Encoded key token
     */
    protected function encodeHeaderKey(string $key): string
    {
        if ($this->isSafeIdentifier($key))
            return $key;
        $replacements = ["\\" => "\\\\", "\"" => "\\\"", "\n" => "\\n", "\r" => "\\r", "\t" => "\\t",];
        $escaped = strtr($key, $replacements);

        return '"' . $escaped . '"';
    }

    /**
     * Check if value is list of associative arrays with identical keys and scalar cells.
     *
     * @param array<int,mixed> $array Input list
     *
     * @return bool True when uniform and scalar-only
     */
    protected function isListOfUniformAssocArrays(array $array): bool
    {
        if ($array === [])
            return false;
        if (! is_array($array[0]))
            return false;
        if (! $this->isAssoc($array[0]))
            return false;
        $keys = array_keys($array[0]);
        foreach ($array as $row) {
            if (! is_array($row) || ! $this->isAssoc($row) || array_keys($row) !== $keys)
                return false;
            foreach ($row as $val) {
                if (! ($val === null || is_scalar($val)))
                    return false;
            }
        }

        return true;
    }
    protected function encodeKey(string $key): string
    {
        if (str_contains($key, '.')) {
            $segments = explode('.', $key);
            $allSafe = true;
            foreach ($segments as $seg) {
                if (! $this->isSafeIdentifier($seg)) {
                    $allSafe = false;
                    break;
                }
            }
            if ($allSafe)
                return $key;
        }
        if ($this->isSafeIdentifier($key))
            return $key;
        $replacements = ["\\" => "\\\\", "\"" => "\\\"", "\n" => "\\n", "\r" => "\\r", "\t" => "\\t",];
        $escaped = strtr($key, $replacements);

        return '"' . $escaped . '"';
    }

    /**
     * Check if identifier matches [A-Za-z_][A-Za-z0-9_]*.
     *
     * @param string $id Identifier
     *
     * @return bool
     */
    protected function isSafeIdentifier(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id);
    }

    /**
     * Check if value is null|bool|int|float|string.
     *
     * @param mixed $value Input
     *
     * @return bool
     */
    protected function isJsonScalarCompatible(mixed $value): bool
    {
        return $value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }

    /**
     * Check if array has non-sequential keys (associative in PHP sense).
     *
     * @param array<mixed> $array Input array
     *
     * @return bool
     */
    protected function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if all elements are scalars or null.
     *
     * @param array<mixed> $array List
     *
     * @return bool
     */
    protected function allScalars(array $array): bool
    {
        foreach ($array as $v) {
            if (! ($v === null || is_scalar($v)))
                return false;
        }

        return true;
    }

    /**
     * Compute spaces string for given logical indent level.
     *
     * @param int $indent Indent levels
     *
     * @return string Spaces
     */
    protected function indent(int $indent): string
    {
        $size = (int) ($this->config['indent'] ?? 2);

        return str_repeat(' ', $indent * $size);
    }

    /**
     * Normalize input into indentation-aware lines array.
     *
     * @param string $toon Normalized TOON text
     *
     * @return void
     * @throws ToonDecodeException On invalid indentation widths
     */
    protected function prepareLines(string $toon): void
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", $toon);
        $rawLines = explode("\n", (string) $normalized);
        $this->lines = [];
        $indentSize = (int) $this->config['indent'];
        foreach ($rawLines as $rawLine) {
            $line = rtrim($rawLine, "\t ");
            if ($line === '')
                continue;
            $spaces = 0;
            $length = strlen($line);
            while ($spaces < $length && $line[$spaces] === ' ') {
                $spaces++;
            }
            if ($spaces % $indentSize !== 0)
                throw new ToonDecodeException('Invalid indentation: spaces must be a multiple of the configured indent size.');
            $indentLevel = (int) ($spaces / $indentSize);
            $text = ltrim($line, ' ');
            $this->lines[] = ['indent' => $indentLevel, 'text' => $text];
        }
        $this->lineCount = count($this->lines);
    }

    /**
     * Parse a value at a specific expected indent.
     *
     * @param int $expectedIndent Indent level required
     * @param int $index          Line index (by-ref)
     *
     * @return mixed Parsed value
     * @throws ToonDecodeException On structure/indent errors
     */
    protected function parseValueAtIndent(int $expectedIndent, int &$index): mixed
    {
        if ($index >= $this->lineCount)
            throw new ToonDecodeException('Unexpected end of TOON input.');
        $line = $this->lines[$index];
        if ($line['indent'] !== $expectedIndent)
            throw new ToonDecodeException('Indentation mismatch while decoding TOON value.');
        $text = $line['text'];
        if ($this->isArrayHeader($text))
            return $this->parseArrayAtIndent($expectedIndent, $index);
        if ($this->looksLikeObjectKey($text))
            return $this->parseObjectAtIndent($expectedIndent, $index);
        $value = $this->parseScalarToken($text);
        $index++;

        return $value;
    }

    /**
     * Detect any array header: primitive [n]: or tabular [n]{...}:
     * Allows optional delimiter suffix inside brackets.
     *
     * @param string $text Line content
     *
     * @return bool
     */
    protected function isArrayHeader(string $text): bool
    {
        return preg_match('/^\[\d+[^\]]*\](?:\{[^}]*\})?:/', $text) === 1;
    }

    /**
     * Parse array header and body for primitive/tabular/mixed forms.
     *
     * @param int $indent Current indent of header line
     * @param int $index  Line index (by-ref)
     *
     * @return array<int,mixed> Decoded array
     * @throws ToonDecodeException On syntax/indent/count errors
     */
    protected function parseArrayAtIndent(int $indent, int &$index): array
    {
        $line = $this->lines[$index];
        $text = $line['text'];
        $primitivePattern = '/^\[(\d+)([^\]]*)\]:(?:\s*(.*))?$/';
        $tabularPattern = '/^\[(\d+)([^\]]*)\]\{([^}]*)\}:\s*$/';
        if (preg_match($primitivePattern, $text, $matches) === 1) {
            $count = (int) $matches[1];
            $suffix = (string) ($matches[2] ?? '');
            $inline = $matches[3] ?? '';
            $delimiter = $this->resolveHeaderDelimiter($suffix);
            if ($inline !== '') {
                $index++;

                return $this->decodePrimitiveArrayInline($count, $inline, $delimiter);
            }
            $index++;

            return $this->decodeMixedArray($count, $indent, $index);
        }
        if (preg_match($tabularPattern, $text, $matches) === 1) {
            $count = (int) $matches[1];
            $suffix = (string) ($matches[2] ?? '');
            $keysRaw = $matches[3];
            $delimiter = $this->resolveHeaderDelimiter($suffix);
            $index++;

            return $this->decodeTabularArray($count, $keysRaw, $indent, $index, $delimiter);
        }
        throw new ToonDecodeException('Invalid TOON array header syntax.');
    }

    /**
     * Decode inline primitive array values from a header line.
     *
     * @param int $expectedCount Declared length
     * @param string $inline     Values segment
     * @param string $delimiter  Active delimiter
     *
     * @return array<int,mixed>
     * @throws ToonDecodeException On length mismatch
     */
    protected function decodePrimitiveArrayInline(int $expectedCount, string $inline, string $delimiter): array
    {
        $tokens = $this->splitValues($inline, $delimiter);
        $result = [];
        foreach ($tokens as $token) {
            $result[] = $this->parseScalarToken($token);
        }
        if (count($result) !== $expectedCount)
            throw new ToonDecodeException('Primitive array length does not match declared size.');

        return $result;
    }

    /**
     * Decode mixed array block with list items, supporting nested elements.
     *
     * @param int $expectedCount Declared item count
     * @param int $indent        Header indent
     * @param int $index         Line index (by-ref)
     *
     * @return array<int,mixed>
     * @throws ToonDecodeException On indent/count errors
     */
    protected function decodeMixedArray(int $expectedCount, int $indent, int &$index): array
    {
        $result = [];
        $childIndent = $indent + 1;
        while ($index < $this->lineCount) {
            $line = $this->lines[$index];
            if ($line['indent'] < $childIndent)
                break;
            if ($line['indent'] > $childIndent)
                throw new ToonDecodeException('Invalid indentation inside mixed array items.');
            $text = $line['text'];
            if (! str_starts_with($text, '- '))
                break;
            $afterDash = ltrim(substr($text, 2));
            $nestedLines = [];
            $currentIndent = $line['indent'];
            $index++;
            while ($index < $this->lineCount) {
                $nextLine = $this->lines[$index];
                if ($nextLine['indent'] <= $currentIndent)
                    break;
                $nestedLines[] = $nextLine;
                $index++;
            }
            if ($nestedLines === []) {
                $result[] = $this->parseScalarOrInlineValue($afterDash);
            }
            else {
                $elementToon = $this->buildNestedElementToon($afterDash, $nestedLines, $currentIndent);
                $result[] = (new self($this->config))->decode($elementToon);
            }
        }
        if (count($result) !== $expectedCount)
            throw new ToonDecodeException('Mixed array item count does not match declared size.');

        return $result;
    }

    /**
     * Build synthetic TOON block for a nested list item element.
     *
     * @param string $firstLineText                                 First-line content after '- '
     * @param array<int,array{indent:int,text:string}> $nestedLines Following lines
     * @param int $baseIndent                                       Indent of '- ' line
     *
     * @return string TOON block to feed decoder
     */
    protected function buildNestedElementToon(string $firstLineText, array $nestedLines, int $baseIndent): string
    {
        $indentSize = (int) $this->config['indent'];
        $lines = [];
        $lines[] = $firstLineText;
        foreach ($nestedLines as $line) {
            $relativeIndent = $line['indent'] - ($baseIndent + 1);
            if ($relativeIndent < 0)
                $relativeIndent = 0;
            $lines[] = str_repeat(' ', $relativeIndent * $indentSize) . $line['text'];
        }

        return implode("\n", $lines);
    }

    /**
     * Parse a single-line value that may be scalar or an inline header.
     *
     * @param string $text Raw text
     *
     * @return mixed Parsed value
     * @throws ToonDecodeException
     */
    protected function parseScalarOrInlineValue(string $text): mixed
    {
        $trimmed = trim($text);
        if ($trimmed === '')
            return null;
        if ($this->isArrayHeader($trimmed) || $this->looksLikeObjectKey($trimmed)) {
            return (new self($this->config))->decode($trimmed . "\n");
        }

        return $this->parseScalarToken($trimmed);
    }

    /**
     * Split a delimited values line while respecting quoted strings.
     *
     * @param string $line      Values line
     * @param string $delimiter Active delimiter
     *
     * @return array<int,string> Tokens (trimmed)
     */
    protected function splitValues(string $line, string $delimiter): array
    {
        $tokens = [];
        $buffer = '';
        $inQuotes = false;
        $length = strlen($line);
        for ($i = 0 ; $i < $length ; $i++) {
            $char = $line[$i];
            if ($char === '"' && ($i === 0 || $line[$i - 1] !== '\\')) {
                $inQuotes = ! $inQuotes;
                $buffer .= $char;
                continue;
            }
            if ($char === $delimiter && ! $inQuotes) {
                $tokens[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($buffer !== '') {
            $tokens[] = trim($buffer);
        }

        return $tokens;
    }

    /**
     * Parse a scalar token into PHP value (null, bool, int/float/string).
     *
     * @param string $token Token
     *
     * @return mixed Scalar/null
     */
    protected function parseScalarToken(string $token): mixed
    {
        if ($token === '[]')
            return [];
        if ($token === 'null')
            return null;
        if ($token === 'true')
            return true;
        if ($token === 'false')
            return false;
        $length = strlen($token);
        if ($length >= 2 && $token[0] === '"' && $token[$length - 1] === '"') {
            $inner = substr($token, 1, -1);
            $inner = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", "\"", "\\"], $inner);

            return $inner;
        }
        if (is_numeric($token)) {
            if (($this->config['numbers_as_strings'] ?? false) === true)
                return $token;
            if (str_contains($token, '.') || stripos($token, 'e') !== false)
                return (float) $token;

            return (int) $token;
        }

        return $token;
    }

    /**
     * Lightweight detection of object key before ':' (quoted or unquoted).
     *
     * @param string $text Line text
     *
     * @return bool
     */
    protected function looksLikeObjectKey(string $text): bool
    {
        $len = strlen($text);
        if ($len === 0)
            return false;
        if ($text[0] === '"') {
            $qpos = strpos($text, '":');

            return $qpos !== false;
        }
        $pos = strpos($text, ':');
        if ($pos === false)
            return false;
        for ($j = 0 ; $j < $pos ; $j++) {
            if (ctype_space($text[$j]))
                return false;
        }

        return true;
    }

    /**
     * Parse object block starting at given indent, supporting key-suffixed arrays.
     *
     * @param int $indent Expected indent of object keys
     * @param int $index  Line index (by-ref)
     *
     * @return array<string,mixed>
     * @throws ToonDecodeException On indent/structure errors
     */
    protected function parseObjectAtIndent(int $indent, int &$index): array
    {
        $result = [];
        while ($index < $this->lineCount) {
            $line = $this->lines[$index];
            if ($line['indent'] < $indent)
                break;
            if ($line['indent'] > $indent)
                throw new ToonDecodeException('Invalid indentation inside object block.');
            $text = $line['text'];
            if (! $this->looksLikeObjectKey($text))
                break;
            [$rawKey, $rest] = $this->splitObjectKeyLine($text);
            $inlineValue = ltrim($rest, ' ');
            $index++;
            if (preg_match('/^(.*)\[(\d+)([^\]]*)\](?:\{([^}]*)\})?$/', $rawKey, $km)) {
                $baseKey = $this->parseKeyToken($km[1]);
                $count = (int) $km[2];
                $suffix = (string) ($km[3] ?? '');
                $fields = $km[4] ?? null;
                $bracket = '[' . $count . $suffix . ']';
                $header = $fields !== null ? ($bracket . '{' . $fields . '}:') : ($bracket . ':');
                if ($inlineValue !== '') {
                    if ($fields !== null)
                        throw new ToonDecodeException('Tabular array cannot be inlined as a single line.');
                    $toon = $header . ' ' . $inlineValue . "\n";
                    $value = (new self($this->config))->decode($toon);
                    $this->assignValue($result, $baseKey, $value);
                    continue;
                }
                $childIndent = $indent + 1;
                $lines = [$header];
                $indentSize = (int) $this->config['indent'];
                while ($index < $this->lineCount) {
                    $ln = $this->lines[$index];
                    if ($ln['indent'] < $childIndent)
                        break;
                    $relative = $ln['indent'] - $indent;
                    if ($relative < 0) {
                        $relative = 0;
                    }
                    $lines[] = str_repeat(' ', $relative * $indentSize) . $ln['text'];
                    $index++;
                }
                $toon = implode("\n", $lines);
                $value = (new self($this->config))->decode($toon);
                $this->assignValue($result, $baseKey, $value);
                continue;
            }
            $key = $this->parseKeyToken($rawKey);
            if ($inlineValue !== '') {
                $value = $this->parseScalarOrInlineValue($inlineValue);
                $this->assignValue($result, $key, $value);
                continue;
            }
            if ($index >= $this->lineCount) {
                $result[$key] = null;
                break;
            }
            $nextLine = $this->lines[$index];
            if ($nextLine['indent'] <= $indent) {
                $result[$key] = null;
                continue;
            }
            $childIndent = $nextLine['indent'];
            $value = $this->parseValueAtIndent($childIndent, $index);
            $this->assignValue($result, $key, $value);
        }

        return $result;
    }

    /**
     * Split object key line into raw key token and rest after ':' (handles quoted keys).
     *
     * @param string $text Line text
     *
     * @return array{0:string,1:string} [rawKey, afterColon]
     */
    protected function splitObjectKeyLine(string $text): array
    {
        $len = strlen($text);
        if ($len === 0)
            return ['', ''];
        if ($text[0] === '"') {
            $i = 1;
            $escaped = false;
            while ($i < $len) {
                $ch = $text[$i];
                if ($escaped) {
                    $escaped = false;
                }
                else if ($ch === '\\') {
                    $escaped = true;
                }
                else if ($ch === '"') {
                    break;
                }
                $i++;
            }
            $rawKey = substr($text, 0, $i + 1);
            $rest = substr($text, $i + 1);
            $rest = ltrim($rest, ':');

            return [$rawKey, $rest];
        }
        $pos = strpos($text, ':');
        if ($pos === false)
            return [$text, ''];

        return [substr($text, 0, $pos), substr($text, $pos + 1)];
    }

    /**
     * Resolve optional delimiter suffix from [N<suffix>] header.
     *
     * @param string $suffix Suffix inside brackets (may be empty)
     *
     * @return string One of ',', "\t", '|'
     */
    protected function resolveHeaderDelimiter(string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '')
            return (string) ($this->config['delimiter'] ?? ',');
        if ($suffix === ',' || $suffix === "\t" || $suffix === '|')
            return $suffix;

        return (string) ($this->config['delimiter'] ?? ',');
    }

    /**
     * Decode tabular block rows into list of associative arrays.
     *
     * @param int $expectedCount Declared row count
     * @param string $keysRaw    Raw keys list inside {}
     * @param int $indent        Header indent
     * @param int $index         Line index (by-ref)
     * @param string $delimiter  Active delimiter
     *
     * @return array<int,array<string,mixed>>
     * @throws ToonDecodeException On header/indent/count errors
     */
    protected function decodeTabularArray(
        int $expectedCount,
        string $keysRaw,
        int $indent,
        int &$index,
        string $delimiter
    ): array {
        $rawFields = $this->splitFields($keysRaw, $delimiter);
        $keys = [];
        foreach ($rawFields as $f) {
            $f = trim($f);
            if ($f === '')
                continue;
            $keys[] = $this->parseKeyToken($f);
        }
        if ($keys === [])
            throw new ToonDecodeException('Tabular array header must define at least one key.');
        $result = [];
        $childIndent = $indent + 1;
        while ($index < $this->lineCount) {
            $line = $this->lines[$index];
            if ($line['indent'] < $childIndent)
                break;
            if ($line['indent'] > $childIndent)
                throw new ToonDecodeException('Invalid indentation inside tabular array rows.');
            $values = $this->splitValues($line['text'], $delimiter);
            if (count($values) !== count($keys))
                throw new ToonDecodeException('Tabular row value count does not match header keys.');
            $row = [];
            foreach ($keys as $pos => $k) {
                $row[$k] = $this->parseScalarToken($values[$pos]);
            }
            $result[] = $row;
            $index++;
        }
        if (count($result) !== $expectedCount)
            throw new ToonDecodeException('Tabular array row count does not match declared size.');

        return $result;
    }

    /**
     * Split tabular header fields respecting quotes and delimiter.
     *
     * @param string $fieldsRaw Contents of {...}
     * @param string $delimiter Active delimiter
     *
     * @return array<int,string>
     */
    protected function splitFields(string $fieldsRaw, string $delimiter): array
    {
        $tokens = [];
        $buffer = '';
        $inQuotes = false;
        $len = strlen($fieldsRaw);
        for ($i = 0 ; $i < $len ; $i++) {
            $ch = $fieldsRaw[$i];
            if ($ch === '"' && ($i === 0 || $fieldsRaw[$i - 1] !== '\\')) {
                $inQuotes = ! $inQuotes;
                $buffer .= $ch;
                continue;
            }
            if ($ch === $delimiter && ! $inQuotes) {
                $tokens[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    /**
     * Parse key token (quoted or unquoted) to raw string value.
     *
     * @param string $token Key token
     *
     * @return string Raw key
     */
    protected function parseKeyToken(string $token): string
    {
        $len = strlen($token);
        if ($len >= 2 && $token[0] === '"' && $token[$len - 1] === '"') {
            $inner = substr($token, 1, -1);
            $inner = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", "\"", "\\"], $inner);

            return $inner;
        }

        return $token;
    }

    /**
     * Assign value to target, optionally expanding dot paths when enabled.
     *
     * @param array<string,mixed> $target Target object
     * @param string $key                 Key (maybe dotted)
     * @param mixed $value                Value
     *
     * @return void
     */
    protected function assignValue(array &$target, string $key, mixed $value): void
    {
        $expand = (bool) ($this->config['expand_paths'] ?? false);
        if (! $expand || ! str_contains($key, '.')) {
            $target[$key] = $value;

            return;
        }
        $segments = explode('.', $key);
        foreach ($segments as $seg) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $seg)) {
                $target[$key] = $value;

                return;
            }
        }
        $ref =& $target;
        $last = array_pop($segments);
        foreach ($segments as $seg) {
            if (! isset($ref[$seg]) || ! is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref =& $ref[$seg];
        }
        $ref[$last] = $value;
    }
}
