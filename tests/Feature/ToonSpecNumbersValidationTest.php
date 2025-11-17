<?php

namespace JobMetric\Toon\Tests\Feature;

use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Exceptions\ToonEncodeException;
use JobMetric\Toon\Facades\Toon;
use JobMetric\Toon\Tests\TestCase;

class ToonSpecNumbersValidationTest extends TestCase
{
    /**
     * @throws ToonDecodeException
     */
    public function testDecodeNumbersFormats(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $toon = 'nums[5]: 42,-1E+03,1.5000,-0,2.5e2';
        $decoded = Toon::decode($toon);

        $this->assertSame([
            'nums' => [42, -1000.0, 1.5, 0, 250.0],
        ], $decoded);
    }

    /**
     * @throws ToonEncodeException
     * @throws ToonDecodeException
     */
    public function testEncodeStringLooksNumericWithLeadingZero(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = ['v' => '05'];
        $encoded = Toon::encode($data);

        $this->assertSame('v: "05"', $encoded);
        $this->assertSame($data, Toon::decode($encoded));
    }

    /**
     * @throws ToonEncodeException
     * @throws ToonDecodeException
     */
    public function testEncodeStringWithEscapes(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = [
            'text' => "line1\nline2",
            'path' => "C:\\Users\\path",
            'tab'  => "a\tb",
        ];

        $encoded = Toon::encode($data);

        $expected = "text: \"line1\\nline2\"\npath: \"C:\\\\Users\\\\path\"\ntab: \"a\\tb\"";
        $this->assertSame($expected, $encoded);

        $this->assertSame($data, Toon::decode($encoded));
    }

    /**
     * @throws ToonDecodeException
     */
    public function testNumbersAsStringsOption(): void
    {
        config()->set('laravel-toon.spec_strict', true);
        config()->set('laravel-toon.numbers_as_strings', true);

        $toon = 'n: 12345678901234567890';
        $decoded = Toon::decode($toon);

        $this->assertSame(['n' => '12345678901234567890'], $decoded);
    }

    public function testTabularRowValueCountMismatchThrows(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $toon = <<<TOON
items[2]{id,name}:
  1,Alice
  2
TOON;

        $this->expectException(ToonDecodeException::class);
        Toon::decode($toon);
    }

    public function testMixedArrayItemCountMismatchThrows(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $toon = <<<TOON
items[1]:
  - 1
  - 2
TOON;

        $this->expectException(ToonDecodeException::class);
        Toon::decode($toon);
    }
}
