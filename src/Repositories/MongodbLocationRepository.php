<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\NationalRailTimetable\Exceptions\AmbiguousStation;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Station;
use MongoDB\BSON\Regex;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\CursorInterface;
use function array_keys;
use function array_values;

class MongodbLocationRepository implements LocationRepositoryInterface {
    public function __construct(Database $database) {
        $this->collection = $database->selectCollection('locations');
    }

    public function getLocation(string $text) : ?Location {
        $crs = strtoupper($text);
        return $this->locationCache[$crs] ??= 
            $this->processAmbiguousResult(
                $this->collection->find(
                    ['$or' => [self::getCrsPredicate($text), self::getTiplocPredicate($text), self::getNamePredicate($text)]]
                ), $text
            );
    }

    public function getLocationByCrs(string $crs) : ?Location {
        $crs = strtoupper($crs);
        return $this->crsCache[$crs] ??= $this->processSimpleResult($this->collection->find(
            MongodbLocationRepository::getCrsPredicate($crs)
        ));
    }

    public function getLocationByName(string $name) : ?Location {
        $name = strtoupper($name);
        return $this->nameCache[$name] ??= $this->processAmbiguousResult($this->collection->find(self::getNamePredicate($name)), $name);
    }

    public function getLocationByTiploc(string $tiploc) : ?Location {
        $tiploc = strtoupper($tiploc);
        return $this->tiplocCache[$tiploc] ??= $this->processSimpleResult($this->collection->find($this->getTiplocPredicate($tiploc)));
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
            if ($result instanceof Station) {
                $crs = $result->crsCode;
                if (!isset($this->crsCache[$crs]) || $result->isSuperior($this->crsCache[$crs])) {
                    $this->crsCache[$crs] = $result;
                }
                if ($result->minorCrsCode !== null) {
                    $this->crsCache[$result->minorCrsCode] = $result;
                }
            }
        }
        return $this->crsCache;
    }

    private function processSimpleResult(CursorInterface $cursor) : ?Location {
        $result = null;
        foreach ($cursor as $item) {
            if ($item instanceof Location && $item->isSuperior($result)) {
                $result = $item;
            }
        }
        return $result;
    }

    private static function getNamePredicate(string $name) : array {
        return [
            '$or' => [
                ['name' => $name],
                ['name' => new Regex('^' . preg_quote("$name (", '/'))]
            ]
        ];
    }

    private static function getCrsPredicate(string $crs) : array {
        return ['$or' => [['crsCode' => $crs], ['minorCrsCode' => $crs]]];
    }


    private function getTiplocPredicate(string $tiploc) : array {
        return ['tiploc' => $tiploc];
    }

    private function processAmbiguousResult(CursorInterface $cursor, string $text) : mixed {
        $exact_match = null;
        $ambiguous_matches = [];
        foreach ($cursor as $item) {
            if ($item instanceof Location) {
                if ($item->name === $text || $item->tiploc === $text || $item->crsCode === $text) {
                    $bucket =& $exact_match;
                } else {
                    $bucket =& $ambiguous_matches[$item->name];
                }
                if ($item->isSuperior($bucket)) {
                    $bucket = $item;
                }
                unset($bucket);
            }
        }

        if ($exact_match) {
            return $exact_match;
        }
        if (count($ambiguous_matches) > 1) {
            throw new AmbiguousStation($text, array_map(fn(Location $location) => $location->name, $ambiguous_matches));
        }
        return array_first($ambiguous_matches);
    }

    private readonly Collection $collection;
    private array $locationCache = [];
    private array $crsCache = [];
    private array $nameCache = [];
    private array $tiplocCache = [];
}