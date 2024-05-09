<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Miklcct\RailOpenTimetableData\Enums\AssociationCategory;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Exceptions\UnreachableException;
use Miklcct\RailOpenTimetableData\Models\Points\CallingPoint;
use Miklcct\RailOpenTimetableData\Models\Points\DestinationPoint;
use Miklcct\RailOpenTimetableData\Models\Points\HasArrival;
use Miklcct\RailOpenTimetableData\Models\Points\HasDeparture;
use Miklcct\RailOpenTimetableData\Models\Points\OriginPoint;
use Miklcct\RailOpenTimetableData\Models\Points\PassingPoint;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;
use UnexpectedValueException;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function Miklcct\RailOpenTimetableData\get_absolute_time_zone;

/**
 * @property-read Service $service
 */
class FullService extends DatedService {
    public function __construct(
        Service $service
        , Date $date
        , public ?DatedAssociation $divideFrom
        , array $dividesJoinsEnRoute
        , public ?DatedAssociation $joinTo
    ) {
        usort(
            $dividesJoinsEnRoute
            , function (DatedAssociation $a, DatedAssociation $b) {
                assert($a->association instanceof Association);
                assert($b->association instanceof Association);
                /** @var TimingPoint[] $points */
                $points = array_map(
                    $this->service->getAssociationPoint(...)
                    , [$a->association, $b->association]
                );
                $times = array_map(
                    static fn(TimingPoint $point) =>
                        $point instanceof HasDeparture ? $point->getPublicOrWorkingDeparture() : (
                            $point instanceof HasArrival ? $point->getPublicOrWorkingArrival() : (
                                $point instanceof PassingPoint ? $point->pass : throw new UnreachableException()
                            )
                        )
                    , $points
                );
                if ($times[0]->toHalfMinutes() === $times[1]->toHalfMinutes()) {
                    // the associations are at the same call
                    if ($a->association->category === $b->association->category) {
                        /** @var DateTimeImmutable[] $timestamps */
                        $timestamps = array_map(
                            static function (DatedAssociation $dated_association) {
                                assert($dated_association->association instanceof Association);
                                assert($dated_association->secondaryService instanceof FullService);
                                return $dated_association->secondaryService->date->toDateTimeImmutable(
                                    match ($dated_association->association->category) {
                                        AssociationCategory::DIVIDE =>
                                            $dated_association->secondaryService->service->getOrigin()->getPublicOrWorkingDeparture(),
                                        AssociationCategory::JOIN =>
                                            $dated_association->secondaryService->service->getDestination()->getPublicOrWorkingArrival(),
                                        default => throw new UnreachableException(),
                                    }
                                    , $dated_association->secondaryService->getAbsoluteTimeZone()
                                );
                            }
                            , [$a, $b]
                        );
                        // multiple joins or divides happening together - order by departure / arrival time
                        // of the child portions
                        return $timestamps[0] <=> $timestamps[1];
                    }
                    // one is join and one is divide - order divide before join
                    return $a->association->category->name <=> $b->association->category->name;
                }
                return $times[0]->toHalfMinutes() <=> $times[1]->toHalfMinutes();
            }
        );
        $this->dividesJoinsEnRoute = $dividesJoinsEnRoute;
        parent::__construct($service, $date);
    }

    /** @var DatedAssociation[] */
    public array $dividesJoinsEnRoute;

    /**
     * Returns the origins of this service, listed in portion order with
     * key defining which train the origin comes from
     *
     * @return array<string, OriginPoint>
     */
    public function getOrigins(?Time $time = null) : array {
        if ($this->divideFrom === null) {
            $base = $this->service instanceof Service ? [$this->service->uid => $this->service->getOrigin()] : [];
            $portions = [];
        } else {
            $base = [];
            $portions = [$this->divideFrom->primaryService];
        }
        $portions = array_merge(
            $portions
            , array_map(
                static fn(DatedAssociation $association) => $association->secondaryService
                , array_values(
                    array_filter(
                        $this->dividesJoinsEnRoute
                        , function (DatedAssociation $association) use ($time) {
                            if (!$association->association instanceof Association) {
                                return false;
                            }
                            if ($association->association->category !== AssociationCategory::JOIN) {
                                return false;
                            }
                            assert($this->service instanceof Service);
                            $association_point = $this->service->getAssociationPoint($association->association);
                            assert($association_point instanceof CallingPoint);
                            return $time === null
                                || $association_point->getPublicOrWorkingDeparture()->toHalfMinutes() <= $time->toHalfMinutes();
                        }
                    )
                )
            )
        );
        return array_merge(
            $base
            , ...array_map(
                static function(DatedService $portion) {
                    if (!$portion instanceof self) {
                        throw new LogicException('Listing all origins requires all services being full services.');
                    }
                    return $portion->getOrigins();
                }
                , $portions
            )
        );
    }

