<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\DomainModels\AssociationWithService;
use Miklcct\RailOpenTimetableData\DomainModels\DepartureBoard;
use Miklcct\RailOpenTimetableData\DomainModels\Service;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ScheduleEntry;

interface ServiceRepositoryInterface {
    /**
     * @param ScheduleEntry[] $schedules
     * @return void
     */
    public function insertSchedules(array $schedules) : void;

    /**
     * @param AssociationEntry[] $associations
     * @return void
     */
    public function insertAssociations(array $associations) : void;
    
    public function getService(string $uid, Date $date) : ?Service;

    /**
     * @param string $rsid
     * @param Date $date
     * @return Service[]
     */
    public function getServiceByRsid(string $rsid, Date $date) : ?Service;

    /**
     * Get all services which calls / passes the station
     **
     * @return DepartureBoard
     */
    public function getDepartureBoard(
        Location $location
        , DateTimeImmutable $from
        , DateTimeImmutable $to
        , TimeType $time_type
        , ?array $toc = null
    ) : DepartureBoard;

    /**
     * Make a repository which only contains the permanent schedules of this repository
     * 
     * @return static
     */
    public function makePermanentRepository() : static;
}