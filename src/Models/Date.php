<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;
use UnexpectedValueException;

class Date implements JsonSerializable, Persistable {
    final public function __construct(
        public readonly int $year
        , public readonly int $month
        , public readonly int $day
    ) {
        $this->validateDate();
    }

    public static function today() : static {
        return static::fromDateTimeInterface(new DateTimeImmutable());
    }

    private function validateDate() : void {
        $days = [
            1 => 31,
            2 => 28,
            3 => 31,
            4 => 30,
            5 => 31,
            6 => 30,
            7 => 31,
            8 => 31,
            9 => 30,
            10 => 31,
            11 => 30,
            12 => 31,
        ];
        $valid = $this->month === 2 && $this->day === 29
            ? $this->isLeapYear()
            : isset($days[$this->month]) && $this->day >= 1 && $this->day <= $days[$this->month];
        if (!$valid) {
            throw new UnexpectedValueException('Date is not valid');
        }
    }

    public function __toString() : string {
        return sprintf("%04d-%02d-%02d", $this->year, $this->month, $this->day);
    }

    public function jsonSerialize() : string {
        return $this->__toString();
    }

    public function getWeekday() : int {
        return (int)$this->toDateTimeImmutable()->format('w');
    }

    public function toDateTimeImmutable(Time $time = null, ?DateTimeZone $timezone = null) : DateTimeImmutable {
        return (new DateTimeImmutable('now', $timezone))
            ->setDate($this->year, $this->month, $this->day)
            ->setTime($time?->hours ?? 0, $time?->minutes ?? 0, $time?->halfMinute ? 30 : 0);
    }

    public static function fromDateTimeInterface(DateTimeInterface $datetime) : static {
        return new static(
            year: (int)$datetime->format('Y')
            , month: (int)$datetime->format('n')
            , day: (int)$datetime->format('j')
        );
    }

    private function isLeapYear() : bool {
        return $this->year % 400 === 0 || $this->year % 4 === 0 && $this->year % 100 !== 0;
    }

    public function addDays(int $days) : static {
        static $utc;
        $utc ??= new DateTimeZone('UTC');
        $interval = new DateInterval(sprintf('P%dD', abs($days)));
        if ($days < 0) {
            $interval->invert = 1;
        }
        return static::fromDateTimeInterface($this->toDateTimeImmutable(null, $utc)->add($interval));
    }

    public function compare(Date $other) : int {
        return $this->year === $other->year
            ? $this->month === $other->month
                ? $this->day <=> $other->day
                : $this->month <=> $other->month
            : $this->year <=> $other->year;
    }

    public function bsonSerialize() : array {
        return ['date' => new UTCDateTime($this->toDateTimeImmutable(null, new DateTimeZone('UTC')))];
    }

    public function bsonUnserialize(array $data) : void {
        /** @var UTCDateTime $date */
        $date = $data['date'];
        $date_time = $date->toDateTime()->setTimezone(new DateTimeZone('UTC'));
        $this->__construct((int)$date_time->format('Y'), (int)$date_time->format('m'), (int)$date_time->format('d'));
    }
}