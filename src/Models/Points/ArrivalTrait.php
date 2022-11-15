<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Enums\Activity;
use Miklcct\RailOpenTimetableData\Models\Time;

trait ArrivalTrait {
    public readonly Time $workingArrival;
    public readonly ?Time $publicArrival;

    public function getWorkingArrival() : Time {
        return $this->workingArrival;
    }

    public function getPublicArrival() : ?Time {
        return in_array(Activity::UNADVERTISED, $this->activities, true) ? null : $this->publicArrival;
    }

    public function getPublicOrWorkingArrival() : Time {
        return $this->getPublicArrival() ?? $this->getWorkingArrival();
    }
}