<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\LocationWithCrs;
use Miklcct\RailOpenTimetableData\Models\Station;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Cursor;
use stdClass;
use function array_keys;
use function array_values;

class MongodbLocationRepository implements LocationRepositoryInterface {
    public function __construct(Database $database) {
        $this->collection = $database->selectCollection('locations');
    }

    public function getLocationByCrs(string $crs) : ?LocationWithCrs {
        $crs = strtoupper($crs);
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->crsCache[$crs] ??= $this->processResult($this->collection->find(['$or' => [['crsCode' => $crs], ['minorCrsCode' => $crs]]]));
    }

    public function getLocationByName(string $name) : ?Location {
        $name = strtoupper($name);
        return $this->nameCache[$name] ??= $this->processResult($this->collection->find(['name' => $name]));
    }

    public function getLocationByTiploc(string $tiploc) : ?Location {
        $tiploc = strtoupper($tiploc);
        return $this->tiplocCache[$tiploc] ??= $this->processResult($this->collection->find(['tiploc' => $tiploc]));
    }

    public function insertLocations(array $locations) : void {
        if ($locations !== []) {
            $this->collection->insertMany($locations);
        }
        $this->clearCache();
    }

    public function insertAliases(array $aliases) : void {
        if ($aliases !== []) {
            $this->collection->insertMany(
                array_map(
                    static function (string $key, string $value) : array {
                        return ['name' => $key, 'alias' => $value];
                    }
                    , array_keys($aliases)
                    , array_values($aliases)
                )
            );
        }
    }

    public function addIndexes() : void {
        $this->collection->createIndexes(
            [
                ['key' => ['tiploc' => 1]],
                ['key' => ['crsCode' => 1]],
                ['key' => ['name' => 'text']],
            ]
        );
    }

    public function clearCache() : void {
        $this->crsCache = [];
        $this->nameCache = [];
        $this->tiplocCache = [];
    }

    public function getAllStations(): array {
        $this->crsCache = [];
        foreach ($this->collection->find(['crsCode' => ['$ne' => null]]) as $result) {
            if ($result instanceof LocationWithCrs) {
                $crs = $result->getCrsCode();
                if (!isset($this->crsCache[$crs]) || $result->isSuperior($this->crsCache[$crs])) {
                    $this->crsCache[$crs] = $result;
                }
                if ($result instanceof Station && $result->minorCrsCode !== null) {
                    $this->crsCache[$result->minorCrsCode] = $result;
                }
            }
        }
        return $this->crsCache;
    }

    private function processResult(Cursor $cursor) : ?Location {
        $result = null;
        foreach ($cursor as $item) {
            if ($item instanceof stdClass && isset($item->alias)) {
                /** @noinspection NullPointerExceptionInspection */
                $item = $this->getLocationByName($item->alias);
            }
            if ($item instanceof Location && $item->isSuperior($result)) {
                $result = $item;
            }
        }
        return $result;
    }

    private readonly Collection $collection;
    private array $crsCache = [];
    private array $nameCache = [];
    private array $tiplocCache = [];
}