<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;

readonly class PassingPoint extends IntermediatePoint {
    use BsonSerializeTrait;

    public function __construct(
        Location $location,
        ?int $locationSuffix,
        ?string $platform,
        ?string $path,
        ?string $line,
        public Time $pass,
        Time $engineeringAllowance,
        Time $pathingAllowance,
        Time $performanceAllowance,
        array $activities,
        ServiceProperty $serviceProperty
    ) {
        parent::__construct(
            $location,
            $locationSuffix,
            $platform,
            $path,
            $line,
            $engineeringAllowance,
            $pathingAllowance,
            $performanceAllowance,
            $activities,
            $serviceProperty
        );
    }

    public function getPass() : Time {
        return $this->pass;
    }
}
