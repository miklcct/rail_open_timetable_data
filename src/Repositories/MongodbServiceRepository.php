<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\DomainModels\Service;
use Miklcct\RailOpenTimetableData\Enums\Activity;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Points\OriginOrIntermediatePoint;
use Miklcct\RailOpenTimetableData\Models\Schedule;
use Miklcct\RailOpenTimetableData\Models\ScheduleEntry;
use MongoDB\BSON\Regex;
use MongoDB\Collection;
use MongoDB\Database;
use stdClass;
use function array_chunk;
use function array_map;
use function array_unique;
use function array_values;
use function preg_quote;

class MongodbServiceRepository extends AbstractServiceRepository {
    /** @var array<string, ScheduleEntry|null> */
    private array $scheduleCache = [];
    /** @var array<string, AssociationEntry[]> */
    private array $childAssociationEntriesCache = [];
    /** @var array<string, AssociationEntry[]> */
    private array $parentAssociationEntriesCache = [];
    /** @var array<string, Service|null> */
    private array $serviceCache = [];

    public function __construct(
        private readonly Database $database
        , private readonly bool $permanentOnly = false
    ) {
        $this->schedulesCollection = $database->selectCollection('schedules');
        $this->associationsCollection = $database->selectCollection('associations');
    }

    protected function getSchedule(string $uid, Date $date) : ?ScheduleEntry {
        $id = $this->getServiceCacheKey($uid, $date);
        if (array_key_exists($id, $this->scheduleCache)) {
            return $this->scheduleCache[$id];
        }
        $result = $this->schedulesCollection->findOne(
            $this->getServicePredicate($uid, $date)
            // this will order STP before permanent
            ,
            ['sort' => ['shortTermPlanning' => 1]]
        );
        return $this->scheduleCache[$id] = $result instanceof ScheduleEntry ? $result : null;
    }

    protected function getChildAssociationEntries(string $uid, Date $date) : array {
        $id = $this->getServiceCacheKey($uid, $date);
        if (array_key_exists($id, $this->childAssociationEntriesCache)) {
            return $this->childAssociationEntriesCache[$id];
        }
        return $this->childAssociationEntriesCache[$id] = $this->associationsCollection->find([
            'primaryUid' => $uid,
            'period.from' => ['$lte' => $date],
            'period.to' => ['$gte' => $date],
            "period.weekdays.{$date->getWeekday()}" => true,
        ])
            ->toArray();
    }

    protected function getParentAssociationEntries(string $uid, Date $date) : array {
        $id = $this->getServiceCacheKey($uid, $date);
        if (array_key_exists($id, $this->parentAssociationEntriesCache)) {
            return $this->parentAssociationEntriesCache[$id];
        }
        $weekdays = array_unique([$date->addDays(-1)->getWeekday(), $date->getWeekday(), $date->addDays(1)->getWeekday()]);
        return $this->parentAssociationEntriesCache[$id] = $this->associationsCollection->find([
            'secondaryUid' => $uid,
            'period.from' => ['$lte' => $date->addDays(1)],
            'period.to' => ['$gte' => $date->addDays(-1)],
            '$or' => array_map(fn(int $weekday) => ["period.weekdays.$weekday" => true], $weekdays),
        ])
            ->toArray();
    }

    public function getServiceByRsid(string $rsid, Date $date) : ?Service {
        $predicate = match(strlen($rsid)) {
            6 => new Regex(sprintf('^%s\d{2,2}$', preg_quote($rsid, null)), 'i'),
            8 => $rsid,
        };

        // find the UID first
        $uids = array_values(
            array_unique(
                array_map(
                    static fn(stdClass $object) => $object->uid
                    , $this->schedulesCollection->find([
                        '$and' => [
                            [
                                'period.from' => ['$lte' => $date],
                                'period.to' => ['$gte' => $date],
                                "period.weekdays.{$date->getWeekday()}" => true,
                                'timingPoints.serviceProperty.rsid' => $predicate,
                            ],
                            $this->getShortTermPlanningPredicate(),
                        ]
                    ]
                    , [
                        'projection' => ['uid' => 1, '_id' => 0]
                    ])->toArray()
                )
            )
        );
        return $this->findServicesInUidMatchingRsid($uids, $rsid, $date);
    }

