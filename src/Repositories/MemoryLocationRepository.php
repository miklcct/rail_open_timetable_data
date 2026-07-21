<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\NationalRailTimetable\Exceptions\AmbiguousStation;
use Miklcct\RailOpenTimetableData\Models\Location;
use function is_string;

class MemoryLocationRepository implements LocationRepositoryInterface {
    public function getLocation(string $text) : ?Location {
        return $this->getLocationByCrs($text) ?? $this->getLocationByTiploc($text) ?? $this->getLocationByName($text);
    }

    public function getLocationByCrs(string $crs) : ?Location {
        return $this->locationsByCrs[strtoupper($crs)] ?? null;
    }

    public function getLocationByName(string $name) : ?Location {
        $name = strtoupper($name);
        $result = $this->locationsByName[$name] ?? null;
        if (is_string($result)) {
            return $this->getLocationByName($result);
        }
        if ($result === null) {
            $full_names = $this->fullNamesByShortName[$name] ?? [];
            if (count($full_names) > 1) {
                throw new AmbiguousStation($name, $full_names);
            }
            if (count($full_names) === 1) {
                return $this->getLocationByName(array_first($full_names));
            }
        }
        return $result;
    }

    public function getLocationByTiploc(string $tiploc) : ?Location {
        return $this->locationsByTiploc[strtoupper($tiploc)] ?? null;
    }

    public function insertLocations(array $locations) : void {
        /** @var Location $station */
        foreach ($locations as $station) {
            if (isset($station->crsCode)) {
                $this->updateStation(
                    $this->locationsByCrs
                    , $station->crsCode
                    , $station
                );
            }
            if (isset($station->minorCrsCode)) {
                $this->updateStation(
                    $this->locationsByCrs
                    , $station->minorCrsCode
                    , $station
                );
            }
            $this->updateStation(
                $this->locationsByName
                , $station->name
                , $station
            );
            $this->updateStation(
                $this->locationsByTiploc
                , $station->tiploc
                , $station
            );
            $full_names =& $this->fullNamesByShortName[$station->getShortName()];
            if ($full_names === null) {
                $full_names = [];
            }
            if (!in_array($station->name, $full_names)) {
                $full_names[] = $station->name;
            }
            unset($full_names);
        }
    }

    public function insertAliases(array $aliases) : void {
        $this->locationsByName += $aliases;
    }

    public function getAllStations() : array {
        return $this->locationsByCrs;
    }

    /** @var array<string, Location> */
    private array $locationsByCrs = [];
    /** @var array<string, string|Location> */
    private array $locationsByName = [];
    /** @var array<string, Location> */
    private array $locationsByTiploc = [];
    /** @var array<string, string[]> */
    private array $fullNamesByShortName = [];

    private function updateStation(
        array &$bucket
        , string $key
        , Location $station
    ) : void {
        $existing = $bucket[$key] ?? null;
        if ($station->isSuperior($existing)) {
            $bucket[$key] = $station;
        }
    }
}