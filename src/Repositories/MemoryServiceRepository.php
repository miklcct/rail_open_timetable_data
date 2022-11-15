<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\DatedService;
use Miklcct\RailOpenTimetableData\Models\DepartureBoard;
use Miklcct\RailOpenTimetableData\Models\Service;
use Miklcct\RailOpenTimetableData\Models\ServiceCallWithDestination;
use Miklcct\RailOpenTimetableData\Models\ServiceEntry;
use function array_filter;
use function array_keys;
use function array_values;

class MemoryServiceRepository extends AbstractServiceRepository {
    public function insertServices(array $services) : void {
        foreach ($services as $service) {
            $this->services[$service->uid][] = $service;
        }
    }

    public function insertAssociations(array $associations) : void {
        foreach ($associations as $association) {
            $this->associations[$association->primaryUid][] = $association;
            $this->associations[$association->secondaryUid][] = $association;
        }
    }

    protected function getAssociationEntries(string $uid, Date $date) : array {
        return array_values(
            array_filter(
                $this->associations[$uid] ?? []
                , static fn(AssociationEntry $association) =>
                    $association->period->from->compare($date->addDays(1)) <= 0
                    && $association->period->to->compare($date->addDays(-1)) >= 0
            )
        );
    }

    /** @var array<string, ServiceEntry[]> */
    private array $services = [];

    /** @var array<string, AssociationEntry[]> */
    private array $associations = [];

    public function getService(string $uid, Date $date) : ?DatedService {
        $result = null;
        foreach ($this->services[$uid] ?? [] as $service) {
            if ($service->runsOnDate($date) && $service->isSuperior($result, $this->permanentOnly)) {
                $result = $service;
            }
        }
        return $result === null ? null : new DatedService($result, $date);
    }

    public function getDepartureBoard(
        string $crs,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        TimeType $time_type
    ) : DepartureBoard {
        $results = [];
        foreach (array_keys($this->services) as $uid) {
            $from_date = Date::fromDateTimeInterface($from)->addDays(-1);
            $to_date = Date::fromDateTimeInterface($to);
            for ($date = $from_date; $date->compare($to_date) <= 0; $date = $date->addDays(1)) {
                $dated_service = $this->getService($uid, $date);
                if ($dated_service?->service instanceof Service) {
                    /** @noinspection NullPointerExceptionInspection it's not possible to be null due to if condition */
                    $full_service = $this->getFullService($dated_service);
                    $results[] = array_values(
                        array_filter(
                            $full_service->getCalls($time_type, $crs, $from, $to, true)
                            // prevent repeated calls from different portion
                            , static fn(ServiceCallWithDestination $result) =>
                                $result->uid === $full_service->service->uid
                        )
                    );
                }
            }
        }
        /** @noinspection PhpParamsInspection */
        return new DepartureBoard(
            $crs
            , $from
            , $to
            , $time_type
            , $this->sortCallResults(array_merge(...$results))
        );
    }

    public function getServiceByRsid(string $rsid, Date $date) : array {
        return $this->findServicesInUidMatchingRsid(array_keys($this->services), $rsid, $date);
    }

    public function getGeneratedDate(): ?Date {
        return $this->date;
    }

    public function setGeneratedDate(?Date $date) : void {
        $this->date = $date;
    }

    private ?Date $date = null;
}