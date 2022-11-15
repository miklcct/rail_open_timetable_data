<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

class OriginPoint extends TimingPoint implements HasDeparture {
    use DepartureTrait;
    use BsonSerializeTrait;

    public function __construct(
        Location $location
        , string $locationSuffix
        , string $platform
        , public readonly string $line
        , Time $workingDeparture
        , ?Time $publicDeparture
        , public readonly int $allowanceHalfMinutes
        , array $activity
        , public readonly ServiceProperty $serviceProperty
    ) {
        $this->publicDeparture = $publicDeparture;
        $this->workingDeparture = $workingDeparture;
        parent::__construct($location, $locationSuffix, $platform, $activity);
    }
}