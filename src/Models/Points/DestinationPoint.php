<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Time;

class DestinationPoint extends TimingPoint implements HasArrival {
    use BsonSerializeTrait;
    use ArrivalTrait;

    public function __construct(
        Location $location
        , string $locationSuffix
        , string $platform
        , public readonly string $path
        , Time $workingArrival
        , ?Time $publicArrival
        , array $activity
    ) {
        $this->publicArrival = $publicArrival;
        $this->workingArrival = $workingArrival;
        parent::__construct($location, $locationSuffix, $platform, $activity);
    }
}