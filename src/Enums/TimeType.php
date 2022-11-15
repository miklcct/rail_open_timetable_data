<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum TimeType : string {
    case WORKING_ARRIVAL = 'working_arrival';
    case PUBLIC_ARRIVAL = 'public_arrival';
    case PASS = 'pass';
    case PUBLIC_DEPARTURE = 'public_departure';
    case WORKING_DEPARTURE = 'working_departure';

    public function isArrival() : bool {
        return $this === self::PUBLIC_ARRIVAL || $this === self::WORKING_ARRIVAL;
    }

    public function isDeparture() : bool {
        return $this === self::PUBLIC_DEPARTURE || $this === self::WORKING_DEPARTURE;
    }
}