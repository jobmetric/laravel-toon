<?php

namespace JobMetric\Toon\Support;

use JobMetric\Toon\Exceptions\ToonDecodeException;

/**
 * Class ToonDecoder
 *
 * Decodes TOON notation strings back into PHP data structures.
 * This decoder expects TOON text that follows the official specification
 * and the conventions used by the ToonEncoder in this package.
 */
class ToonDecoder
{
    /**
     * Holds configuration options that tune the decoding behavior.
     *
     * Supported options:
     * - indent: int (default: 2)                 Number of spaces per indentation level.
     * - delimiter: string (default: ",")         Delimiter used between tabular values.
     * - throw_on_decode_error: bool (default: true)
     *   When true, decoding errors will throw ToonDecodeException.
     *   When false, decode() will return null instead.
     *
     * @var array<string,mixed>
     */
    protected array $config;

    /**
     * Holds the processed TOON lines as indentation-aware entries.
     *
     * Each entry has:
     * - indent: int   The indentation level (not spaces, but levels).
     * - text: string  The line text with leading spaces stripped.
     *
     * @var array<int,array{indent:int,text:string}>
     */
    protected array $lines = [];

    /**
     * Holds the number of processed lines.
     *
     * @var int
     */
    protected int $lineCount = 0;

    /**
     * ToonDecoder constructor.
     *
     * Stores the configuration that shapes how TOON input is parsed.
     *
     * @param array<string,mixed> $config Configuration options for decoding.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'indent'                => 2,
            'delimiter'             => ',',
            'throw_on_decode_error' => true,
        ], $config);
    }

    /**
     * Decode a TOON string back into a PHP data structure.
     *
     * On success, this returns the decoded PHP value. When the input
     * is empty or whitespace only, it returns null. If a decoding
     * error occurs, behavior depends on configuration:
     * - When throw_on_decode_error is true, a ToonDecodeException is thrown.
     * - When throw_on_decode_error is false, null is returned.
     *
     * @param string $toon The TOON text to decode.
     *
     * @return mixed
     *
     * @throws ToonDecodeException
     */
    public function decode(string $toon): mixed
    {
        $trimmed = trim($toon);

        if ($trimmed === '') {
            return null;
        }

        try {
            $this->prepareLines($trimmed);

            $index = 0;

            return $this->parseValueAtIndent(0, $index);
        } catch (ToonDecodeException $exception) {
            if (! ($this->config['throw_on_decode_error'] ?? true)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Normalizes the input string into an internal list of indentation-aware lines.
     *
     * @param string $toon The normalized TOON input.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    protected function prepareLines(string $toon): void
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", $toon);
        $rawLines = explode("\n", $normalized);

        $this->lines = [];
        $indentSize = (int) $this->config['indent'];

        foreach ($rawLines as $rawLine) {
            $line = rtrim($rawLine, "\t ");

            if ($line === '') {
                continue;
            }

            $spaces = 0;
            $length = strlen($line);

            while ($spaces < $length && $line[$spaces] === ' ') {
                $spaces++;
            }

            if ($spaces % $indentSize !== 0) {
                throw new ToonDecodeException('Invalid indentation: spaces must be a multiple of the configured indent size.');
            }

            $indentLevel = (int) ($spaces / $indentSize);
            $text = ltrim($line, ' ');

            $this->lines[] = [
                'indent' => $indentLevel,
                'text'   => $text,
            ];
        }

        $this->lineCount = count($this->lines);
    }

    /**
     * Parses a value starting at the current index for a specific indentation level.
     *
     * This dispatcher decides whether the current line represents:
     * - an array (primitive, tabular, or mixed)
     * - an object (associative structure)
     * - a scalar value
     *
     * @param int $expectedIndent The indentation level expected for this value.
     * @param int $index          The current line index (passed by reference).
     *
     * @return mixed
     *
     * @throws ToonDecodeException
     */
    protected function parseValueAtIndent(int $expectedIndent, int &$index): mixed
    {
        if ($index >= $this->lineCount) {
            throw new ToonDecodeException('Unexpected end of TOON input.');
        }

        $line = $this->lines[$index];

        if ($line['indent'] !== $expectedIndent) {
            throw new ToonDecodeException('Indentation mismatch while decoding TOON value.');
        }

        $text = $line['text'];

        if ($this->isArrayHeader($text)) {
            return $this->parseArrayAtIndent($expectedIndent, $index);
        }

        if ($this->isObjectKeyLine($text)) {
            return $this->parseObjectAtIndent($expectedIndent, $index);
        }

        $value = $this->parseScalarToken($text);
        $index++;

        return $value;
    }

    /**
     * Checks whether a line is an array header line.
     *
     * @param string $text The line text without leading spaces.
     *
     * @return bool
     */
    protected function isArrayHeader(string $text): bool
    {
        return preg_match('/^\[\d+.*\]:/', $text) === 1;
    }

    /**
     * Checks whether a line is a potential object key line.
     *
     * @param string $text The line text without leading spaces.
     *
     * @return bool
     */
    protected function isObjectKeyLine(string $text): bool
    {
        return preg_match('/^[^:\s]+:/', $text) === 1;
    }

    /**
     * Parses an array header and its associated body.
     *
     * This method branches into:
     * - primitive array decoding
     * - tabular array decoding
     * - mixed array decoding
     *
     * @param int $indent The indentation level of the array header.
     * @param int $index  The current line index (passed by reference).
     *
     * @return array<int,mixed>
     *
     * @throws ToonDecodeException
     */
    protected function parseArrayAtIndent(int $indent, int &$index): array
    {
        $line = $this->lines[$index];
        $text = $line['text'];

        $primitivePattern = '/^\[(\d+)\]:(?:\s*(.*))?$/';
        $tabularPattern = '/^\[(\d+),\]\{([^}]*)\}:\s*$/';

        if (preg_match($primitivePattern, $text, $matches) === 1) {
            $count = (int) $matches[1];
            $inline = $matches[2] ?? '';

            if ($inline !== '') {
                $index++;

                return $this->decodePrimitiveArrayInline($count, $inline);
            }

            $index++;

            return $this->decodeMixedArray($count, $indent, $index);
        }

        if (preg_match($tabularPattern, $text, $matches) === 1) {
            $count = (int) $matches[1];
            $keysRaw = $matches[2];

            $index++;

            return $this->decodeTabularArray($count, $keysRaw, $indent, $index);
        }

        throw new ToonDecodeException('Invalid TOON array header syntax.');
    }

    /**
     * Decodes an inline primitive array from a single header line.
     *
     * Example:
     * "[3]: 1,2,3"
     *
     * @param int $expectedCount Expected number of elements.
     * @param string $inline     The inline values portion.
     *
     * @return array<int,mixed>
     *
     * @throws ToonDecodeException
     */
    protected function decodePrimitiveArrayInline(int $expectedCount, string $inline): array
    {
        $delimiter = (string) $this->config['delimiter'];

        $tokens = $this->splitValues($inline, $delimiter);
        $result = [];

        foreach ($tokens as $token) {
            $result[] = $this->parseScalarToken($token);
        }

        if (count($result) !== $expectedCount) {
            throw new ToonDecodeException('Primitive array length does not match declared size.');
        }

        return $result;
    }

    /**
     * Decodes a tabular array of uniform associative rows.
     *
     * @param int $expectedCount Expected number of rows.
     * @param string $keysRaw    The raw keys list inside braces.
     * @param int $indent        The indentation level of the header.
     * @param int $index         The current line index (passed by reference).
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws ToonDecodeException
     */
    protected function decodeTabularArray(int $expectedCount, string $keysRaw, int $indent, int &$index): array
    {
        $delimiter = (string) $this->config['delimiter'];

        $keys = array_filter(array_map('trim', explode(',', $keysRaw)), static function ($key) {
            return $key !== '';
        });

        if ($keys === []) {
            throw new ToonDecodeException('Tabular array header must define at least one key.');
        }

        $result = [];
        $childIndent = $indent + 1;

        while ($index < $this->lineCount) {
            $line = $this->lines[$index];

            if ($line['indent'] < $childIndent) {
                break;
            }

            if ($line['indent'] > $childIndent) {
                throw new ToonDecodeException('Invalid indentation inside tabular array rows.');
            }

            $values = $this->splitValues($line['text'], $delimiter);

            if (count($values) !== count($keys)) {
                throw new ToonDecodeException('Tabular row value count does not match header keys.');
            }

            $row = [];

            foreach ($keys as $position => $key) {
                $row[$key] = $this->parseScalarToken($values[$position]);
            }

            $result[] = $row;

            $index++;

            if (count($result) === $expectedCount) {
                break;
            }
        }

        if (count($result) !== $expectedCount) {
            throw new ToonDecodeException('Tabular array row count does not match declared size.');
        }

        return $result;
    }

    /**
     * Decodes a mixed array where each element is represented as a list item.
     *
     * Example:
     * [3]:
     *   - 1
     *   - name: Alice
     *   - [2]: 10,20
     *
     * @param int $expectedCount Expected number of elements.
     * @param int $indent        The indentation level of the header.
     * @param int $index         The current line index (passed by reference).
     *
     * @return array<int,mixed>
     *
     * @throws ToonDecodeException
     */
    protected function decodeMixedArray(int $expectedCount, int $indent, int &$index): array
    {
        $result = [];
        $childIndent = $indent + 1;

        while ($index < $this->lineCount) {
            $line = $this->lines[$index];

            if ($line['indent'] < $childIndent) {
                break;
            }

            if ($line['indent'] > $childIndent) {
                throw new ToonDecodeException('Invalid indentation inside mixed array items.');
            }

            $text = $line['text'];

            if (! str_starts_with($text, '- ')) {
                break;
            }

            $afterDash = ltrim(substr($text, 2));
            $nestedLines = [];
            $currentIndent = $line['indent'];

            $index++;

            while ($index < $this->lineCount) {
                $nextLine = $this->lines[$index];

                if ($nextLine['indent'] <= $currentIndent) {
                    break;
                }

                $nestedLines[] = $nextLine;
                $index++;
            }

            if ($nestedLines === []) {
                $result[] = $this->parseScalarOrInlineValue($afterDash);
            }
            else {
                $elementToon = $this->buildNestedElementToon($afterDash, $nestedLines, $currentIndent);
                $result[] = $this->decode($elementToon);
            }

            if (count($result) === $expectedCount) {
                break;
            }
        }

        if (count($result) !== $expectedCount) {
            throw new ToonDecodeException('Mixed array item count does not match declared size.');
        }

        return $result;
    }

    /**
     * Builds a nested TOON block for a mixed array element that spans multiple lines.
     *
     * @param string $firstLineText                                 The first line text after the "- " marker.
     * @param array<int,array{indent:int,text:string}> $nestedLines Nested lines belonging to this element.
     * @param int $baseIndent                                       The indentation level of the "- " line.
     *
     * @return string
     */
    protected function buildNestedElementToon(string $firstLineText, array $nestedLines, int $baseIndent): string
    {
        $indentSize = (int) $this->config['indent'];

        $lines = [];
        $lines[] = $firstLineText;

        foreach ($nestedLines as $line) {
            $relativeIndent = $line['indent'] - $baseIndent;

            if ($relativeIndent < 0) {
                $relativeIndent = 0;
            }

            $lines[] = str_repeat(' ', $relativeIndent * $indentSize) . $line['text'];
        }

        return implode("\n", $lines);
    }

    /**
     * Parses a PHP value from a scalar token or inline structure.
     *
     * @param string $text The text to interpret as a value.
     *
     * @return mixed
     *
     * @throws ToonDecodeException
     */
    protected function parseScalarOrInlineValue(string $text): mixed
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        if ($this->isArrayHeader($trimmed)) {
            $temp = $trimmed . "\n";

            return $this->decode($temp);
        }

        if ($this->isObjectKeyLine($trimmed) && ! str_contains($trimmed, ' ')) {
            return $this->decode($trimmed . "\n");
        }

        return $this->parseScalarToken($trimmed);
    }

    /**
     * Parses an object at a given indentation level.
     *
     * Example:
     * key: value
     * nested:
     *   foo: 1
     *
     * @param int $indent The indentation level of the object keys.
     * @param int $index  The current line index (passed by reference).
     *
     * @return array<string,mixed>
     *
     * @throws ToonDecodeException
     */
    protected function parseObjectAtIndent(int $indent, int &$index): array
    {
        $result = [];

        while ($index < $this->lineCount) {
            $line = $this->lines[$index];

            if ($line['indent'] < $indent) {
                break;
            }

            if ($line['indent'] > $indent) {
                throw new ToonDecodeException('Invalid indentation inside object block.');
            }

            $text = $line['text'];

            if (! $this->isObjectKeyLine($text)) {
                break;
            }

            $parts = explode(':', $text, 2);
            $key = $parts[0];
            $rest = $parts[1] ?? '';

            $inlineValue = ltrim($rest, ' ');

            $index++;

            if ($inlineValue !== '') {
                $result[$key] = $this->parseScalarToken($inlineValue);

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
            $result[$key] = $this->parseValueAtIndent($childIndent, $index);
        }

        return $result;
    }

    /**
     * Splits a comma-separated line of values into tokens, respecting quotes.
     *
     * This method is used for parsing primitive and tabular arrays where
     * values may be quoted and may contain the delimiter as part of the string.
     *
     * @param string $line      The line containing delimited values.
     * @param string $delimiter The delimiter character.
     *
     * @return array<int,string>
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
     * Parses a scalar token into a PHP value.
     *
     * This is the inverse of the scalar encoding rules in ToonEncoder.
     *
     * @param string $token The token to parse.
     *
     * @return mixed
     */
    protected function parseScalarToken(string $token): mixed
    {
        if ($token === 'null') {
            return null;
        }

        if ($token === 'true') {
            return true;
        }

        if ($token === 'false') {
            return false;
        }

        $length = strlen($token);

        if ($length >= 2 && $token[0] === '"' && $token[$length - 1] === '"') {
            $inner = substr($token, 1, -1);
            $inner = str_replace('\"', '"', $inner);

            return $inner;
        }

        if (is_numeric($token)) {
            if (str_contains($token, '.') || stripos($token, 'e') !== false) {
                return (float) $token;
            }

            return (int) $token;
        }

        return $token;
    }
}
