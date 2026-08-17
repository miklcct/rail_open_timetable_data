<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\DomainModels;

use Miklcct\RailOpenTimetableData\Exceptions\UnreachableException;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;
use Miklcct\RailOpenTimetableData\Models\ServiceCall;
use function array_filter;
use function array_reverse;
use function count;
use function Safe\json_decode as json_decode;

class Timetable {
    // this number must be greater than the maximum number of calls for a train
    private const int MULTIPLIER = 1000;

    /**
     * @param Location[] $stations
     * @param ServiceCall[][] $calls
     */
    public function __construct(
        public readonly array $stations
        , public readonly array $calls
    ) {
    }

    public static function generateFromBoard(DepartureBoard $board) : static {
        $calls = $board->calls;
        // try to order the stations
        /** @var Location[] $stations */
        $stations = [];
        // I hope this is good enough - I don't know how to sort the stations properly
        $arrival_mode = $board->timeType->isArrival();
        $public_mode = $board->timeType->isPublic();
        usort(
            $calls
            , static fn(
            ServiceCall $a
            , ServiceCall $b
        ) => -(
            count($arrival_mode ? $a->getPrecedingCalls($public_mode) : $a->getSubsequentCalls($public_mode))
            <=> count($arrival_mode ? $b->getPrecedingCalls($public_mode) : $b->getSubsequentCalls($public_mode))
        )
        );
        $common_check = true;
        while (array_filter($calls) !== []) {
            $initial_count = count(array_filter($calls));
            foreach ($calls as &$call) {
                if ($call !== null) {
                    $portions = $arrival_mode ? $call->service->getOriginPortions() : $call->service->getDestinationPortions();
                    $portions_remaining = count($portions);
                    foreach (array_keys($portions) as $portion) {
                        $order = [];
                        $i = $arrival_mode ? count($stations) - 1 : 0;
                        $found_one = false;
                        foreach ($arrival_mode ? array_reverse($call->getPrecedingCalls($public_mode)) : $call->getSubsequentCalls($public_mode) as $subsequent_call) {
                            if (
                                array_key_exists(
                                    $portion,
                                    $arrival_mode ? $subsequent_call->service->getOriginPortions() : $subsequent_call->service->getDestinationPortions()
                                )
                            ) {
                                $timingPoint2 = $subsequent_call->timingPoint;
                                $current_station = $timingPoint2->location;
                                $found = null;
                                $old_i = $i;
                                while (isset($stations[$i])) {
                                    if ($stations[$i]->getCrsOrTiplocCode() === $current_station->getCrsOrTiplocCode()) {
                                        $found = $i;
                                        $i += $arrival_mode ? -1 : 1;
                                        $found_one = true;
                                        break;
                                    }
                                    $i += $arrival_mode ? -1 : 1;
                                }
                                if ($found === null) {
                                    $i = $old_i;
                                }
                                $order[] = [$current_station, $found === null ? null : $found * self::MULTIPLIER];
                            }
                        }
                        if ($common_check && !$found_one && $stations !== []) {
                            // current portion has no common calls with processed services
                            // try another one first
                            continue;
                        }
                        if ($stations === []) {
                            // seed stations from pregenerated list
                            $preorder = json_decode(file_get_contents(__DIR__ . '/../../resource/stop_orders.json'));
                            $max_count = 0;
                            foreach ($preorder as $list) {
                                foreach ([$list, array_reverse($list)] as $list_direction) {
                                    $count = 0;
                                    $start_index = 0;
                                    foreach ($order as $item) {
                                        $index = $start_index;
                                        while (isset($list_direction[$index])) {
                                            /** @var Location $station */
                                            $station = $item[0];
                                            if ($list_direction[$index] === $station->getCrsOrTiplocCode()) {
                                                ++$count;
                                                $start_index = $index + 1;
                                                break;
                                            }
                                            ++$index;
                                        }
                                    }
                                    if ($count > $max_count) {
                                        $stations = array_map(
                                            static fn(string $crs) => new readonly class($crs) extends Location{
                                                public function __construct(string $crsCode) {
                                                    parent::__construct("$crsCode---", $crsCode, $crsCode);
                                                }
                                            }
                                            , $list_direction
                                        );
                                        $max_count = $count;
                                    }
                                }
                            }
                            if ($stations !== []) {
                                // stations have now seeded - need to rearrange index again
                                continue;
                            }
                        }

                        foreach ($order as $j => $item) {
                            if ($item[1] !== null) {
                                for ($k = $j - 1; $k >= 0 && $order[$k][1] === null; --$k) {
                                    $order[$k][1] = $item[1] + (self::MULTIPLIER - 1 - $k) * ($arrival_mode ? 1 : -1);
                                }
                            }
                        }
                        $max = count($stations);
                        foreach ($order as &$item) {
                            if ($item[1] === null) {
                                $item[1] = $max++ * self::MULTIPLIER * ($arrival_mode ? -1 : 1);
                            }
                        }
                        unset($item);

                        foreach ($order as $i => $item) {
                            if ($i > 0) {
                                assert(($order[$i - 1][1] <=> $order[$i][1]) === ($arrival_mode ? 1 : -1));
                            }
                        }

                        $new_stations = array_reduce(
                            $order
                            , static fn(array $carry, array $item) : array => [$item[1] => $item[0]] + $carry
                            , array_combine(
                                array_map(
                                    static fn(int $x) => $x * self::MULTIPLIER
                                    , array_keys($stations)
                                )
                                , array_values($stations)
                            )
                        );
                        ksort($new_stations);
                        $stations = array_values($new_stations);
                        --$portions_remaining;
                    }
                    if ($portions_remaining === 0) {
                        $call = null;
                    }
                }
            }
            unset($call);
            if (count(array_filter($calls)) === $initial_count) {
                $common_check = false;
            }
        }
        /** @var Location[] $stations */
        $origin = array_reduce($board->calls, fn(Location|null $carry, ServiceCall $item) => $item->timingPoint->location->isSuperior($carry) ? $item->timingPoint->location : $carry);
        $stations = array_merge([$origin], $stations);
        foreach ($stations as &$station) {
            if (!$station instanceof Location) {
                foreach ($stations as $find_station) {
                    if ($find_station instanceof Location && $find_station->getCrsOrTiplocCode() === $station->getCrsOrTiplocCode()) {
                        $station = $find_station;
                    }
                }
            }
        }
        unset($station);

        $matrix = [];

        // fill the calls matrix
        $i = 0;
        foreach ($board->calls as $call) {
            foreach (array_keys($arrival_mode ? $call->service->getOriginPortions() : $call->service->getDestinationPortions()) as $portion) {
                $matrix[0][$i] = $call;
                $j = 1;
                foreach ($arrival_mode ? $call->getPrecedingCalls($public_mode) : $call->getSubsequentCalls($public_mode) as $subsequent_call) {
                    $timingPoint = $subsequent_call->timingPoint;
                    $location = $timingPoint->location;
                    if (array_key_exists($portion, $arrival_mode ? $subsequent_call->service->getOriginPortions() : $subsequent_call->service->getDestinationPortions())) {
                        $subsequent_crs = $location->getCrsOrTiplocCode();
                        while ($stations[$j]->getCrsOrTiplocCode() !== $subsequent_crs) {
                            ++$j;
                            if (!isset($stations[$j])) {
                                throw new UnreachableException();
                            }
                        }
                        $matrix[$j][$i] = $subsequent_call;
                        if ($location->isSuperior($stations[$j])) {
                            $stations[$j] = $location;
                        }
                        ++$j;
                    }
                }
                ++$i;
            }
        }

        // check if duplicated stations can be simplified
        foreach (array_keys($stations) as $key) {
            if (!array_key_exists($key, $matrix)) {
                unset($stations[$key]);
            }
        }
        ksort($matrix);
        ksort($stations);
        $stations = array_values($stations);
        $matrix = array_values($matrix);
        do {
            $removed_duplication = false;
            for ($i = $arrival_mode ? 1 : count($stations) - 1; $arrival_mode ? $i <= count($stations) - 1 : $i >= 1; $i += $arrival_mode ? 1 : -1) {
                for ($j = $i + ($arrival_mode ? 1 : - 1); $arrival_mode ? $j <= count($stations) - 1 : $j >= 1; $j += $arrival_mode ? 1 : -1) {
                    if ($stations[$i]->getCrsOrTiplocCode() === $stations[$j]->getCrsOrTiplocCode()) {
                        $failed = false;
                        foreach (array_keys($matrix[0]) as $column) {
                            if (isset($matrix[$j][$column])) {
                                for (
                                    $k = $j + ($arrival_mode ? -1 : 1);
                                    $arrival_mode ? $k >= $i : $k <= $i;
                                    $k += $arrival_mode ? -1 : 1
                                ) {
                                    if (isset($matrix[$k][$column])) {
                                        $failed = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if (!$failed) {
                            foreach ($matrix[$j] as $column => $call) {
                                $matrix[$i][$column] = $call;
                            }
                            if ($stations[$j]->isSuperior($stations[$i])) {
                                $stations[$i] = $stations[$j];
                            }
                            unset($matrix[$j]);
                            unset($stations[$j]);
                            $stations = array_values($stations);
                            $matrix = array_values($matrix);
                            $removed_duplication = true;
                            break 2;
                        }
                    }
                }
            }
        } while ($removed_duplication);

        return new static($stations, $matrix);
    }
}