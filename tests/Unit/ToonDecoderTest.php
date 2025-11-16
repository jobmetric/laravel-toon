<?php

namespace JobMetric\Toon\Tests\Unit;

use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Support\ToonDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Class ToonDecoderTest
 *
 * Covers decoding TOON notation into PHP values, verifying the inverse
 * of ToonEncoder for scalar, object, primitive array, tabular array
 * and mixed array scenarios.
 */
class ToonDecoderTest extends TestCase
{
    /**
     * Test decoding of scalar tokens into PHP scalar values.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    public function testDecodeScalars(): void
    {
        $decoder = new ToonDecoder();

        $this->assertNull($decoder->decode("null\n"));
        $this->assertTrue($decoder->decode("true\n"));
        $this->assertFalse($decoder->decode("false\n"));
        $this->assertSame(42, $decoder->decode("42\n"));
        $this->assertSame(3.14, $decoder->decode("3.14\n"));
        $this->assertSame('hello world', $decoder->decode("\"hello world\"\n"));
        $this->assertSame('123', $decoder->decode("\"123\"\n"));
        $this->assertSame('true', $decoder->decode("\"true\"\n"));
    }

    /**
     * Test decoding of an associative object with nested structures.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    public function testDecodeAssociativeObject(): void
    {
        $decoder = new ToonDecoder();

        $toon = <<<TOON
id: 1
name: Alice
active: true
meta:
  role: admin
  score: 9.5

TOON;

        $decoded = $decoder->decode($toon);

        $expected = [
            'id' => 1,
            'name' => 'Alice',
            'active' => true,
            'meta' => [
                'role' => 'admin',
                'score' => 9.5,
            ],
        ];

        $this->assertSame($expected, $decoded);
    }

    /**
     * Test decoding of a primitive array.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    public function testDecodePrimitiveArray(): void
    {
        $decoder = new ToonDecoder();

        $toon = "[3]: 1,2,3\n";

        $decoded = $decoder->decode($toon);

        $this->assertSame([1, 2, 3], $decoded);
    }

    /**
     * Test decoding of a tabular array.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    public function testDecodeTabularArray(): void
    {
        $decoder = new ToonDecoder();

        $toon = <<<TOON
[2,]{id,name,role}:
  1,Alice,admin
  2,Bob,user

TOON;

        $decoded = $decoder->decode($toon);

        $expected = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
        ];

        $this->assertSame($expected, $decoded);
    }

    /**
     * Test decoding of a mixed array.
     *
     * @return void
     *
     * @throws ToonDecodeException
     */
    public function testDecodeMixedArray(): void
    {
        $decoder = new ToonDecoder();

        $toon = <<<TOON
[3]:
  - 1
  - name: Alice
  - [2]: 10,20

TOON;

        $decoded = $decoder->decode($toon);

        $this->assertSame(1, $decoded[0]);
        $this->assertSame(['name' => 'Alice'], $decoded[1]);
        $this->assertSame([10, 20], $decoded[2]);
    }

    /**
     * Test that invalid indentation results in a decoding error.
     *
     * @return void
     */
    public function testDecodeInvalidIndentationThrowsException(): void
    {
        $decoder = new ToonDecoder();

        $toon = "id:\n   name: Alice\n";

        $this->expectException(ToonDecodeException::class);

        $decoder->decode($toon);
    }
}
