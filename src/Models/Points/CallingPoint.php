<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

class CallingPoint extends IntermediatePoint implements HasDeparture, HasArrival {
    use BsonSerializeTrait;
    use ArrivalTrait;
    use DepartureTrait;

    public function __construct(
        Location $location
        , string $locationSuffix
        , string $platform
        , string $path
        , string $line
        , Time $workingArrival
        , ?Time $publicArrival
        , Time $workingDeparture
        , ?Time $publicDeparture
        , int $allowanceHalfMinutes
        , array $activities
        , ? ServiceProperty $serviceProperty
    ) {
        $this->publicDeparture = $publicDeparture;
        $this->workingDeparture = $workingDeparture;
        $this->publicArrival = $publicArrival;
        $this->workingArrival = $workingArrival;
        parent::__construct(
            $location
            , $locationSuffix
            , $platform
            , $path
            , $line
            , $allowanceHalfMinutes
            , $activities
            , $serviceProperty
        );
    }
}