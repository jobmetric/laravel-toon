<?php

namespace JobMetric\Toon\Tests\Unit;

use JobMetric\Toon\Exceptions\ToonEncodeException;
use JobMetric\Toon\Tests\TestCase;
use JobMetric\Toon\ToonManager;

/**
 * Class ToonEncoderTest
 *
 * Covers encoding PHP values into TOON notation for scalar, object,
 * primitive array, tabular array and mixed array scenarios.
 */
class ToonEncoderTest extends TestCase
{
    /**
     * Test encoding of scalar values into TOON scalar tokens.
     *
     * @return void
     *
     * @throws ToonEncodeException
     */
    public function testEncodeScalars(): void
    {
        $encoder = new ToonManager();

        $this->assertSame("null", $encoder->encode(null));
        $this->assertSame("true", $encoder->encode(true));
        $this->assertSame("false", $encoder->encode(false));
        $this->assertSame("42", $encoder->encode(42));
        $this->assertSame("3.14", $encoder->encode(3.14));
        $this->assertSame("\"hello world\"", $encoder->encode('hello world'));
        $this->assertSame("\"123\"", $encoder->encode('123'));
        $this->assertSame("\"true\"", $encoder->encode('true'));
    }

    /**
     * Test encoding of associative objects with nested structures.
     *
     * @return void
     *
     * @throws ToonEncodeException
     */
    public function testEncodeAssociativeObject(): void
    {
        $encoder = new ToonManager();

        $data = [
            'id'     => 1,
            'name'   => 'Alice',
            'active' => true,
            'meta'   => [
                'role'  => 'admin',
                'score' => 9.5,
            ],
        ];

        $toon = $encoder->encode($data);

        $expected = <<<TOON
id: 1
name: Alice
active: true
meta:
  role: admin
  score: 9.5
TOON;

        $this->assertSame($expected, $toon);
    }

    /**
     * Test encoding of primitive arrays into compact TOON arrays.
     *
     * @return void
     *
     * @throws ToonEncodeException
     */
    public function testEncodePrimitiveArray(): void
    {
        $encoder = new ToonManager();

        $data = [1, 2, 3];

        $toon = $encoder->encode($data);

        $this->assertSame("[3]: 1,2,3", $toon);
    }

    /**
     * Test encoding of tabular arrays of uniform associative rows.
     *
     * @return void
     *
     * @throws ToonEncodeException
     */
    public function testEncodeTabularArray(): void
    {
        $encoder = new ToonManager([
            'min_rows_tabular' => 2,
        ]);

        $data = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
        ];

        $toon = $encoder->encode($data);

        $expected = <<<TOON
[2]{id,name,role}:
  1,Alice,admin
  2,Bob,user
TOON;

        $this->assertSame($expected, $toon);
    }

    /**
     * Test encoding of mixed arrays that contain multiple value types.
     *
     * @return void
     *
     * @throws ToonEncodeException
     */
    public function testEncodeMixedArray(): void
    {
        $encoder = new ToonManager();

        $data = [
            1,
            ['name' => 'Alice'],
            [10, 20],
        ];

        $toon = $encoder->encode($data);

        $expected = <<<TOON
[3]:
  - 1
  - name: Alice
  - [2]: 10,20
TOON;

        $this->assertSame($expected, $toon);
    }

    /**
     * Test that encoding unsupported types throws a ToonEncodeException.
     *
     * @return void
     */
    public function testEncodeUnsupportedTypeThrowsException(): void
    {
        $encoder = new ToonManager();

        $this->expectException(ToonEncodeException::class);

        $encoder->encode(fopen('php://memory', 'r'));
    }
}
