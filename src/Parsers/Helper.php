<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Parsers;

use function array_map;
use function array_slice;
use function Miklcct\RailOpenTimetableData\array_rotate;
use function str_split;

class Helper {
    public function parseLine(string $line, array $widths) : array {
        return $widths === []
            ? []
            : array_merge(
                [rtrim(substr($line, 0, $widths[0]))]
                , $this->parseLine(substr($line, $widths[0]), array_slice($widths, 1))
            );
    }

    /**
     * @param string $string 7 characters with 0 and 1 each starting from Monday
     * @return bool[] 7 bits representing if active on each of the weekdays
     * (0 for Sunday, 6 for Saturday)
     */
    public function parseWeekdays(string $string) : array {
       return array_rotate(
            array_map(
                static fn(string $char) => $char !== '0'
                , str_split($string)
            )
            , -1
        );
    }
}