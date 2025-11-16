<?php

namespace JobMetric\Toon\Facades;

use Illuminate\Support\Facades\Facade;
use JobMetric\Toon\Contracts\ToonManagerInterface;

/**
 * @method static string encode(mixed $data)
 * @method static mixed decode(string $toon)
 */
class Toon extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return ToonManagerInterface::class;
    }
}
