<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Time;

readonly class DestinationPoint extends TimingPoint implements HasArrival {
    use ArrivalTrait;
    use BsonSerializeTrait;

    public function __construct(
        ?Location $location,
        ?int $locationSuffix,
        ?string $platform,
        public ?string $path,
        Time $workingArrival,
        ?Time $publicArrival,
        array $activities
    ) {
        $this->publicArrival = $publicArrival;
        $this->workingArrival = $workingArrival;
        parent::__construct($location, $locationSuffix, $platform, $activities);
    }
}
