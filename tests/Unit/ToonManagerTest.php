<?php

namespace JobMetric\Toon\Tests\Unit;

use JobMetric\Toon\ToonManager;
use PHPUnit\Framework\TestCase;

/**
 * Class ToonManagerTest
 *
 * Verifies delegation from ToonManager to the underlying encoder
 * and decoder using real implementations.
 */
class ToonManagerTest extends TestCase
{
    /**
     * Test that ToonManager encodes and decodes data symmetrically.
     *
     * @return void
     */
    public function testEncodeAndDecodeRoundTrip(): void
    {
        $manager = new ToonManager([
            'min_rows_tabular' => 2,
        ]);

        $data = [
            'id' => 1,
            'name' => 'Alice',
            'tags' => ['admin', 'user'],
            'rows' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ];

        $toon = $manager->encode($data);

        $decoded = $manager->decode($toon);

        $this->assertSame($data, $decoded);
    }

    /**
     * Test that ToonManager can handle simple scalar round trips.
     *
     * @return void
     */
    public function testScalarRoundTrip(): void
    {
        $manager = new ToonManager();

        $value = 'hello world';

        $toon = $manager->encode($value);

        $decoded = $manager->decode($toon);

        $this->assertSame($value, $decoded);
    }
}
