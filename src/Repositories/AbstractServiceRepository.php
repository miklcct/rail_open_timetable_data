<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use InvalidArgumentException;
use Miklcct\RailOpenTimetableData\Enums\AssociationCategory;
use Miklcct\RailOpenTimetableData\Enums\AssociationDay;
use Miklcct\RailOpenTimetableData\Enums\AssociationType;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\DatedAssociation;
use Miklcct\RailOpenTimetableData\Models\DatedService;
use Miklcct\RailOpenTimetableData\Models\FullService;
use Miklcct\RailOpenTimetableData\Models\Points\CallingPoint;
use Miklcct\RailOpenTimetableData\Models\Service;
use Miklcct\RailOpenTimetableData\Models\ServiceCall;
use function count;

abstract class AbstractServiceRepository implements ServiceRepositoryInterface {
    public function __construct(protected readonly bool $permanentOnly = false) {}

    /**
     * @return AssociationEntry[]
     */
    abstract protected function getAssociationEntries(string $uid, Date $date) : array;

    public function getFullService(
        DatedService $dated_service
        , bool $include_non_passenger = false
        , array $recursed_services = []
    ) : FullService {
        $service = $dated_service->service;
        if (!$service instanceof Service) {
            throw new InvalidArgumentException("It's not possible to make a full service if it's not a service.");
        }
        $stub = new FullService($service, $dated_service->date, null, [], null);
        $dated_associations = $this->getAssociations(
            $dated_service
            , $include_non_passenger
        );
        $divide_from = array_filter(
            $dated_associations
            , static function (DatedAssociation $dated_association) use ($service) {
                $primary_service = $dated_association->primaryService->service;
                return $service->uid === $dated_association->association->secondaryUid
                    && $dated_association->association instanceof Association
                    && $dated_association->association->category === AssociationCategory::DIVIDE
                    // the following lines are to prevent some ScotRail services "dividing" at its terminus
                    && $primary_service instanceof Service
                    && $primary_service->getAssociationPoint($dated_association->association) instanceof CallingPoint
                    // the following lines are to prevent divided trains not starting from dividing point
                    // https://www.railforums.co.uk/threads/divided-portion-doesnt-start-from-divide-point-what-does-it-mean.231126/
                    && $service->getOrigin()->location->tiploc === $dated_association->association->location->tiploc
                    && $service->getOrigin()->locationSuffix === $dated_association->association->secondarySuffix;
            }
        )[0] ?? null;
        $join_to = array_filter(
            $dated_associations
            , static function (DatedAssociation $dated_association) use ($service, $dated_service) {
                $primary_service = $dated_association->primaryService->service;
                return $dated_service->service->uid === $dated_association->association->secondaryUid
                    && $dated_association->association instanceof Association
                    && $dated_association->association->category === AssociationCategory::JOIN
                    // the following lines are to prevent some ScotRail services "joining" at its terminus
                    && $primary_service instanceof Service
                    && $primary_service->getAssociationPoint($dated_association->association) instanceof CallingPoint
                    // the following lines are to prevent joining trains not ending at joining point
                    // https://www.railforums.co.uk/threads/divided-portion-doesnt-start-from-divide-point-what-does-it-mean.231126/
                    && $service->getDestination()->location->tiploc === $dated_association->association->location->tiploc
                    && $service->getDestination()->locationSuffix === $dated_association->association->secondarySuffix;
            }
        )[0] ?? null;
        $divides_and_joins = array_filter(
            $dated_associations
            , static function (DatedAssociation $dated_association) use ($service) {
                $secondary_service = $dated_association->secondaryService->service;
                return $service->uid === $dated_association->association->primaryUid
                    && $dated_association->association instanceof Association
                    // the following lines are to prevent some ScotRail services "dividing" or "joining" at its terminus
                    && $service->getAssociationPoint($dated_association->association) instanceof CallingPoint
                    // the following lines are to prevent divided / joining trains not starting / ending from the associated point
                    // https://www.railforums.co.uk/threads/divided-portion-doesnt-start-from-divide-point-what-does-it-mean.231126/
                    && $secondary_service instanceof Service
                    && ($secondary_expected_location = match ($dated_association->association->category) {
                        AssociationCategory::NEXT => null,
                        AssociationCategory::DIVIDE => $secondary_service->getOrigin(),
                        AssociationCategory::JOIN => $secondary_service->getDestination(),
                    })
                    && $secondary_expected_location->location->tiploc === $dated_association->association->location->tiploc
                    && $secondary_expected_location->locationSuffix === $dated_association->association->secondarySuffix;
            }
        );

        /** @var array<DatedAssociation|null> $dated_associations */
        $dated_associations = [$divide_from, ...$divides_and_joins, $join_to];

        $recursed_services[] = $stub;
        foreach ($dated_associations as &$dated_association) {
            if ($dated_association !== null) {
                /** @var DatedService[] $services */
                $services = [$dated_association->primaryService, $dated_association->secondaryService];
                foreach ($services as &$service) {
                    $recursed = array_values(
                        array_filter(
                            $recursed_services
                            , static fn(DatedService $previous) =>
                                $service->service->uid === $previous->service->uid
                                && $service->date->toDateTimeImmutable()
                                    == $previous->date->toDateTimeImmutable()
                        )
                    )[0] ?? null;
                    $service = $recursed ?? $this->getFullService(
                        $service
                        , $include_non_passenger
                        , $recursed_services
                    );
                }
                unset($service);
                $dated_association = new DatedAssociation(
                    $dated_association->association
                    , $services[0]
                    , $services[1]
                );
            }
        }
        unset($dated_association);

        $stub->divideFrom = $dated_associations[0];
        $stub->dividesJoinsEnRoute = array_slice($dated_associations, 1, count($dated_associations) - 2);
        $stub->joinTo = $dated_associations[count($dated_associations) - 1];
        return $stub;
    }

