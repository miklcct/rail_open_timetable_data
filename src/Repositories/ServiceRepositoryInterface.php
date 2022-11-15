<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\DatedAssociation;
use Miklcct\RailOpenTimetableData\Models\DatedService;
use Miklcct\RailOpenTimetableData\Models\DepartureBoard;
use Miklcct\RailOpenTimetableData\Models\FullService;
use Miklcct\RailOpenTimetableData\Models\ServiceEntry;

interface ServiceRepositoryInterface {
    /**
     * @param ServiceEntry[] $services
     * @return void
     */
    public function insertServices(array $services) : void;

    /**
     * @param AssociationEntry[] $associations
     * @return void
     */
    public function insertAssociations(array $associations) : void;

    public function getService(string $uid, Date $date) : ?DatedService;

    /**
     * Get all UIDs which calls / passes the station
     **
     * @return DepartureBoard
     */
    public function getDepartureBoard(
        string $crs
        , DateTimeImmutable $from
        , DateTimeImmutable $to
        , TimeType $time_type
    ) : DepartureBoard;

    /**
     * Get associations of the specified service
     *
     * If $from is specified, only the following associations happening after
     * it will be returned.
     * - joining another train
     * - dividing to form another train
     * - forming another service at the end
     *
     * If $to is specified, only the following associations happening before it
     * will be returned.
     * - another train joining
     * - dividing from another train
     * - formed from another service at the beginning
     *
     * @param DatedService $dated_service
     * @param bool $include_non_passenger
     * @return DatedAssociation[]
     */
    public function getAssociations(
        DatedService $dated_service
        , bool $include_non_passenger = false
    ) : array;

    public function getFullService(
        DatedService $dated_service
        , bool $include_non_passenger = false
        , array $recursed_services = []
    ) : FullService;

    /**
     * @param string $rsid
     * @param Date $date
     * @return DatedService[]
     */
    public function getServiceByRsid(string $rsid, Date $date) : array;

    public function getGeneratedDate() : ?Date;

    public function setGeneratedDate(?Date $date);
}