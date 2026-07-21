<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

readonly abstract class OriginOrIntermediatePoint extends TimingPoint {
    use BsonSerializeTrait;

    public function __construct(
        Location $location,
        ?int $locationSuffix,
        ?string $platform,
        public ?string $line,
        public Time $engineeringAllowance,
        public Time $pathingAllowance,
        public Time $performanceAllowance,
        array $activities,
        public ServiceProperty $serviceProperty
    ) {
        parent::__construct($location, $locationSuffix, $platform, $activities);
    }
}
