<?php

namespace JobMetric\Toon\Tests\Feature;

use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Exceptions\ToonEncodeException;
use JobMetric\Toon\Facades\Toon;
use JobMetric\Toon\Tests\TestCase;

class ToonSpecConformanceTest extends TestCase
{
    /**
     * @throws ToonEncodeException
     */
    public function testSpecStrictRemovesFinalNewline(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = ['id' => 123];

        $encoded = Toon::encode($data);

        $this->assertSame('id: 123', $encoded);
    }

    /**
     * @throws ToonEncodeException
     * @throws ToonDecodeException
     */
    public function testKeyQuotingInObject(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = ['full name' => 'Ada'];

        $encoded = Toon::encode($data);

        $this->assertSame('"full name": Ada', $encoded);

        $decoded = Toon::decode($encoded);

        $this->assertSame($data, $decoded);
    }

    /**
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testKeyQuotingWithColonAndBrackets(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = [
            'order:id' => 7,
            '[index]'  => 5,
            '{key}'    => 3,
        ];

        $encoded = Toon::encode($data);

        $expected = "\"order:id\": 7\n\"[index]\": 5\n\"{key}\": 3";
        $this->assertSame($expected, $encoded);

        $decoded = Toon::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    /**
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testDelimiterQuotingInArrays(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $data = ['items' => ['a,b', 'c']];
        $encoded = Toon::encode($data);

        $this->assertSame('items[2]: "a,b",c', $encoded);

        $decoded = Toon::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    /**
     * @throws ToonEncodeException
     * @throws ToonDecodeException
     */
    public function testDelimiterQuotingInTabularValues(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $rows = [
            ['id' => 1, 'note' => 'a,b'],
            ['id' => 2, 'note' => 'c,d'],
        ];

        $data = ['items' => $rows];
        $encoded = Toon::encode($data);

        $expected = "items[2]{id,note}:\n  1,\"a,b\"\n  2,\"c,d\"";
        $this->assertSame($expected, $encoded);

        $decoded = Toon::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    /**
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testPathFoldingAndExpansion(): void
    {
        config()->set('laravel-toon.spec_strict', true);
        config()->set('laravel-toon.key_folding', 'safe');
        config()->set('laravel-toon.expand_paths', true);

        $data = ['a' => ['b' => ['c' => 1]]];

        $encoded = Toon::encode($data);
        $this->assertSame('a.b.c: 1', $encoded);

        $decoded = Toon::decode($encoded);
        $this->assertSame($data, $decoded);
    }

    /**
     * @throws ToonDecodeException
     */
    public function testWhitespaceToleranceDecode(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $toon = 'tags[3]: a , b , c';

        $decoded = Toon::decode($toon);

        $this->assertSame(['tags' => ['a', 'b', 'c']], $decoded);
    }

    public function testValidationErrorOnLengthMismatch(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $toon = 'tags[2]: a,b,c';

        $this->expectException(ToonDecodeException::class);

        Toon::decode($toon);
    }

    /**
     * @throws ToonDecodeException
     */
    public function testDecodeEmptyDocumentReturnsEmptyObjectInSpecMode(): void
    {
        config()->set('laravel-toon.spec_strict', true);

        $decoded = Toon::decode("");

        $this->assertSame([], $decoded);
    }
}

