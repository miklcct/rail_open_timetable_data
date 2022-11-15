<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use MongoDB\BSON\Persistable;
use function is_string;
use function Safe\preg_replace;

abstract class Location implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly string $tiploc
        , public readonly string $name
    ) {}

    public function isSuperior(Location|string|null $existing) : bool {
        return !is_string($existing) && (
            $existing === null || $this->superiorScore() > $existing->superiorScore()
        );
    }

    public function getShortName() : string {
        return preg_replace('/ \(.*\)$/', '', $this->name);
    }

    private function superiorScore() : int {
        return $this instanceof Station
            ? $this->interchange !== 9 ? 3 : ($this->minorCrsCode === $this->crsCode ? 2 : 1)
            : 0;
    }
}