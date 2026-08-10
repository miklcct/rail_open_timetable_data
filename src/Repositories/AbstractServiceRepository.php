<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\DomainModels\AssociationWithService;
use Miklcct\RailOpenTimetableData\DomainModels\DepartureBoard;
use Miklcct\RailOpenTimetableData\DomainModels\Service;
use Miklcct\RailOpenTimetableData\Enums\AssociationType;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Points\OriginOrIntermediatePoint;
use Miklcct\RailOpenTimetableData\Models\Schedule;
use Miklcct\RailOpenTimetableData\Models\ScheduleEntry;
use Miklcct\RailOpenTimetableData\Models\ServiceCall;
use RuntimeException;

abstract class AbstractServiceRepository implements ServiceRepositoryInterface {
    abstract protected function getSchedule(string $uid, Date $date) : ?ScheduleEntry;

    /**
     * Get all possible association entries, before processing for overlays, for the given primary portion and date
     * 
     * @param string $uid
     * @param Date $date
     * @return AssociationEntry[]
     */
    abstract protected function getChildAssociationEntries(string $uid, Date $date) : array;

    /**
     * Get all possible association entries, before processing for overlays, for the given secondary portion and date
     *
     * @param string $uid
     * @param Date $date
     * @return AssociationEntry[]
     */
    abstract protected function getParentAssociationEntries(string $uid, Date $date) : array;

    public function getService(string $uid, Date $date, ?string $recursed_from = null) : ?Service {
        $schedule = $this->getSchedule($uid, $date);
        return $schedule instanceof Schedule ? $this->getFullService(Service::fromSchedule($schedule, $date), false, $recursed_from) : null;
    }

    public function getDepartureBoard(
        Location $location,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        TimeType $time_type,
        ?array $tocs = null,
        ?array $signalling_id_prefixes = null
    ) : DepartureBoard {
        if ($signalling_id_prefixes !== null) {
            foreach ($signalling_id_prefixes as &$prefix) {
                $prefix = strtoupper($prefix);
            }
        }
        unset($prefix);
        
        $uids = $this->getUidsAtLocation($location, $time_type, $tocs, $signalling_id_prefixes);
        $from_date = Date::fromDateTimeInterface($from)->addDays(-1);
        $to_date = Date::fromDateTimeInterface($to);
        $services = $this->getServicesBetweenDates($uids, $from_date, $to_date);
        $calls = array_merge(
            ...array_values(
                array_map(
                    fn (Service $service) => array_filter(
                        $service->findCallInSameUid($location)
                        , function (ServiceCall $call) use ($signalling_id_prefixes, $to, $from, $time_type) {
                            $timestamp = $call->getTimestamp($time_type);
                            $timestamp_valid = $timestamp !== null && $timestamp >= $from && $timestamp < $to;
                            if (!$timestamp_valid) {
                                return false;
                            }
                            if ($signalling_id_prefixes === null) {
                                return true;
                            }
                            $preceding_call = $call->service->timingPoints[$call->callIndex + ($time_type->isArrival() ? -1 : 0)];
                            $service_property = $preceding_call instanceof OriginOrIntermediatePoint ? $preceding_call->serviceProperty : null;
                            return $service_property !== null && in_array(substr($service_property->identity, 0, 2), $signalling_id_prefixes);
                        }
                    )
                    , $services
                )
            )
        );
        
        usort($calls, fn(ServiceCall $a, ServiceCall $b) => $a->getTimestamp($time_type) <=> $b->getTimestamp($time_type));
        return new DepartureBoard($time_type, $calls);
    }

    /**
     * Return the list of UIDs which call at the specified location
     * @return string[]
     */
    abstract protected function getUidsAtLocation(Location $location, TimeType $time_type, ?array $tocs = null, ?array $prefixes = null) : array;

    /**
     * @param array{0: string, 1: Date, 2: Service}[] $uid_on_dates
     * @return Service[] an association array indexed with "UID_date"
     */
    protected function getServicesOnDates(array $uid_on_dates) : array {
        $result = [];
        foreach ($uid_on_dates as [$uid, $date, $recursed_from]) {
            $service = $this->getService($uid, $date, $recursed_from);
            if ($service !== null) {
                $result["{$uid}_$date"] = $service;
            }
        }
        return $result;
    }