    protected function getUidsAtLocation(Location $location, TimeType $time_type, ?array $tocs = null) : array {
        $field = match ($time_type) {
            TimeType::WORKING_ARRIVAL => 'workingArrival',
            TimeType::PUBLIC_ARRIVAL => 'publicArrival',
            TimeType::PASS => 'pass',
            TimeType::PUBLIC_DEPARTURE => 'publicDeparture',
            TimeType::WORKING_DEPARTURE => 'workingDeparture',
        };

        $elemMatch = [
            '$or' => [
                ['location.crsCode' => $location->crsCode],
                ['location.tiploc' => $location->tiploc],
            ],
            $field => ['$ne' => null],
        ];
        if ($time_type->isPublic()) {
            $elemMatch['activities'] = [
                '$nin' => [
                    Activity::UNADVERTISED->value,
                    ['value' => Activity::UNADVERTISED->value],
                ],
            ];
        }

        return $this->schedulesCollection->distinct(
            'uid',
            [
                '$and' => [
                    ['timingPoints' => ['$elemMatch' => $elemMatch]],
                    $this->getShortTermPlanningPredicate(),
                    $tocs === null ? ['$expr' => true] : ['toc' => ['$in' => $tocs]],
                ],
            ]
        );
    }

    public function insertSchedules(array $schedules) : void {
        foreach (array_chunk($schedules, 10000) as $chunk) {
            if ($chunk !== []) {
                $this->schedulesCollection->insertMany($chunk);
            }
        }
    }

    public function insertAssociations(array $associations) : void {
        if ($associations !== []) {
            $this->associationsCollection->insertMany($associations);
        }
    }

    public function getService(string $uid, Date $date, ?string $recursed_from = null) : ?Service {
        $id = $this->getServiceCacheKey($uid, $date);
        if ($recursed_from === null && array_key_exists($id, $this->serviceCache)) {
            return $this->serviceCache[$id];
        }
        $schedule = $this->getSchedule($uid, $date);
        $result = $schedule instanceof Schedule ? $this->getFullService(Service::fromSchedule($schedule, $date), false, $recursed_from) : null;
        if ($recursed_from === null && $result !== null) {
            foreach ([$result, ...$result->divides, ...$result->joins] as $portion) {
                $this->serviceCache[$this->getServiceCacheKey($portion->uid, $portion->date)] = $portion;
            }
        }
        return $result;
    }

