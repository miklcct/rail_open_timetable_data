<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use Miklcct\RailOpenTimetableData\Enums\Activity;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\BsonSerializeTrait;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\LocationWithCrs;
use Miklcct\RailOpenTimetableData\Models\Time;
use Miklcct\RailOpenTimetableData\Models\TiplocLocation;
use MongoDB\BSON\Persistable;

abstract class TimingPoint implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly Location $location
        , public readonly string $locationSuffix
        , public readonly string $platform
        , array $activities
    ) {
        $this->activities = $activities;
    }

    public function getTime(TimeType $time_type) : ?Time {
        return match ($time_type) {
            TimeType::WORKING_ARRIVAL => $this instanceof HasArrival ? $this->getWorkingArrival() : null,
            TimeType::PUBLIC_ARRIVAL => $this instanceof HasArrival ? $this->getPublicArrival() : null,
            TimeType::PASS => $this instanceof PassingPoint ? $this->pass : null,
            TimeType::PUBLIC_DEPARTURE => $this instanceof HasDeparture ? $this->getPublicDeparture() : null,
            TimeType::WORKING_DEPARTURE => $this instanceof HasDeparture ? $this->getWorkingDeparture() : null,
        };
    }

    public function isPublicCall() : bool {
        $location = $this->location;
        return
            (
                $this instanceof HasDeparture && $this->getPublicDeparture() !== null
                || $this instanceof HasArrival && $this->getPublicArrival() !== null
            )
            // this filter out non-stations on rail services, but keeps bus stations without CRS
            && (
                $location instanceof LocationWithCrs
                || $location instanceof TiplocLocation && $location->stanox === null
            );
    }

    /** @var Activity[] */
    #[ElementType(Activity::class)]
    public readonly array $activities;
}