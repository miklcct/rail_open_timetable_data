<?php
declare(strict_types=1);

namespace Test\Miklcct\RailOpenTimetableData\Parser;

use Miklcct\RailOpenTimetableData\Parsers\Helper;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase {
    public function testParseWeekdays() : void {
        static::assertSame(
            [true, false, false, true, false, false, false]
            , (new Helper())->parseWeekdays('0010001')
        );
    }
}