    protected function getServicesBetweenDates(array $uids, Date $from_date, Date $to_date) : array {
        $uids_to_fetch = array_values(array_unique($uids));

        $dates = [];
        $date_strings = [];
        $weekdays = [];
        for ($date = $from_date; $date->compare($to_date) <= 0; $date = $date->addDays(1)) {
            $dates[] = $date;
            $date_strings[] = (string)$date;
            $weekdays[$date->getWeekday()] = true;
        }
        $weekday_predicate = count($weekdays) === 7 ? [] : ['$or' => array_map(fn($w) => ["period.weekdays.$w" => true], array_keys($weekdays))];
        $parent_weekdays = [];
        for ($date = $from_date->addDays(-1); $date->compare($to_date->addDays(1)) <= 0; $date = $date->addDays(1)) {
            $parent_weekdays[$date->getWeekday()] = true;
            if (count($parent_weekdays) === 7) {
                break;
            }
        }
        $parent_weekday_predicate = count($parent_weekdays) === 7 ? [] : ['$or' => array_map(fn($w) => ["period.weekdays.$w" => true], array_keys($parent_weekdays))];

        $fetched_uids = [];
        $iterations = 0;
        while (!empty($uids_to_fetch) && $iterations < 3) {
            $iterations++;
            foreach ($uids_to_fetch as $uid) {
                $fetched_uids[$uid] = true;
            }

            // Fetch schedules
            $schedules = $this->schedulesCollection->find(
                [
                    '$and' => array_merge(
                        [
                            [
                                'uid' => ['$in' => $uids_to_fetch],
                                'period.from' => ['$lte' => $to_date],
                                'period.to' => ['$gte' => $from_date],
                            ],
                            $this->getShortTermPlanningPredicate(),
                        ],
                        $weekday_predicate === [] ? [] : [$weekday_predicate]
                    ),
                ]
                , ['sort' => ['shortTermPlanning' => 1]]
            )->toArray();
            $schedulesByUid = [];
            foreach ($schedules as $schedule) {
                $schedulesByUid[$schedule->uid][] = $schedule;
            }
            foreach ($uids_to_fetch as $uid) {
                $uid_schedules = $schedulesByUid[$uid] ?? [];
                foreach ($date_strings as $date_string) {
                    $id = "{$uid}_{$date_string}";
                    if (!array_key_exists($id, $this->scheduleCache)) {
                        $this->scheduleCache[$id] = null;
                    }
                }
                foreach ($uid_schedules as $schedule) {
                    $from_index = 0;
                    while ($from_index < count($dates) && $schedule->period->from->compare($dates[$from_index]) > 0) {
                        $from_index++;
                    }
                    $to_index = count($dates) - 1;
                    while ($to_index >= $from_index && $schedule->period->to->compare($dates[$to_index]) < 0) {
                        $to_index--;
                    }
                    for ($i = $from_index; $i <= $to_index; $i++) {
                        $id = "{$uid}_{$date_strings[$i]}";
                        if ($this->scheduleCache[$id] === null && $schedule->runsOnDate($dates[$i])) {
                            $this->scheduleCache[$id] = $schedule;
                        }
                    }
                }
            }

            $new_uids = [];
            // Fetch child associations
            $child_associations = $this->associationsCollection->find(
                [
                    '$and' => array_merge(
                        [
                            [
                                'primaryUid' => ['$in' => $uids_to_fetch],
                                'period.from' => ['$lte' => $to_date],
                                'period.to' => ['$gte' => $from_date],
                            ]
                        ],
                        $weekday_predicate === [] ? [] : [$weekday_predicate]
                    )
                ]
            )->toArray();
            $childAssocsByUid = [];
            foreach ($child_associations as $assoc) {
                $childAssocsByUid[$assoc->primaryUid][] = $assoc;
                if (!isset($fetched_uids[$assoc->secondaryUid])) {
                    $new_uids[$assoc->secondaryUid] = true;
                }
            }
            foreach ($uids_to_fetch as $uid) {
                $assocs = $childAssocsByUid[$uid] ?? [];
                foreach ($date_strings as $date_string) {
                    $id = "{$uid}_{$date_string}";
                    if (!array_key_exists($id, $this->childAssociationEntriesCache)) {
                        $this->childAssociationEntriesCache[$id] = [];
                    }
                }
                foreach ($assocs as $assoc) {
                    $from_index = 0;
                    while ($from_index < count($dates) && $assoc->period->from->compare($dates[$from_index]) > 0) {
                        $from_index++;
                    }
                    $to_index = count($dates) - 1;
                    while ($to_index >= $from_index && $assoc->period->to->compare($dates[$to_index]) < 0) {
                        $to_index--;
                    }
                    for ($i = $from_index; $i <= $to_index; $i++) {
                        $this->childAssociationEntriesCache["{$uid}_{$date_strings[$i]}"][] = $assoc;
                    }
                }
            }

            // Fetch parent associations
            $parent_associations = $this->associationsCollection->find(
                [
                    '$and' => array_merge(
                        [
                            [
                                'secondaryUid' => ['$in' => $uids_to_fetch],
                                'period.from' => ['$lte' => $to_date->addDays(1)],
                                'period.to' => ['$gte' => $from_date->addDays(-1)],
                            ]
                        ],
                        $parent_weekday_predicate === [] ? [] : [$parent_weekday_predicate]
                    )
                ]
            )->toArray();
            $parentAssocsByUid = [];
            foreach ($parent_associations as $assoc) {
                $parentAssocsByUid[$assoc->secondaryUid][] = $assoc;
                if (!isset($fetched_uids[$assoc->primaryUid])) {
                    $new_uids[$assoc->primaryUid] = true;
                }
            }
            foreach ($uids_to_fetch as $uid) {
                $assocs = $parentAssocsByUid[$uid] ?? [];
                foreach ($date_strings as $date_string) {
                    $id = "{$uid}_{$date_string}";
                    if (!array_key_exists($id, $this->parentAssociationEntriesCache)) {
                        $this->parentAssociationEntriesCache[$id] = [];
                    }
                }
                foreach ($assocs as $assoc) {
                    $from_index = 0;
                    // For parent associations, we check overlap with [date-1, date+1]
                    // So association period [from, to] matches date if:
                    // from <= date + 1 AND to >= date - 1
                    // which means date >= from - 1 AND date <= to + 1
                    $date_from_limit = $assoc->period->from->addDays(-1);
                    while ($from_index < count($dates) && $date_from_limit->compare($dates[$from_index]) > 0) {
                        $from_index++;
                    }
                    $to_index = count($dates) - 1;
                    $date_to_limit = $assoc->period->to->addDays(1);
                    while ($to_index >= $from_index && $date_to_limit->compare($dates[$to_index]) < 0) {
                        $to_index--;
                    }
                    for ($i = $from_index; $i <= $to_index; $i++) {
                        $this->parentAssociationEntriesCache["{$uid}_{$date_strings[$i]}"][] = $assoc;
                    }
                }
            }

            $uids_to_fetch = array_keys($new_uids);
        }

        $result = [];
        foreach ($uids as $uid) {
            foreach ($dates as $i => $date) {
                $id = "{$uid}_{$date_strings[$i]}";
                if (($this->scheduleCache[$id] ?? null) !== null) {
                    $service = $this->getService($uid, $date);
                    if ($service !== null) {
                        $result[$id] = $service;
                    }
                }
            }
        }
        return $result;
    }

