<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\Enums\BankHoliday;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\DatedAssociation;
use Miklcct\RailOpenTimetableData\Models\DatedService;
use Miklcct\RailOpenTimetableData\Models\DepartureBoard;
use Miklcct\RailOpenTimetableData\Models\Service;
use Miklcct\RailOpenTimetableData\Models\ServiceCallWithDestinationAndCalls;
use Miklcct\RailOpenTimetableData\Models\ServiceEntry;
use MongoDB\BSON\Regex;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\SimpleCache\CacheInterface;
use stdClass;
use function array_chunk;
use function array_filter;
use function array_map;
use function array_values;
use function Miklcct\RailOpenTimetableData\get_generated;
use function Miklcct\RailOpenTimetableData\set_generated;
use function preg_quote;

class MongodbServiceRepository extends AbstractServiceRepository {
    public function __construct(
        private readonly Database $database
        , private readonly ?CacheInterface $cache
        , bool $permanentOnly = false
    ) {
        parent::__construct($permanentOnly);
        $this->servicesCollection = $database->selectCollection('services');
        $this->associationsCollection = $database->selectCollection('associations');
    }

    protected function getAssociationEntries(string $uid, Date $date) : array {
        return $this->associationsCollection->find($this->getAssociationPredicate($uid, $date))
            ->toArray();
    }

    /**
     * @param DatedService[] $dated_services
     * @return AssociationEntry[]
     */
    protected function getAssociationEntriesForMultipleServices(array $dated_services) : array {
        return $this->associationsCollection->find(
            ['$or' => array_map(
                fn ($dated_service) => $this->getAssociationPredicate($dated_service->service->uid, $dated_service->date)
                , $dated_services
            )]
        )
            ->toArray();
    }

    public function insertServices(array $services) : void {
        foreach (array_chunk($services, 10000) as $chunk) {
            if ($chunk !== []) {
                $this->servicesCollection->insertMany($chunk);
            }
        }
    }

    public function insertAssociations(array $associations) : void {
        if ($associations !== []) {
            $this->associationsCollection->insertMany($associations);
        }
    }

    public function addIndexes() : void {
        $this->servicesCollection->createIndexes(
            [
                ['key' => ['uid' => 1]],
                ['key' => ['points.location.crsCode' => 1, 'period.from' => 1, 'period.to' => 1]],
                ['key' => ['points.serviceProperty.rsid' => 1]],
            ]
        );
        $this->associationsCollection->createIndexes(
            [
                ['key' => ['primaryUid' => 1]],
                ['key' => ['secondaryUid' => 1]],
            ]
        );
    }

    public function getService(string $uid, Date $date) : ?DatedService {
        $query_results = $this->servicesCollection->find(
            $this->getServicePredicate($uid, $date)
            // this will order STP before permanent
            , ['sort' => ['shortTermPlanning.value' => 1, 'shortTermPlanning' => 1]]
        );
        /** @var ServiceEntry $result */
        foreach ($query_results as $result) {
            if ($result->runsOnDate($date)) {
                return new DatedService($result, $date);
            }
        }
        return null;
    }

    public function getServices(array $items) : array {
        if ($items === []) {
            return [];
        }
        $query_results = $this->servicesCollection->find(
            [
                '$or' => array_map(
                    fn($item) => $this->getServicePredicate(...$item)
                    , array_values($items)
                ),
            ]
            // this will order STP before permanent
            , ['sort' => ['shortTermPlanning.value' => 1, 'shortTermPlanning' => 1]]
        );
        $results = [];
        /** @var ServiceEntry $entry */
        foreach ($query_results as $entry) {
            foreach ($items as [$uid, $date]) {
                if ($entry->uid === $uid && $entry->runsOnDate($date)) {
                    $result = new DatedService($entry, $date);
                    $results[$result->getId()] ??= $result;
                }
            }
        }

        return $results;
    }