    /**
     * Returns the destination of this service, listed in portion order with
     * key defining which train the destination comes from
     *
     * @return array<string, DestinationPoint>
     */
    public function getDestinations(?Time $time = null) : array {
        if ($this->joinTo === null) {
            $base = $this->service instanceof Service ? [$this->service->uid => $this->service->getDestination()] : [];
            $portions = [];
        } else {
            $base = [];
            $portions = [$this->joinTo->primaryService];
        }
        $portions = array_merge(
            $portions
            , array_map(
                static fn(DatedAssociation $association) => $association->secondaryService
                , array_values(
                    array_filter(
                        $this->dividesJoinsEnRoute
                        , function (DatedAssociation $association) use ($time) {
                            if (!$association->association instanceof Association) {
                                return false;
                            }
                            if ($association->association->category !== AssociationCategory::DIVIDE) {
                                return false;
                            }
                            assert($this->service instanceof Service);
                            $association_point = $this->service->getAssociationPoint($association->association);
                            assert($association_point instanceof CallingPoint);
                            return $time === null
                                || $association_point->getPublicOrWorkingArrival()->toHalfMinutes() >= $time->toHalfMinutes();
                        }
                    )
                )
            )
        );
        return array_merge(
            $base
            , ...array_map(
                static function(DatedService $portion) {
                    if (!$portion instanceof self) {
                        throw new LogicException('Listing all destinations requires all services being full services.');
                    }
                    return $portion->getDestinations();
                }
                , $portions
            )
        );
    }

