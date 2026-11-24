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
                $this->findWithAliases(
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
        return $this->nameCache[$name] ??= $this->processAmbiguousResult($this->findWithAliases(self::getNamePredicate($name)), $name);
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
                ['name' => new Regex('^' . preg_quote("$name (", '/') . '[^)]*\)?\z')]
            ]
        ];
    }

    private static function getCrsPredicate(string $crs) : array {
        return ['$or' => [['crsCode' => $crs], ['minorCrsCode' => $crs]]];
    }


    private function getTiplocPredicate(string $tiploc) : array {
        return ['tiploc' => $tiploc];
    }

    private function findWithAliases(array $filter) : iterable {
        foreach ($this->collection->find(['$and' => [$filter, ['__pclass' => ['$exists' => true]]]]) as $location) {
            yield $location;
        }

        foreach ($this->collection->find(['$and' => [$filter, ['alias' => ['$exists' => true]]]]) as $alias) {
            yield $alias;
        }
    }

    private function processAmbiguousResult(iterable $cursor, string $text) : mixed {
        $exact_match = null;
        $ambiguous_matches = [];
        foreach ($cursor as $item) {
            $content = $item instanceof Location ? $item : $item->alias;
            if ($item->name === $text || $item instanceof Location && ($item->tiploc === $text || $item->crsCode === $text)) {
                $bucket =& $exact_match;
            } else {
                $bucket =& $ambiguous_matches[$item->name];
            }
            if ($this->isLocationSuperior($content, $bucket)) {
                $bucket = $content;
            }
            unset($bucket);
        }

        $result = $exact_match;
        if ($result === null) {
            if (count($ambiguous_matches) > 1) {
                throw new AmbiguousStation(
                    $text,
                    array_map(fn($location) => $location instanceof Location ? $location->name : $location, $ambiguous_matches)
                );
            }
            $result = array_first($ambiguous_matches);
        }
        return is_string($result) 
            ? $this->processAmbiguousResult($this->findWithAliases(['name' => $result]), $result)
            : $result;
    }
    
    private function isLocationSuperior(Location|string $content, Location|string|null $existing) : bool {
        if (is_string($content)) {
            return true;
        }
        if ($content instanceof Location) {
            return $content->isSuperior($existing);
        }
        return false;
    }

    private readonly Collection $collection;
    private array $locationCache = [];
    private array $crsCache = [];
    private array $nameCache = [];
    private array $tiplocCache = [];
}