    public function getServiceByRsid(string $rsid, Date $date) : array {
        $predicate = match(strlen($rsid)) {
            6 => new Regex(sprintf('^%s\d{2,2}$', preg_quote($rsid, null)), 'i'),
            8 => $rsid,
        };

        // find the UID first
        $uids = array_values(
            array_unique(
                array_map(
                    static fn(stdClass $object) => $object->uid
                    , $this->servicesCollection->find(
                        [
                            '$and' => [
                                [
                                    'period.from' => ['$lte' => $date],
                                    'period.to' => ['$gte' => $date],
                                    'points.serviceProperty.rsid' => $predicate,
                                ],
                                $this->getShortTermPlanningPredicate(),
                            ]
                        ]
                        , [
                            'projection' => ['uid' => 1, '_id' => 0]
                        ]
                    )->toArray()
                )
            )
        );
        return $this->findServicesInUidMatchingRsid($uids, $rsid, $date);
    }

    public function getDepartureBoard(
        string $crs,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        TimeType $time_type
    ) : DepartureBoard {
        $cache_key = sprintf('board_%s_%s_%012d_%012d_%s_%d', $this->getGeneratedDate(), $crs, $from->getTimestamp(), $to->getTimestamp(), $time_type->value, $this->permanentOnly);
        $cache_entry = $this->cache?->get($cache_key);
        if ($cache_entry !== null) {
            return $cache_entry;
        }

        $from_date = Date::fromDateTimeInterface($from)->addDays(-1);
        $to_date = Date::fromDateTimeInterface($to);
        // get skeleton services - incomplete objects!
        $query_results = $this->servicesCollection->find(
            [
                '$and' => [
                    [
                        'points' => [
                            '$elemMatch' => [
                                'location.crsCode' => $crs,
                                match($time_type) {
                                    TimeType::WORKING_ARRIVAL => 'workingArrival',
                                    TimeType::PUBLIC_ARRIVAL => 'publicArrival',
                                    TimeType::PASS => 'pass',
                                    TimeType::PUBLIC_DEPARTURE => 'publicDeparture',
                                    TimeType::WORKING_DEPARTURE => 'workingDeparture',
                                } => ['$ne' => null],
                            ],
                        ],
                        'period.from' => ['$lte' => $to_date],
                        'period.to' => ['$gte' => $from_date],
                    ],
                    $this->getShortTermPlanningPredicate(),
                ]
            ]
            , ['projection' => ['uid' => 1, '_id' => 0, 'period' => 1, 'excludeBankHoliday' => 1, 'shortTermPlanning' => 1]]
        );

        /** @var DatedService[] $possibilities */
        $possibilities = [];

        foreach ($query_results as $entry) {
            // this is to make the code compatible with both before and after https://github.com/mongodb/mongo-php-driver/pull/1378
            $get_value_or_self = function ($item) {
                return is_object($item) ? $item->value : $item;
            };
            for ($date = $from_date; $date->compare($to_date) <= 0; $date = $date->addDays(1)) {
                $skeleton_service = new ServiceEntry(
                    $entry->uid
                    , $entry->period
                    , BankHoliday::from($get_value_or_self($entry->excludeBankHoliday))
                    , ShortTermPlanning::from($get_value_or_self($entry->shortTermPlanning))
                );
                if ($skeleton_service->runsOnDate($date)) {
                    $possibilities[] = new DatedService($skeleton_service, $date);
                }
            }
        }

        // filter out duplicate possibilities
        $possibilities_count = count($possibilities);
        foreach ($possibilities as $i => $possibility) {
            for ($j = $i + 1; $j < $possibilities_count; ++$j) {
                if (
                    $possibilities[$j] !== null
                    && $possibility->service->uid === $possibilities[$j]->service->uid
                    && $possibility->date->compare($possibilities[$j]->date) === 0
                ) {
                    $possibilities[$j] = null;
                }
            }
        }
        /** @var DatedService[] $possibilities */
        $possibilities = array_values(array_filter($possibilities));

        if ($possibilities === []) {
            return new DepartureBoard($crs, $from, $to, $time_type, []);
        }
        $real_services = $this->servicesCollection->find(
            [
                '$and' => [
                    [
                        '$or' => array_map(
                            static fn(DatedService $dated_service) =>
                                [
                                    'uid' => $dated_service->service->uid,
                                    'period.from' => ['$lte' => $dated_service->date],
                                    'period.to' => ['$gte' => $dated_service->date],
                                ]
                            , $possibilities
                        ),
                    ],
                    $this->getShortTermPlanningPredicate(),
                ]
            ]
        )->toArray();

        // replace skeleton services with real services - handle STP here
        foreach ($possibilities as &$possibility) {
            $result = null;
            foreach ($real_services as $service) {
                if (
                    $service->uid === $possibility->service->uid
                    && $service->runsOnDate($possibility->date)
                    && $service->isSuperior($result, $this->permanentOnly)
                ) {
                    $result = $service;
                }
            }
            assert($result !== null);
            $possibility = new DatedService($result, $possibility->date);
        }
        unset($possibility);

        // index possibilities with their UID and date
        $possibilities = array_combine(
            array_map(static fn(DatedService $dated_service) => $dated_service->getId(), $possibilities)
            , $possibilities
        );

        $results = array_merge(
            ...array_values(
                array_map(
                    static fn(DatedService $possibility) =>
                        $possibility->service instanceof Service
                            ? $possibility->getCalls($time_type, $crs, $from, $to)
                            : []
                    , $possibilities
                )
            )
        );
        /** @var ServiceCallWithDestinationAndCalls[] $results */
        $results = $this->sortCallResults($results);
        $dated_services = $this->getFullServices(array_map(
            static fn ($result) => $possibilities[$result->uid . '_' . $result->date],
            $results
        ));
        foreach ($results as $i => &$result) {
            $dated_service = $dated_services[$i];
            $full_results = $dated_service->getCalls($time_type, $crs, $from, $to, true);
            foreach ($full_results as $full_result) {
                if ($result->timestamp == $full_result->timestamp) {
                    $result = $full_result;
                }
            }
        }
        unset($result);
        $board = new DepartureBoard($crs, $from, $to, $time_type, $results);
        $this->cache?->set($cache_key, $board);
        return $board;
    }