    /**
     * @return ServiceCallWithDestinationAndCalls[]
     */
    public function getCalls(
        TimeType $time_type
        , string $crs = null
        , DateTimeImmutable $from = null
        , DateTimeImmutable $to = null
        , bool $with_subsequent_calls = false
        , DateTimeImmutable $base = null
    ) : array {
        $this_portion = parent::getCalls($time_type, $crs, $from, $to);
        foreach ($this_portion as &$service_call) {
            $time = $service_call->call->getTime($service_call->timeType);
            $origins = $this->getOrigins($time);
            $destinations = $this->getDestinations($time);
            if ($with_subsequent_calls) {
                $preceding_calls = $this->getCalls(
                    match ($service_call->timeType) {
                        TimeType::WORKING_ARRIVAL => TimeType::WORKING_DEPARTURE,
                        TimeType::PUBLIC_ARRIVAL => TimeType::PUBLIC_DEPARTURE,
                        default => $service_call->timeType
                    }
                    , null
                    , null
                    , $service_call->timestamp
                    , false
                    , $service_call->timestamp
                );
                $subsequent_calls = $this->getCalls(
                    match ($service_call->timeType) {
                        TimeType::WORKING_DEPARTURE => TimeType::WORKING_ARRIVAL,
                        TimeType::PUBLIC_DEPARTURE => TimeType::PUBLIC_ARRIVAL,
                        default => $service_call->timeType
                    }
                    , null
                    , $service_call->timestamp->add(new DateInterval('PT1S'))
                    , null
                    , false
                    , $service_call->timestamp
                );
                $service_call = new ServiceCallWithDestinationAndCalls(
                    $service_call->timestamp
                    , $service_call->timeType
                    , $service_call->uid
                    , $service_call->date
                    , $service_call->call
                    , $service_call->mode
                    , $service_call->toc
                    , $service_call->serviceProperty
                    , $origins
                    , $destinations
                    , $preceding_calls
                    , $subsequent_calls
                    , $service_call->shortTermPlanning
                );
            } else {
                $service_call = new ServiceCallWithDestination(
                    $service_call->timestamp
                    , $service_call->timeType
                    , $service_call->uid
                    , $service_call->date
                    , $service_call->call
                    , $service_call->mode
                    , $service_call->toc
                    , $service_call->serviceProperty
                    , $origins
                    , $destinations
                    , $service_call->shortTermPlanning
                );
            }
        }
        unset($service_call);
        if ($this->joinTo === null) {
            $join_portion = [];
        } else {
            $association = $this->joinTo->association;
            assert($association instanceof Association);
            $primary_service = $this->joinTo->primaryService;
            assert($primary_service instanceof self);
            $association_point = $primary_service->service->getAssociationPoint($association);
            assert($association_point instanceof CallingPoint);
            $association_timestamp = $primary_service->date->toDateTimeImmutable(
                $association_point->getPublicOrWorkingDeparture()
                , $primary_service->getAbsoluteTimeZone()
            );
            $join_portion = $primary_service->getCalls(
                $time_type
                , $crs
                , $from !== null && $from > $association_timestamp ? $from : $association_timestamp
                , $to
                , $with_subsequent_calls
            );
        }
        if ($this->divideFrom === null) {
            $divide_portion = [];
        } else {
            $association = $this->divideFrom->association;
            assert($association instanceof Association);
            $primary_service = $this->divideFrom->primaryService;
            assert($primary_service instanceof self);
            $association_point = $primary_service->service->getAssociationPoint($association);
            assert($association_point instanceof CallingPoint);
            $association_timestamp = $primary_service->date->toDateTimeImmutable(
                $association_point->getPublicOrWorkingArrival()
                , $primary_service->getAbsoluteTimeZone()
            );
            $divide_portion = $primary_service->getCalls(
                $time_type
                , $crs
                , $from
                , $to !== null && $to < $association_timestamp ? $to : $association_timestamp
                , $with_subsequent_calls
            );
        }
        $other_portions = array_merge(
            ...array_map(
                function (DatedAssociation $dated_association) use ($base, $with_subsequent_calls, $time_type, $crs, $to, $from) {
                    $association = $dated_association->association;
                    assert($association instanceof Association);
                    $secondary_service = $dated_association->secondaryService;
                    assert($secondary_service instanceof FullService);
                    $association_point = $this->service->getAssociationPoint($association);
                    assert($association_point instanceof CallingPoint);
                    if ($association->category === AssociationCategory::DIVIDE) {
                        $divide_timestamp = $this->date->toDateTimeImmutable(
                            $association_point->getPublicOrWorkingArrival()
                            , $this->getAbsoluteTimeZone()
                        );
                        return $divide_timestamp < $from || $divide_timestamp < $base
                            ? []
                            : $secondary_service->getCalls(
                                $time_type
                                , $crs
                                , $secondary_service->date->toDateTimeImmutable(
                                    $secondary_service->service->getOrigin()->getPublicOrWorkingDeparture()
                                )
                                , $to
                                , $with_subsequent_calls
                            );
                    }
                    if ($association->category === AssociationCategory::JOIN) {
                        $join_timestamp = $this->date->toDateTimeImmutable(
                            $association_point->getPublicOrWorkingDeparture()
                            , $this->getAbsoluteTimeZone()
                        );
                        return $join_timestamp > $to || $join_timestamp > $base
                            ? []
                            : $secondary_service->getCalls(
                                $time_type
                                , $crs
                                , $from
                                , $secondary_service->date->toDateTimeImmutable(
                                    $secondary_service->service->getDestination()->getPublicOrWorkingArrival()
                                    , $secondary_service->getAbsoluteTimeZone()
                                )
                                , $with_subsequent_calls
                            );
                    }
                    throw new UnexpectedValueException('Unknown association type');
                }
                , $this->dividesJoinsEnRoute
            )
        );
        $result = array_merge($divide_portion, $this_portion, $join_portion, $other_portions);
        usort(
            $result
            , static fn(ServiceCallWithDestination $a, ServiceCallWithDestination $b)
                => $a->timestamp <=> $b->timestamp
        );
        return $result;
    }
}