    public function makePermanentRepository() : static {
        return $this->permanentOnly ? $this : new static($this->database, true);
    }

    public function addIndexes() : void {
        $this->schedulesCollection->createIndexes(
            [
                ['key' => ['uid' => 1]],
                ['key' => ['timingPoints.location.crsCode' => 1, 'period.from' => 1, 'period.to' => 1]],
                ['key' => ['timingPoints.location.tiploc' => 1, 'period.from' => 1, 'period.to' => 1]],
                ['key' => ['timingPoints.location.name' => 1, 'period.from' => 1, 'period.to' => 1]],
                ['key' => ['timingPoints.serviceProperty.rsid' => 1]],
            ]
        );
        $this->associationsCollection->createIndexes(
            [
                ['key' => ['primaryUid' => 1]],
                ['key' => ['secondaryUid' => 1]],
            ]
        );
    }

    protected function findServicesInUidMatchingRsid(array $uids, string $rsid, Date $date) : ?Service {
        $services = $this->getServicesBetweenDates($uids, $date, $date);
        $matched = null;
        $matched_rsid = null;
        foreach ($services as $service) {
            foreach ($service->timingPoints as $timingPoint) {
                if ($timingPoint instanceof OriginOrIntermediatePoint) {
                    // 8-character RSID
                    if ($timingPoint->serviceProperty->rsid === $rsid) {
                        return $service;
                    }
                    // need to make sure that the whole portion is returned, as Caledonian Sleeper has cross-midnight portion working
                    if (strlen($rsid) === 6
                        && str_starts_with($timingPoint->serviceProperty->rsid, $rsid)
                        && ($matched_rsid === null || $timingPoint->serviceProperty->rsid > $matched_rsid)) {
                            $matched = $service;
                            $matched_rsid = $timingPoint->serviceProperty->rsid;
                        }
                }
            }
        }
        return $matched;
    }

    private function getShortTermPlanningPredicate() : array {
        return $this->permanentOnly 
            ? [
                'shortTermPlanning' => ShortTermPlanning::PERMANENT->value
            ] 
            : ['$expr' => ['$eq' => [0, 0]]];
    }

    private function getServicePredicate(string $uid, Date $date) : array {
        return [
            '$and' => [
                [
                    'uid' => $uid,
                    'period.from' => ['$lte' => $date],
                    'period.to' => ['$gte' => $date],
                    "period.weekdays.{$date->getWeekday()}" => true,
                ],
                $this->getShortTermPlanningPredicate(),
            ]
        ];
    }

    private function getServiceCacheKey(string $uid, Date $date) : string {
        return $uid . '_' . $date;
    }

    private readonly Collection $schedulesCollection;
    private readonly Collection $associationsCollection;

}
