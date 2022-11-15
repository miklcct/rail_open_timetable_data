<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

class PassingPoint extends IntermediatePoint {
    use BsonSerializeTrait;

    public function __construct(
        Location $location
        , string $locationSuffix
        , string $platform
        , string $path
        , string $line
        , public readonly Time $pass
        , int $allowanceHalfMinutes
        , array $activity
        , ?ServiceProperty $serviceProperty
    ) {
        parent::__construct(
            $location
            , $locationSuffix
            , $platform
            , $path
            , $line
            , $allowanceHalfMinutes
            , $activity
            , $serviceProperty
        );
    }

    /**
     * @return Time
     */
    public function getPass() : Time {
        return $this->pass;
    }
}