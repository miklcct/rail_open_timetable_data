<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;

abstract class IntermediatePoint extends TimingPoint {
    use BsonSerializeTrait;

    public function __construct(
        Location $location
        , string $locationSuffix
        , string $platform
        , public readonly string $path
        , public readonly string $line
        , public readonly int $allowanceHalfMinutes
        , array $activity
        , public readonly ?ServiceProperty $serviceProperty
    ) {
        parent::__construct($location, $locationSuffix, $platform, $activity);
    }
}