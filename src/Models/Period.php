<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use MongoDB\BSON\Persistable;

class Period implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly Date $from
        , public readonly Date $to
        , array $weekdays
    ) {
        $this->weekdays = $weekdays;
    }

    /** @var bool[] 7 bits specifying if it is active on each of the weekdays */
    #[ElementType('bool')]
    public readonly array $weekdays;

    public function isActive(Date $date) : bool {
        return $date->compare($this->from) >= 0
            && $date->compare($this->to) <= 0
            && $this->weekdays[$date->getWeekday()];
    }
}