<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\LocationWithCrs;

interface LocationRepositoryInterface {
    public function getLocationByCrs(string $crs) : ?LocationWithCrs /*?(Location&LocationWithCrs)*/;

    public function getLocationByName(string $name) : ?Location;

    public function getLocationByTiploc(string $tiploc) : ?Location;

    /**
     * @param Location[] $locations
     */
    public function insertLocations(array $locations) : void;

    /**
     * @param array<string, string> $aliases
     */
    public function insertAliases(array $aliases) : void;

    /**
     * @return LocationWithCrs[]
     */
    public function getAllStations() : array;
}