    /**
     * @param string[] $uids
     * @return Service[] an association array indexed with "UID_date"
     */
    protected function getServicesBetweenDates(array $uids, Date $from_date, Date $to_date) : array {
        $result = [];
        foreach ($uids as $uid) {
            for (
                $date = $from_date; $date->toDateTimeImmutable() <= $to_date->toDateTimeImmutable();
                $date = $date->addDays(1)
            ) {
                $service = $this->getService($uid, $date);
                if ($service !== null) {
                    $result["{$uid}_$date"] = $service;
                }
            }
        }
        return $result;
    }
    
    protected function getFullService(
        Service $service
        , bool $include_non_passenger = false
        , ?string $recursed_from = null
    ) : Service {
        $parent_associations = $this->getParentAssociations($service, $include_non_passenger);
        foreach ($parent_associations as $parent_association) {
            if ($parent_association->primaryUid !== $recursed_from) {
                $result = $this->getService(
                    $parent_association->primaryUid,
                    $parent_association->getAssociationDateFromSecondaryDate($service->date),
                );
                if ($result !== null) {
                    foreach ([$result, ...$result->divides, ...$result->joins] as $portion) {
                        if ($portion->uid === $service->uid) {
                            return $portion;
                        }
                    }
                    // the parent service does not contain a portion of this service
                    // this may happen if the association isn't processed at all,
                    // for example if it happens at the beginning or the end of the service. 
                }
            }
        }
        $associations = $this->getChildAssociations($service, $include_non_passenger);
        $child_uid_on_dates = array_map(
            fn(Association $association) => [
                $association->secondaryUid,
                $association->getSecondaryDateForAssociationDate($service->date),
                $association->primaryUid
            ]
            , $associations
        );
        $children = $this->getServicesOnDates($child_uid_on_dates);
        
        /** @var AssociationWithService[] $association_with_services */
        $association_with_services = [];
        foreach ($associations as $i => $association) {
            $child = $children[implode("_", array_slice($child_uid_on_dates[$i], 0, 2))] ?? null;
            if (isset($child)) {
                $assoc_index = $service->getAssociationIndex($association);
                if ($assoc_index === null) {
                    throw new RuntimeException("Cannot identify the calling point where the association between $association->primaryUid and $association->secondaryUid happens at {$association->location->tiploc}");
                }
                $association_with_services[] = new AssociationWithService($association, $assoc_index, $child);
            }
        }
        return $service->processChildren($association_with_services);
    }

    /**
     * @param Service $service
     * @param bool $include_non_passenger
     * @return Association[]
     */
    protected function getParentAssociations(Service $service, bool $include_non_passenger) : array {
        $association_entries = $this->getParentAssociationEntries($service->uid, $service->date);
        return $this->processAssociationOverlay($association_entries, $service->date, true, $include_non_passenger);
    }

    /**
     * @param Service $service
     * @param bool $include_non_passenger
     * @return Association[]
     */
    protected function getChildAssociations(Service $service, bool $include_non_passenger = false) : array {
        $association_entries = $this->getChildAssociationEntries($service->uid, $service->date);
        return $this->processAssociationOverlay($association_entries, $service->date, false, $include_non_passenger);
    }

    /**
     * @param AssociationEntry[] $association_entries
     * @return Association[]
     */
    private function processAssociationOverlay(
        array $association_entries
        , Date $date
        , bool $secondary
        , bool $include_non_passenger = false
    ) : array {
        // sort the associations such that the permanent ones are first
        usort($association_entries, fn(AssociationEntry $a, AssociationEntry $b) => $b->shortTermPlanning->value <=> $a->shortTermPlanning->value);
        
        /** @var Association[] $result */
        $result = [];
        
        foreach ($association_entries as $association) {
            $found_existing = false;
            foreach ($result as $i => $existing) {
                if ($existing->isSame($association)) {
                    $found_existing = true;
                    if ($existing->isActive($date, $secondary)) {
                        if ($association instanceof Association && ($include_non_passenger || $association->type === AssociationType::PASSENGER)) {
                            $result[$i] = $association;
                        } else {
                            unset($result[$i]);
                        }
                    }
                }
            }
            if (!$found_existing && $association instanceof Association && $association->isActive($date, $secondary) && ($include_non_passenger || $association->type === AssociationType::PASSENGER)) {
                $result[] = $association;
            }
        }
        
        return $result;
    }
}