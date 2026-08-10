<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\DomainModels;

use InvalidArgumentException;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceCall;
use function array_filter;
use function array_key_exists;
use function array_merge;

readonly class DepartureBoard {
    /**
     * @param TimeType $timeType
     * @param ServiceCall[] $calls
     */
    public function __construct(
        public TimeType $timeType
        , public array $calls
    ) {
        if ($this->timeType === TimeType::PASS) {
            throw new InvalidArgumentException("Building a departure board of passing points is not supported.");
        }
        $this->callMatrix = $this->buildCallMatrix();
    }

    public function isPortionOvertaken(ServiceCall $self_departure, string $destination_crs, string $portion_uid) : bool {
        $arrival_mode = $this->timeType->isArrival();

        $self_arrival = null;
        foreach ($arrival_mode ? array_reverse($self_departure->getPrecedingCalls($this->timeType->isPublic())) : $self_departure->getSubsequentCalls($this->timeType->isPublic()) as $arrival) {
            $location = $arrival->timingPoint->location;
            if (
                array_key_exists($portion_uid, $arrival_mode ? $arrival->origins : $arrival->destinations)
                && $location->getCrsOrTiplocCode() === $destination_crs
            ) {
                $self_arrival = $arrival;
                break;
            }
        }
        if ($self_arrival === null) {
            return true;
        }

        return $this->isCallOvertaken($self_departure, $self_arrival);
    }

    public function isCallOvertaken(
        ServiceCall $self_departure
        , ServiceCall $self_arrival
    ) : bool {
        $location = $self_arrival->timingPoint->location;
        $destination_crs = $location->getCrsOrTiplocCode();
        if (!isset($this->callMatrix[$destination_crs])) {
            return false;
        }

        $entries = $this->callMatrix[$destination_crs];

        $self_here_dt = $self_departure->getTimestamp($this->timeType);
        $self_there_dt = $self_arrival->getTimestamp($this->timeType->getCompanion());

        if ($self_here_dt === null || $self_there_dt === null) {
            return false;
        }

        $self_here = $self_here_dt->getTimestamp();
        $self_there = $self_there_dt->getTimestamp();

        $arrival_mode = $this->timeType->isArrival();
        $here_times = array_keys($entries);
        $there_times = array_values($entries);

        // binary search for the last entry before the given time
        $start_index = 0;
        $end_index = count($entries);
        while ($end_index > $start_index) {
            $mid_point = intdiv($start_index + $end_index, 2);
            if ($arrival_mode ? $here_times[$mid_point] <= $self_here : $here_times[$mid_point] >= $self_here) {
                $start_index = $mid_point + 1;
            } else {
                $end_index = $mid_point;
            }
        }
        $other_there = $there_times[$start_index - 1] ?? null;
        
        return $other_there !== null && ($arrival_mode ? $other_there > $self_there : $other_there < $self_there);
    }

    /**
     * Filter the departure board by preceding / subsequent calls
     *
     * @param string[] $filter_crs
     * @param bool $truncate
     * @return static
     */
    /**
     * Filter the departure board by preceding / subsequent calls
     *
     * @param Location[] $filter
     * @param Location[] $inverse_filter
     * @return static
     */
    public function filterByDestination(array $filter, array $inverse_filter) : static {
        if ($filter === [] && $inverse_filter === []) {
            return $this;
        }
        return new static(
            $this->timeType
            , array_values(
                array_filter(
                    array_map(
                        function (ServiceCall $call) use ($filter, $inverse_filter) {
                            $valid_portions = [];
                            // need to do the inverse filter for each destination portion
                            foreach ($this->getPortions($call->service) as $portion) {
                                $arrival_calls_for_portion = array_filter(
                                    $this->timeType->isArrival() ? array_reverse($call->getPrecedingCalls($this->timeType->isPublic())) : array_values($call->getSubsequentCalls($this->timeType->isPublic()))
                                    , fn(ServiceCall $arrival_call) => array_key_exists($portion->uid, $this->getPortions($arrival_call->service))
                                );
                                $min_filtered_index = null;
                                if ($filter !== []) {
                                    $filtered = array_filter(
                                        $arrival_calls_for_portion,
                                        static fn(ServiceCall $arrival_call) => in_array(
                                            $arrival_call->timingPoint->location->getCrsOrTiplocCode(),
                                            array_map(fn($x) => $x->getCrsOrTiplocCode(), $filter)
                                        )
                                    );
                                    if ($filtered === []) {
                                        continue;
                                    }
                                    $min_filtered_index = array_key_first($filtered);
                                }
                                if ($inverse_filter !== []) {
                                    $inverse_filtered = array_filter(
                                        $arrival_calls_for_portion,
                                        static fn(ServiceCall $arrival_call) => in_array(
                                            $arrival_call->timingPoint->location->getCrsOrTiplocCode(),
                                            array_map(fn($x) => $x->getCrsOrTiplocCode(), $inverse_filter)
                                        )
                                    );
                                    if (array_find_key($inverse_filtered, fn($value, $key) => $min_filtered_index === null || $key < $min_filtered_index) !== null) {
                                        continue;
                                    }
                                }
                                $valid_portions[] = $portion->uid;
                            }
                            if ($valid_portions === []) {
                                return null;
                            }
                            return new ServiceCall(new Service(
                                $call->service->uid
                                , $call->service->date
                                , $call->service->period
                                , $call->service->mode
                                , $call->service->toc
                                , $call->service->timingPoints
                                , $call->service->shortTermPlanning
                                , $this->timeType->isArrival() ? array_filter($call->service->joins, fn(Service $portion) => in_array($portion->uid, $valid_portions)) : $call->service->joins
                                , $this->timeType->isDeparture() ? array_filter($call->service->divides, fn(Service $portion) => in_array($portion->uid, $valid_portions)) : $call->service->divides
                                , $call->service->divideFrom
                                , $call->service->joinTo
                            ), $call->callIndex);
                        }
                        , $this->calls
                    )
                )
            )
        );
    }

    /**
     * Group the services into sets which don't share calls.
     *
     * @return static[]
     */
    public function groupServices() : array {
        $station_groups = [];
        $result = [];
        foreach ($this->calls as $call) {
            $group_id = $station_groups === [] ? 0 : max($station_groups) + 1;
            foreach (($this->timeType->isArrival() ? $call->getPrecedingCalls($this->timeType->isPublic()) : $call->getSubsequentCalls($this->timeType->isPublic())) ?: [$call] as $subsequent_call) {
                $timingPoint = $subsequent_call->timingPoint;
                $location = $timingPoint->location;
                if ($location->getCrsOrTiplocCode() !== null) {
                    $subsequent_crs = $location->getCrsOrTiplocCode();
                    if (isset($station_groups[$subsequent_crs])) {
                        $group_to_be_joined = $station_groups[$subsequent_crs];
                        if ($group_to_be_joined !== $group_id) {
                            foreach ($station_groups as &$station_group) {
                                if ($station_group === $group_id) {
                                    $station_group = $group_to_be_joined;
                                }
                            }
                            unset($station_group);
                            $result[$group_to_be_joined] = array_merge($result[$group_to_be_joined], $result[$group_id] ?? []);
                            unset($result[$group_id]);
                            $group_id = $group_to_be_joined;
                        }
                    } else {
                        $station_groups[$subsequent_crs] = $group_id;
                    }
                }
            }
            $result[$group_id][] = $call;
        }
        foreach ($result as &$group) {
            usort($group, fn(ServiceCall $a, ServiceCall $b) => $a->getTimestamp($this->timeType) <=> $b->getTimestamp($this->timeType));
        }
        unset($group);
        return array_map(
            fn(array $calls) => new static($this->timeType, $calls)
            , $result
        );
    }

    /**
     * @return Location[]
     */
    public function getDestinations() : array {
        $destinations = [];
        foreach ($this->calls as $service_call) {
            foreach (
                $this->getPortions($service_call->service) as $portion
            ) {
                $destination = ($this->timeType->isArrival() ? array_first($portion->timingPoints) : array_last($portion->timingPoints))->location;
                if ($destination->isSuperior($destinations[$destination->getCrsOrTiplocCode()] ?? null)) {
                    $destinations[$destination->getCrsOrTiplocCode()] = $destination;
                }
            }
        }

        // remove destinations that are intermediate stations on other services, as long as the real destination is still listed
        // If trains go both A-B-C and A-C-B, one of them will be returned
        foreach ($this->calls as $service_call) {
            $to_be_removed = [];
            foreach ($this->getSubsequentOrPrecedingCalls($service_call) as $subsequent_call) {
                $timingPoint = $subsequent_call->timingPoint;
                $intermediate_location = $timingPoint->location;
                foreach ($this->getPortions($subsequent_call->service) as $portion) {
                    $removing = $to_be_removed[$portion->uid] ?? [];
                    $destinations = array_filter($destinations, static fn(Location $location) => !in_array($location->getCrsOrTiplocCode(), $removing));
                    $destination_crses = array_map(fn(Location $location) => $location->getCrsOrTiplocCode(), $destinations);
                    if (
                        in_array($intermediate_location->getCrsOrTiplocCode(), $destination_crses)
                        && in_array($portion->getPortionDestination()->location->getCrsOrTiplocCode(), $destination_crses)
                    ) {
                        $to_be_removed[$portion->uid][] = $intermediate_location->getCrsOrTiplocCode();
                    }
                }
            }
        }
        return array_values($destinations);
    }


    public function getViaPoint() : ?Location {
        $call = array_first($this->calls);
        if ($call === null) {
            return null;
        }
        $portions = $this->getPortions($call->service);
        $subsequent_calls = $this->getSubsequentOrPrecedingCalls($call);
        foreach ($subsequent_calls as $subsequent_call) {
            if (
                array_filter(
                    $portions
                    , fn(Service $portion) => !array_key_exists($portion->uid, $this->getPortions($subsequent_call->service))
                ) === []
            ) {
                // the subsequent call covers all portions of the current call
                $all_services_called = true;
                $location = $subsequent_call->timingPoint->location;
                foreach ($this->calls as $other_call) {
                    $other_portions = $this->getPortions($other_call->service);
                    $called = false;
                    foreach ($this->getSubsequentOrPrecedingCalls($other_call) as $other_subsequent_call) {
                        $timingPoint2 = $other_subsequent_call->timingPoint;
                        if ($timingPoint2->location->getCrsOrTiplocCode() === $location->getCrsOrTiplocCode()) {
                            if (
                                array_filter(
                                    $other_portions
                                    , fn(Service $portion) => !array_key_exists($portion->uid, $this->getPortions($other_subsequent_call->service))
                                ) === []
                            ) {
                                if ($timingPoint2->location->isSuperior($location)) {
                                    $location = $timingPoint2->location;
                                }
                                $called = true;
                            }
                        }
                    }
                    if (!$called) {
                        $all_services_called = false;
                        break;
                    }
                }
                if ($all_services_called) {
                    return $location;
                }
            }
        }
        return null;
    }

    private function buildCallMatrix() : array {
        $result = [];
        $is_public = $this->timeType->isPublic();
        $arrival_mode = $this->timeType->isArrival();
        $companion_time_type = $this->timeType->getCompanion();

        // assign the arrival times indexed by departure times
        foreach ($this->calls as $here_call) {
            $t_here_dt = $here_call->getTimestamp($this->timeType);
            if ($t_here_dt === null) {
                continue;
            }
            $t_here = $t_here_dt->getTimestamp();

            $there_calls = $arrival_mode
                ? $here_call->getPrecedingCalls($is_public)
                : $here_call->getSubsequentCalls($is_public);

            foreach ($there_calls as $there_call) {
                $location = $there_call->timingPoint->location;
                $crs = $location->getCrsOrTiplocCode();
                $t_there_dt = $there_call->getTimestamp($companion_time_type);
                if ($t_there_dt === null) {
                    continue;
                }
                $t_there = $t_there_dt->getTimestamp();
                
                $bucket =& $result[$crs][$t_here];
                if ($bucket === null || ($arrival_mode ? $t_there > $t_here : $t_there < $t_here)) {
                    $bucket = $t_there;
                }
                unset($bucket);
            }
        }
        
        // sort the entries in reverse departure order, assigning any earlier arrival time from later departures
        foreach ($result as &$entries) {
            if ($arrival_mode) {
                ksort($entries);
            } else {
                krsort($entries);
            }
            $previous = null;
            foreach ($entries as &$entry) {
                if ($previous !== null && ($arrival_mode ? $previous > $entry : $previous < $entry)) {
                    $entry = $previous;
                }
                $previous = $entry;
            }
            unset($entry);
        }
        unset($entries);

        return $result;
    }

    /**
     * @param Service $service
     * @return Service[]
     */
    private function getPortions(Service $service) : array {
        return $this->timeType->isArrival() ? $service->getOriginPortions() : $service->getDestinationPortions();
    }

    /**
     * @return ServiceCall[]
     */
    private function getSubsequentOrPrecedingCalls(ServiceCall $service_call) : array {
        return $this->timeType->isArrival() ? array_reverse($service_call->getPrecedingCalls($this->timeType->isPublic()))
            : $service_call->getSubsequentCalls($this->timeType->isPublic());
    }


    /** @var array<string, array{first: int[], second: int[]}> */
    private array $callMatrix;
}