    public function getAssociations(
        DatedService $dated_service
        , bool $include_non_passenger = false
    ) : array {
        $service = $dated_service->service;
        $departure_date = $dated_service->date;
        $results = [];
        $uid = $service->uid;
        $association_entries = $this->getAssociationEntries($uid, $departure_date);

        // process overlay
        $overlaid_associations = [-1 => [], 0 => [], 1 => []];
        foreach ($overlaid_associations as $date_offset => &$associations) {
            foreach ($association_entries as $association) {
                $association_date = $departure_date->addDays($date_offset);
                if ($association->period->isActive($association_date)) {
                    $found = false;
                    foreach ($associations as &$existing) {
                        if ($association->isSame($existing)) {
                            if ($association->isSuperior($existing, $this->permanentOnly)) {
                                $existing = $association;
                            }
                            $found = true;
                        }
                    }
                    unset($existing);
                    if (!$found && (!$this->permanentOnly || $association->shortTermPlanning === ShortTermPlanning::PERMANENT)) {
                        $associations[] = $association;
                    }
                }
            }
        }
        unset($associations);

        foreach ($overlaid_associations as $date_offset => $associations) {
            foreach ($associations as $association) {
                if (
                    $association instanceof Association
                    && ($include_non_passenger || $association->type === AssociationType::PASSENGER)
                ) {
                    $correct_date = $date_offset === (
                        $association->secondaryUid === $uid
                            ? match ($association->day) {
                                AssociationDay::YESTERDAY => 1,
                                AssociationDay::TODAY => 0,
                                AssociationDay::TOMORROW => -1,
                            }
                            : 0
                    );
                    if ($correct_date) {
                        $primary_departure_date = $departure_date->addDays($date_offset);
                        if ($association->secondaryUid === $uid) {
                            $primary_service = $this->getService(
                                $association->primaryUid
                                , $primary_departure_date
                            );
                            $secondary_service = $dated_service;
                        } else {
                            $primary_service = $dated_service;
                            $secondary_departure_date = match ($association->day) {
                                AssociationDay::YESTERDAY => $departure_date->addDays(-1),
                                AssociationDay::TODAY => $departure_date,
                                AssociationDay::TOMORROW => $departure_date->addDays(1),
                            };
                            $secondary_service = $this->getService(
                                $association->secondaryUid
                                , $secondary_departure_date
                            );
                        }
                        $results[] = new DatedAssociation(
                            $association
                            , $primary_service
                            , $secondary_service
                        );
                    }
                }
            }
        }

        return $results;
    }

    /** @var ServiceCall[] $results */
    protected function sortCallResults(array $results) : array {
        usort(
            $results
            , static fn(ServiceCall $a, ServiceCall $b) => $a->timestamp <=> $b->timestamp
        );
        return $results;
    }

    protected function findServicesInUidMatchingRsid(array $uids, string $rsid, Date $date) : array {
        $results = [];
        foreach ($uids as $uid) {
            $dated_service = $this->getService($uid, $date);
            $service = $dated_service?->service;
            if ($service instanceof Service && $service->hasRsid($rsid)) {
                $results[] = $dated_service;
            }
        }

        return $results;
    }
}