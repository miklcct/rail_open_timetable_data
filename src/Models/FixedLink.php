<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateInterval;
use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use MongoDB\BSON\Persistable;

class FixedLink implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly string $mode
        , public readonly Station $origin
        , public readonly Station $destination
        , public readonly int $transferTime
        , public readonly Time $startTime
        , public readonly Time $endTime
        , public readonly int $priority
        , public readonly ?Date $startDate
        , public readonly ?Date $endDate
        , ?array $weekdays
    ) {
        $this->weekdays = $weekdays;
    }

    /**
     * Get the fixed link arrival time given a departure time
     * 
     * @param DateTimeImmutable $departure
     * @param bool $reverse If true get the departure time from the arrival time instead
     */
    public function getArrivalTime(DateTimeImmutable $departure, bool $reverse = false) : ?DateTimeImmutable {
        $transfer_interval = new DateInterval(sprintf('PT%dM', $this->transferTime));
        if ($reverse) {
            $departure = $departure->sub($transfer_interval);
        }

        $time = Time::fromDateTimeInterface($departure);
        $date_valid = $this->isActiveOnDate(Date::fromDateTimeInterface($departure));
        $time_valid = $this->isActiveAtTime($time);
        if ($date_valid && $time_valid) {
            return $reverse ? $departure : $departure->add($transfer_interval);
        }
        if ($reverse) {
            if ($date_valid && $time->toHalfMinutes() > $this->startTime->toHalfMinutes()) {
                $next_time = $departure->setTime($this->endTime->hours, $this->endTime->minutes);
            } elseif ($this->startDate !== null && $departure < $this->startDate->toDateTimeImmutable()) {
                $next_time = null;
            } else {
                $next_time = $departure->sub(new DateInterval('P1D'))->setTime(23, 59);
            }
            if ($next_time !== null && $departure->getTimestamp() - $next_time->getTimestamp() < 60 * 60 * 6) {
                return $this->getArrivalTime($next_time->add($transfer_interval), true);
            }
            return null;
        }

        if ($date_valid && $time->toHalfMinutes() < $this->startTime->toHalfMinutes()) {
            $next_time = $departure->setTime($this->startTime->hours, $this->startTime->minutes);
        } elseif ($this->endDate !== null && $departure > $this->endDate->toDateTimeImmutable(new Time(23, 59, true))) {
            $next_time = null;
        } else {
            $next_time = $departure->add(new DateInterval('P1D'))->setTime(0, 0);
        }
        if ($next_time !== null && $next_time->getTimestamp() - $departure->getTimestamp() < 60 * 60 * 6) {
            return $this->getArrivalTime($next_time);
        }
        return null;
    }

    public function isActiveOnDate(Date $date) : bool {
        return $this->weekdays[$date->toDateTimeImmutable()->format('w')]
        && (!($this->startDate !== null)
                || $this->startDate->toDateTimeImmutable(new Time(0, 0))
                <= $date->toDateTimeImmutable())
        && (!($this->endDate !== null)
                || $this->endDate->toDateTimeImmutable(new Time(23, 59, true))
                >= $date->toDateTimeImmutable());
    }

    public function isActiveAtTime(Time $time) : bool {
        return $this->startTime->toHalfMinutes() <= $time->toHalfMinutes()
            && $this->endTime->toHalfMinutes() >= $time->toHalfMinutes();
    }

    /** @var bool[] 7 bits specifying if it is active on each of the weekdays */
    #[ElementType('bool')]
    public readonly array $weekdays;
}