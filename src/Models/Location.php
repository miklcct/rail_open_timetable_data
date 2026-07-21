<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;
use MongoDB\BSON\Persistable;
use function is_string;
use function Safe\preg_replace;

readonly abstract class Location implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public string $tiploc
        , public ?string $crsCode
        , public string $name
    ) {}

    public function isSuperior(Location|string|null $existing) : bool {
        return !is_string($existing) && (
            $existing === null || $this->superiorScore() > $existing->superiorScore()
        );
    }
    
    public function getCrsOrTiplocCode() : string {
        return $this->crsCode ?? $this->tiploc;
    }

    public function getShortName() : string {
        if (str_contains($this->name, 'MAESTEG')) {
            return $this->name;
        }
        return preg_replace('/ \(.*\)$/', '', $this->name);
    }

    private function superiorScore() : int {
        return $this instanceof Station
            ? $this->interchange !== 9 ? 3 : ($this->minorCrsCode === $this->crsCode ? 2 : 1)
            : 0;
    }

    public function getCoordinates() : ?array {
        $tiplocData = self::getTiplocData();
        $result = $tiplocData[$this->tiploc] ?? null;
        if (isset($result['easting'], $result['northing'])) {
            return [$result['easting'], $result['northing']];
        }
        return null;
    }

    private static function getTiplocData() : array {
        static $tiplocData;
        if ($tiplocData === null) {
            $csv_path = __DIR__ . '/../../resource/tiplocs-merged.csv';
            $handle = fopen($csv_path, 'r');
            $keys = fgetcsv($handle, 0);
            $tiplocData = [];
            while (($row = fgetcsv($handle, 0)) !== false) {
                $combined = array_combine($keys, $row);
                foreach (
                    ["stop_lon" => "float", "stop_lat" => "float", "easting" => "int", "northing" => "int"] as $key
                => $type
                ) {
                    if (($combined[$key] ?? "") === "") {
                        $combined[$key] = null;
                    } else {
                        settype($combined[$key], $type);
                    }
                }
                $tiplocData[$combined["stop_id"]] = $combined;
            }
            fclose($handle);
        }
        return $tiplocData;
    }
}