    /**
     * @param DatedService[] $dated_services
     * @param bool $include_non_passenger
     * @return DatedAssociation[][]
     */
    public function getAssociationsForMultipleServices(
        array $dated_services
        , bool $include_non_passenger
    ) : array {
        $entries = $this->getAssociationEntriesForMultipleServices($dated_services);

        /** @var array{0: Association, 1: array{0: string, 1: Date}, 2: array{0: string, 1: Date}}[][]  $associated_services */
        $applicable_entries = array_map(
            fn($dated_service) => $this->processAssociationEntries($dated_service, $entries, $include_non_passenger)
            , $dated_services
        );

        $associated_services = $this->getServices(
            array_filter(
                array_merge(
                    ...array_map(
                        static fn($item) => [$item[1], $item[2]]
                        , array_merge(...$applicable_entries)
                    )
                )
            )
        );

        /** @var string|int $key */
        return array_map(
            fn($dated_service, $key) => array_map(
                fn($item) => $this->getDatedAssociation($item, $dated_service, $associated_services)
                , $applicable_entries[$key]
            )
            , $dated_services
            , array_keys($dated_services)
        );

    }

    public function getGeneratedDate(): ?Date {
        return get_generated($this->database);
    }

    public function setGeneratedDate(?Date $date) : void {
        set_generated($this->database, $date);
    }

    private function getShortTermPlanningPredicate() : array {
        return $this->permanentOnly 
            ? [
                '$or' => [
                    ['shortTermPlanning.value' => ShortTermPlanning::PERMANENT->value],
                    ['shortTermPlanning' => ShortTermPlanning::PERMANENT->value]
                ]
            ] 
            : ['$expr' => ['$eq' => [0, 0]]];
    }

    private readonly Collection $servicesCollection;
    private readonly Collection $associationsCollection;

    private function getAssociationPredicate(string $uid, Date $date) : array {
        return [
            '$or' => [['primaryUid' => $uid], ['secondaryUid' => $uid]],
            'period.from' => ['$lte' => $date->addDays(1)],
            'period.to' => ['$gte' => $date->addDays(-1)],
        ];
    }

    private function getServicePredicate(string $uid, Date $date) : array {
        return [
            '$and' => [
                [
                    'uid' => $uid,
                    'period.from' => ['$lte' => $date],
                    'period.to' => ['$gte' => $date],
                ],
                $this->getShortTermPlanningPredicate(),
            ]
        ];
    }
}
