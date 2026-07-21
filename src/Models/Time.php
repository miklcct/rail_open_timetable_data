<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateTimeInterface;
use JsonSerializable;
use MongoDB\BSON\Persistable;

readonly class Time implements JsonSerializable, Persistable {
    use BsonSerializeTrait;
    
    public const int SECONDS_PER_MINUTE = 60;
    public const int MINUTES_PER_HOUR = 60;
    public const int HOURS_PER_DAY = 24;
    public const int SECONDS_PER_DAY = self::SECONDS_PER_MINUTE * self::MINUTES_PER_HOUR * self::HOURS_PER_DAY;

    public const int TWENTY_FOUR_HOUR_CLOCK = 0;
    public const int THIRTY_HOUR_CLOCK = 1;
    public const int SHOW_PLUS_DAYS = 2;

    private const int DAY_JUMP_THRESHOLD = 18 * 60 * 60;

    public int $secondsFromOrigin;
    public bool $negative;
    public int $hours;
    public int $minutes;
    public int $seconds;

    public function __construct(
        int $hours,
        int $minutes,
        int $seconds = 0,
        bool $negative = false,
    ) {
        $this->secondsFromOrigin =
            (($hours * self::MINUTES_PER_HOUR + $minutes) * self::SECONDS_PER_MINUTE + $seconds)
            * ($negative ? -1 : 1);
        $this->negative = $this->secondsFromOrigin < 0;
        $this->hours = intdiv(abs($this->secondsFromOrigin), self::SECONDS_PER_MINUTE * self::MINUTES_PER_HOUR);
        $this->minutes = intdiv(abs($this->secondsFromOrigin), self::SECONDS_PER_MINUTE) % self::MINUTES_PER_HOUR;
        $this->seconds = abs($this->secondsFromOrigin) % self::SECONDS_PER_MINUTE;
    }

    public static function fromString(string $time): self {
        if ($time[0] === '-') {
            return new self(0, 0, self::fromString(substr($time, 1))->secondsFromOrigin, true);
        }
        $components = explode(':', $time);
        return new self(...array_map(fn ($value) => (int)$value, $components));
    }

    public static function fromHhmm(
        string $hhmm
        , ?self $last_call = null
    ) : static {
        $result = new static(
            (int)substr($hhmm, 0, 2)
            , (int)substr($hhmm, 2, 2)
            , (($hhmm[4] ?? '') === 'H') * 30
        );
        return $result->applyDayOffset($last_call);
    }

    public function moduloDay(): self {
        $seconds = $this->secondsFromOrigin % self::SECONDS_PER_DAY;
        while ($seconds < 0) {
            $seconds += self::SECONDS_PER_DAY;
        }
        return new self(0, 0, $seconds);
    }

    public static function fromDateTimeInterface(DateTimeInterface $datetime) : static {
        return new static(
            hours: (int)$datetime->format('G')
            , minutes: (int)$datetime->format('i')
            , seconds: (int)$datetime->format('s')
        );
    }

    public function addDay(int $days = 1) : static {
        return new static(
            $this->hours + 24 * $days
            , $this->minutes
            , $this->seconds
        );
    }

    public function toString(int $format = self::TWENTY_FOUR_HOUR_CLOCK) : string {
        return sprintf(
                "%02d:%02d"
                ,
                $format === self::THIRTY_HOUR_CLOCK
                    ? $this->hours
                    : $this->hours % 24
                ,
                $this->minutes
            )
            . ($this->seconds >= 30 ? '½' : '')
            . ($format === self::SHOW_PLUS_DAYS && $this->hours >= 24
                ? '+' . intdiv($this->hours, 24)
                : '');
    }

    public function applyDayOffset(?Time $previous) : static {
        if ($previous !== null) {
            if ($this->secondsFromOrigin - $previous->secondsFromOrigin >= self::DAY_JUMP_THRESHOLD) {
                return $this->addDay(-1);
            }
            if ($previous->secondsFromOrigin - $this->secondsFromOrigin >= self::DAY_JUMP_THRESHOLD) {
                return $this->addDay();
            }
        }
        return $this;
    }

    public function __toString() : string {
        return $this->toString();
    }
    public function jsonSerialize() : string {
        return $this->toString(self::THIRTY_HOUR_CLOCK);
    }
}