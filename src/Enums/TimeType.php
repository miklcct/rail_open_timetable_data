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

    public function isPublic() : bool {
        return $this === self::PUBLIC_ARRIVAL || $this === self::PUBLIC_DEPARTURE;
    }
    
    public function getCompanion() : self {
        return match ($this) {
            self::WORKING_ARRIVAL => self::WORKING_DEPARTURE,
            self::PUBLIC_ARRIVAL => self::PUBLIC_DEPARTURE,
            self::WORKING_DEPARTURE => self::WORKING_ARRIVAL,
            self::PUBLIC_DEPARTURE => self::PUBLIC_ARRIVAL,
            self::PASS => self::PASS,
        };
    }
}