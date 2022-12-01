<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Exceptions\UnreachableException;
use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;
use function array_filter;
use function array_reverse;
use function count;
use function Safe\json_decode as json_decode;

class Timetable {
    // this number must be greater than the maximum number of calls for a train
    private const MULTIPLIER = 1000;

    /**
     * @param LocationWithCrs[] $stations
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
        /** @var LocationWithCrs[] $stations */
        $stations = [];
        // I hope this is good enough - I don't know how to sort the stations properly
        $arrival_mode = $board->timeType->isArrival();
        usort(
            $calls
            , static fn(
                ServiceCallWithDestinationAndCalls $a
                , ServiceCallWithDestinationAndCalls $b
            ) => -(
                count($arrival_mode ? $a->precedingCalls : $a->subsequentCalls)
                <=> count($arrival_mode ? $b->precedingCalls : $b->subsequentCalls)
            )
        );
        if ($arrival_mode) {
            $calls = array_reverse($calls);
        }
        $common_check = true;
        while (array_filter($calls) !== []) {
            $initial_count = count(array_filter($calls));
            foreach ($calls as &$call) {
                if ($call !== null) {
                    $destinations = $arrival_mode ? $call->origins : $call->destinations;
                    $portions_remaining = count($destinations);
                    foreach (array_keys($destinations) as $portion) {
                        $order = [];
                        $i = $arrival_mode ? count($stations) - 1 : 0;
                        $found_one = false;
                        foreach ($arrival_mode ? array_reverse($call->precedingCalls) : $call->subsequentCalls as $subsequent_call) {
                            if (
                                array_key_exists(
                                    $portion,
                                    $arrival_mode ? $subsequent_call->origins : $subsequent_call->destinations
                                )
                            ) {
                                $current_station = $subsequent_call->call->location;
                                if (
                                    $current_station instanceof LocationWithCrs
                                    && $current_station->getCrsCode()
                                    !== null
                                ) {
                                    $found = null;
                                    $old_i = $i;
                                    while (isset($stations[$i])) {
                                        if ($stations[$i]->getCrsCode() === $current_station->getCrsCode()) {
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
                                            /** @var LocationWithCrs $station */
                                            $station = $item[0];
                                            if ($list_direction[$index] === $station->getCrsCode()) {
                                                ++$count;
                                                $start_index = $index + 1;
                                                break;
                                            }
                                            ++$index;
                                        }
                                    }
                                    if ($count > $max_count) {
                                        $stations = array_map(
                                            static fn(string $crs) => new class($crs) implements LocationWithCrs {
                                                public function __construct(public readonly string $crsCode) {}

                                                public function getCrsCode() : string {
                                                    return $this->crsCode;
                                                }

                                                public function promoteToStation(LocationRepositoryInterface $location_repository) : ?Station {
                                                    $station = $location_repository->getLocationByCrs($this->getCrsCode());
                                                    if (!$station instanceof Station) {
                                                        return null;
                                                    }
                                                    return $station;
                                                }
                                            }
                                            , $list_direction
                                        );
                                        $max_count = $count;
                                    }
                                }
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
        /** @var LocationWithCrs[] $stations */
        $stations = array_merge([$board->calls[0]->call->location], $stations);
        foreach ($stations as &$station) {
            if (!$station instanceof Location) {
                foreach ($stations as $find_station) {
                    if ($find_station instanceof Location && $find_station->getCrsCode() === $station->getCrsCode()) {
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
            foreach (array_keys($arrival_mode ? $call->origins : $call->destinations) as $portion) {
                $matrix[0][$i] = $call;
                $j = 1;
                foreach ($arrival_mode ? $call->precedingCalls : $call->subsequentCalls as $subsequent_call) {
                    $location = $subsequent_call->call->location;
                    if ($location instanceof LocationWithCrs && array_key_exists($portion, $arrival_mode ? $subsequent_call->origins : $subsequent_call->destinations)) {
                        $subsequent_crs = $location->getCrsCode();
                        while ($stations[$j]->getCrsCode() !== $subsequent_crs) {
                            ++$j;
                            if (!isset($stations[$j])) {
                                throw new UnreachableException();
                            }
                        }
                        $matrix[$j][$i] = $subsequent_call;
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
                    if ($stations[$i]->getCrsCode() === $stations[$j]->getCrsCode()) {
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