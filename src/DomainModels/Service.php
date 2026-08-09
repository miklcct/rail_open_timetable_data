<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\DomainModels;

use DateTimeZone;
use LogicException;
use Miklcct\RailOpenTimetableData\Enums\AssociationCategory;
use Miklcct\RailOpenTimetableData\Enums\Mode;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Exceptions\UnreachableException;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Period;
use Miklcct\RailOpenTimetableData\Models\Points\CallingPoint;
use Miklcct\RailOpenTimetableData\Models\Points\DestinationPoint;
use Miklcct\RailOpenTimetableData\Models\Points\OriginPoint;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;
use Miklcct\RailOpenTimetableData\Models\Schedule;
use Miklcct\RailOpenTimetableData\Models\ServiceCall;
use Miklcct\RailOpenTimetableData\Models\Station;
use Miklcct\RailOpenTimetableData\Models\Time;
use RuntimeException;
use function Miklcct\RailOpenTimetableData\get_all_tocs;

class Service {
    public readonly array $joins;
    public readonly array $divides;

    public function __construct(
        public readonly string $uid,
        public readonly Date $date,
        public readonly Period $period,
        public readonly Mode $mode,
        public readonly string $toc,
        /** @var TimingPoint[] $imingPoints The index is the calling index in the whole service, even if joins / divides happen en-route, so it may not start with 0 */
        public readonly array $timingPoints,
        public readonly ShortTermPlanning $shortTermPlanning,
        /** @var Service[] $joins */
        array $joins,
        /** @var Service[] $divides */
        array $divides,
        public readonly ?Service $divideFrom = null,
        public readonly ?Service $joinTo = null,
    ) {
        if (count($this->timingPoints) < 2) {
            throw new LogicException("A service must have at least two timing points.");
        }
        $this->divides = array_map(
            fn(Service $service) => new Service(
                $service->uid
                , $service->date
                , $service->period
                , $service->mode
                , $service->toc
                , $service->timingPoints
                , $service->shortTermPlanning
                , $service->joins
                , $service->divides
                , $this
                , $service->joinTo
            )
            , $divides
        );
        $this->joins = array_map(
            fn(Service $service) => new Service(
                $service->uid
                , $service->date
                , $service->period
                , $service->mode
                , $service->toc
                , $service->timingPoints
                , $service->shortTermPlanning
                , $service->joins
                , $service->divides
                , $service->divideFrom
                , $this
            )
            , $joins
        );
        $line = [];
        foreach ($timingPoints as $i => $point) {
            $location = $point->location;
            $data = $location->getCoordinates();
            if ($data !== null) {
                $line[] = $data;
            } elseif ($location instanceof Station) {
                $line[] = [$location->easting, $location->northing];
            }
            $this->callingIndicesForLocation[$location->getCrsOrTiplocCode()][] = $i;
        }
        $this->line = $line;
    }

    /** @var int[][] */
    public readonly array $line;
    
    /** @var array<string, int[]> */
    private array $callingIndicesForLocation;

    public function getTocName() : string {
        return get_all_tocs()[$this->toc] ?? $this->toc;
    }
    
    public function getPortionOrigin() : OriginPoint {
        $result = $this->timingPoints[0];
        assert($result instanceof OriginPoint);
        return $result;
    }
    
    public function getPortionDestination() : DestinationPoint {
        $result = array_last($this->timingPoints);
        assert($result instanceof DestinationPoint);
        return $result;
    }


    public function getOriginPortions() : array {
        return $this->divideFrom?->getOriginPortions()
            ?? ($this->joins ? array_merge(...array_map(fn(Service $service) => $service->getOriginPortions(), $this->joins)) : [$this->uid => $this]);
    }

    public function getDestinationPortions() : array {
        return $this->joinTo?->getDestinationPortions()
            ?? ($this->divides ? array_merge(...array_map(fn(Service $service) => $service->getDestinationPortions(), $this->divides)) : [$this->uid => $this]);
    }

    /**
     * @param Location $location
     * @param Service|null $recursed_from
     * @return ServiceCall[]
     */
    public function findCallInSameUid(Location $location, ?Service $recursed_from = null) : array {
        /** @var ServiceCall[] $result */
        $code = $location->getCrsOrTiplocCode();
        $result = array_map(fn(int $index) => new ServiceCall($this, $index), $this->callingIndicesForLocation[$code] ?? []);

        foreach (array_filter([$this->divideFrom, $this->joinTo, ...$this->joins, ...$this->divides], fn(?Service $portion) => $portion !== $recursed_from && $portion?->uid === $this->uid) as $portion) {
            $result = array_merge($result, $portion->findCallInSameUid($location, $this));
        }

        return $result;
    }

    public static function isRsid(string $uid_or_rsid) : int {
        return preg_match('/^[A-Z]{2}(\d{4}|\d{6})$/', $uid_or_rsid);
    }

