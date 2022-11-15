<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateTimeInterface;
use JsonSerializable;
use MongoDB\BSON\Persistable;

class Time implements JsonSerializable, Persistable {
    use BsonSerializeTrait;

    public const TWENTY_FOUR_HOUR_CLOCK = 0;
    public const THIRTY_HOUR_CLOCK = 1;
    public const SHOW_PLUS_DAYS = 2;

    final public function __construct(
        public readonly int $hours
        , public readonly int $minutes
        , public readonly bool $halfMinute = false
    ) {}

    public static function fromHhmm(
        string $hhmm
        , self $last_call = null
    ) : static {
        $result = new static(
            (int)substr($hhmm, 0, 2)
            , (int)substr($hhmm, 2, 2)
            , ($hhmm[4] ?? '') === 'H'
        );
        return $last_call !== null
            && $result->toHalfMinutes() < $last_call->toHalfMinutes() - 120
            ? $result->addDay()
            : $result;
    }

    public static function fromDateTimeInterface(DateTimeInterface $datetime) : static {
        return new static(
            hours: (int)$datetime->format('G')
            , minutes: (int)$datetime->format('i')
            , halfMinute: (int)$datetime->format('s') >= 30
        );
    }

    public function addDay() : static {
        return new static(
            $this->hours + 24
            , $this->minutes
            , $this->halfMinute
        );
    }

    public function toHalfMinutes() : int {
        return ($this->hours * 60 + $this->minutes) * 2 + $this->halfMinute;
    }

    public function toString(int $format = self::TWENTY_FOUR_HOUR_CLOCK)
    : string {
        return sprintf(
            "%02d:%02d"
            , $format === self::THIRTY_HOUR_CLOCK
                ? $this->hours
                : $this->hours % 24
            , $this->minutes
        )
            . ($this->halfMinute ? '½' : '')
            . ($format === self::SHOW_PLUS_DAYS && $this->hours >= 24
                ? '+' . intdiv($this->hours, 24)
                : '');
    }

    public function __toString() : string {
        return $this->toString();
    }

    public function jsonSerialize() : string {
        return $this->toString(self::THIRTY_HOUR_CLOCK);
    }
}