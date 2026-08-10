<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\DomainModels\Service;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Points\OriginOrIntermediatePoint;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;
use Miklcct\RailOpenTimetableData\Models\Schedule;
use Miklcct\RailOpenTimetableData\Models\ScheduleEntry;
use function array_filter;
use function array_keys;
use function array_values;

class MemoryServiceRepository extends AbstractServiceRepository {
    public function __construct(readonly bool $permanentOnly = false) {
    }
    
    public function insertSchedules(array $schedules) : void {
        /** @var ScheduleEntry $service */
        foreach ($schedules as $schedule) {
            if (!$this->permanentOnly || $schedule->shortTermPlanning === ShortTermPlanning::PERMANENT) {
                $this->schedules[$schedule->uid][] = $schedule;
                if ($schedule instanceof Schedule) {
                    $location_inserted = [];
                    $rsid_inserted = [];
                    foreach ($schedule->timingPoints as $point) {
                        foreach ([$point->location->crsCode, $point->location->tiploc] as $code) {
                            if ($code !== null && empty($location_inserted[$code])) {
                                $this->schedulesAtLocations[$code][] = $schedule;
                                $location_inserted[$code] = true;
                            }
                        }
                        if ($point instanceof OriginOrIntermediatePoint) {
                            $rsid = $point->serviceProperty->rsid;
                            if ($rsid) {
                                if (empty($rsid_inserted[$rsid])) {
                                    $this->schedulesByRsid[$rsid] = $schedule;
                                    $rsid_inserted[$rsid] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function insertAssociations(array $associations) : void {
        /** @var AssociationEntry $association */
        foreach ($associations as $association) {
            if (!$this->permanentOnly || $association->shortTermPlanning === ShortTermPlanning::PERMANENT) {
                $this->childAssociations[$association->primaryUid][] = $association;
                $this->parentAssociations[$association->secondaryUid][] = $association;
            }
        }
    }

    protected function getSchedule(string $uid, Date $date) : ?ScheduleEntry {
        $result = null;
        foreach ($this->schedules[$uid] ?? [] as $schedule) {
            if ($schedule->period->isActive($date) && $schedule->isSuperior($result)) {
                $result = $schedule;
            }
        }
        return $result;
    }

    protected function getChildAssociationEntries(string $uid, Date $date) : array {
        return array_filter(
            $this->childAssociations[$uid] ?? []
            , fn(AssociationEntry $association) => $association->period->isActive($date)
        );
    }

    protected function getParentAssociationEntries(string $uid, Date $date) : array {
        return array_filter(
            $this->parentAssociations[$uid] ?? []
            , fn(AssociationEntry $association) => $association instanceof Association 
                ? $association->isActive($date, true) 
                : array_filter([-1, 0, 1], fn(int $day_offset) => $association->period->isActive($date->addDays($day_offset))) 
        );
    }

    protected function getUidsAtLocation(Location $location, TimeType $time_type, ?array $tocs = null, ?array $prefixes = null) : array {
        return array_values(
            array_unique(
                array_filter(
                    array_map(fn(Schedule $schedule) => $schedule,
                    $this->schedulesAtLocations[$location->getCrsOrTiplocCode()] ?? [])
                    , fn(Schedule $schedule) => $tocs === null || in_array($schedule->toc, $tocs)
                        && $prefixes === null || array_find($schedule->timingPoints, static fn(TimingPoint $timingPoint) => $timingPoint instanceof OriginOrIntermediatePoint && in_array(substr($timingPoint->serviceProperty->identity, 0, 2), $prefixes))
                )
            )
        );
    }

    public function getServiceByRsid(string $rsid, Date $date) : ?Service {
        $rsid = strtoupper($rsid);
        $full_rsids = strlen($rsid) === 6 
            ? array_filter(array_keys($this->schedulesByRsid), fn(string $full_rsid) => substr($full_rsid, 0, 6) === $rsid) 
            : [$rsid];
        // ensure that the whole service is returned first
        // this is required as Caledonian Sleeper portions may start on a different date from the origin
        rsort($full_rsids);
        /** @var Schedule[] $schedules */
        $schedules = array_merge(...array_map(fn(string $full_rsid) => $this->schedulesByRsid[$full_rsid] ?? [], $full_rsids));
        // I hope this won't take long
        foreach ($schedules as $schedule) {
            $result = $this->getService($schedule->uid, $date);
            if ($result !== null) {
                foreach ($result->timingPoints as $point) {
                    if ($point instanceof OriginOrIntermediatePoint) {
                        $service_rsid = $point->serviceProperty->rsid;
                        if ((strlen($rsid) === 6 ? substr($service_rsid, 0, 6) : $service_rsid) === $rsid) {
                            return $result;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    public function makePermanentRepository() : static {
        $result = new static(true);
        $result->insertSchedules($this->schedules);
        $result->insertAssociations(array_merge(...array_values($this->childAssociations)));
        return $result;
    }

    /** @var array<string, ScheduleEntry[]> */
    private array $schedules = [];

    /** @var array<string, AssociationEntry[]> */
    private array $childAssociations = [];

    /** @var array<string, AssociationEntry[]> */
    private array $parentAssociations = [];
    
    /** @var array<string, Schedule[]> */
    private array $schedulesAtLocations = [];

    /** @var array<string, Schedule[]> */
    private array $schedulesByRsid = [];
}