    public function getAbsoluteTimeZone() : DateTimeZone {
        if (!array_key_exists(0, $this->timingPoints)) {
            // this is a section after joining / dividing, try to find the origin
            $origin_portion = array_find($this->getOriginPortions(), fn(Service $portion) => $portion->uid === $this->uid);
            if ($origin_portion === null) {
                throw new UnreachableException("Cannot find the origin portion for UID $this->uid starting at stop index " . array_key_first($this->timingPoints));
            }
            return $origin_portion->getAbsoluteTimeZone();
        }
        // handle extra departures on London Overground during autumn BST/GMT change
        $time = $this->timingPoints[0]->getTime(TimeType::WORKING_DEPARTURE);
        if (
            $this->toc === 'LO' && $this->shortTermPlanning === ShortTermPlanning::NEW
            && $this->period->from->compare($this->period->to) === 0
            && $this->period->from->month === 10
            && $this->period->from->day >= 25
            && $this->period->weekdays[0]
            && $time->hours === 1
        ) {
            return new DateTimeZone("UTC");
        }

        $date_time = $this->date->toDateTimeImmutable($time);
        // The difference is to handle departure time in the "missing hour" such as the 01:05 from Waterloo
        $utc_offset = $date_time->getOffset() + ($time->secondsFromOrigin - Time::fromDateTimeInterface($date_time)->secondsFromOrigin);
        $negative = $utc_offset < 0;
        $hours = intdiv(abs($utc_offset), 60 * 60);
        $minutes = intdiv(abs($utc_offset) - $hours * 60 * 60, 60);
        return new DateTimeZone(sprintf('%s%02d:%02d', $negative ? '-' : '+', $hours, $minutes));
    }

    public static function fromSchedule(
        Schedule $schedule,
        Date $date,
    ) : Service {
        return new Service(
            $schedule->uid,
            $date,
            $schedule->period,
            $schedule->mode,
            $schedule->toc,
            $schedule->timingPoints,
            $schedule->shortTermPlanning,
            [],
            []
        );
    }

    /**
     * @param AssociationWithService[] $associationsWithServices
     * @return Service
     */
    public function processChildren(array $associationsWithServices) : self {
        /** @var AssociationWithService[][] $indexed_associations */
        $indexed_associations = [];
        foreach ($associationsWithServices as $association) {
            $indexed_associations[$association->primaryIndex][] = $association;
        }
        ksort($indexed_associations);
        $get_child = fn(AssociationWithService $data) => $data->secondaryService;

        /**
         * @var int $assoc_index
         * @var AssociationWithService[] $association_data
         */
        foreach ($indexed_associations as $assoc_index => $association_data) {
            if (!($assoc_index > array_key_first($this->timingPoints) && $assoc_index < array_key_last($this->timingPoints))) {
                // This application currently doesn't support associations at the beginning or end of the service.
                continue;
            }
            $joins = array_map($get_child, array_filter($association_data, fn(AssociationWithService $data) => $data->association->category === AssociationCategory::JOIN));
            $divides = array_map($get_child, array_filter($association_data, fn(AssociationWithService $data) => $data->association->category === AssociationCategory::DIVIDE));
            if ($joins && $divides) {
                throw new RuntimeException("This application currently doesn't support joins and divides simultaneously at the same location.");
            }
            
            /** @var TimingPoint[] $first_portion */
            $first_portion = [];
            /** @var TimingPoint[] $second_portion */
            $second_portion = [];
            foreach ($this->timingPoints as $i => $point) {
                if ($i < $assoc_index) {
                    $first_portion[$i] = $point;
                } elseif ($i === $assoc_index) {
                    if (!$point instanceof CallingPoint) {
                        throw new LogicException("split / join operations can only be done at a calling point");
                    }
                    $first_portion[$i] = new DestinationPoint(
                        $point->location,
                        $point->locationSuffix,
                        $point->platform,
                        $point->path,
                        $point->workingArrival,
                        $point->publicArrival,
                        $point->activities
                    );
                    $second_portion[$i] = new OriginPoint(
                        $point->location,
                        $point->locationSuffix,
                        $point->platform,
                        $point->line,
                        $point->workingDeparture,
                        $point->publicDeparture,
                        $point->engineeringAllowance,
                        $point->pathingAllowance,
                        $point->performanceAllowance,
                        $point->activities,
                        $point->serviceProperty
                    );
                } else {
                    $second_portion[$i] = $point;
                }
            }
            
            $remaining_associations = array_filter($associationsWithServices, fn(AssociationWithService $data) => $data->primaryIndex > $assoc_index);
            
            if ($joins) {
                return new self(
                    $this->uid,
                    $this->date,
                    $this->period,
                    $this->mode,
                    $this->toc,
                    $second_portion,
                    $this->shortTermPlanning,
                    [
                        new self(
                            $this->uid,
                            $this->date,
                            $this->period,
                            $this->mode,
                            $this->toc,
                            $first_portion,
                            $this->shortTermPlanning,
                            $this->joins,
                            []
                        ),
                        ...$joins,
                    ],
                    $divides
                )->processChildren($remaining_associations);
            }
            if ($divides) {
                return new self(
                    $this->uid,
                    $this->date,
                    $this->period,
                    $this->mode,
                    $this->toc,
                    $first_portion,
                    $this->shortTermPlanning,
                    $this->joins,
                    [
                        new self(
                            $this->uid,
                            $this->date,
                            $this->period,
                            $this->mode,
                            $this->toc,
                            $second_portion,
                            $this->shortTermPlanning,
                            [],
                            $this->divides
                        )->processChildren($remaining_associations),
                        ...$divides,
                    ]
                );
            }
        }
        
        return $this;
    }

    public function getAssociationIndex(Association $association) : ?int {
        $secondary = $association->secondaryUid === $this->uid;
        foreach ($this->timingPoints as $i => $point) {
            $association_suffix = $secondary
                ? $association->secondarySuffix
                : $association->primarySuffix;
            if (
                $point->location->tiploc === $association->location->tiploc 
                && (empty($association_suffix) || $point->locationSuffix === $association_suffix)
            ) {
                return $i;
            }
        }
        return null;
    }
}
