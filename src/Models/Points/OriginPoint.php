<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

readonly class OriginPoint extends OriginOrIntermediatePoint implements HasDeparture {
    use DepartureTrait;
    use BsonSerializeTrait;

    public function __construct(
        Location $location,
        ?int $locationSuffix,
        ?string $platform,
        ?string $line,
        Time $workingDeparture,
        ?Time $publicDeparture,
        Time $engineeringAllowance,
        Time $pathingAllowance,
        Time $performanceAllowance,
        array $activities,
        ServiceProperty $serviceProperty
    ) {
        $this->publicDeparture = $publicDeparture;
        $this->workingDeparture = $workingDeparture;
        parent::__construct($location, $locationSuffix, $platform, $line, $engineeringAllowance, $pathingAllowance, $performanceAllowance, $activities, $serviceProperty);
    }
}