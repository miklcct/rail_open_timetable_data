<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\Time;

interface HasDeparture {
    public function getWorkingDeparture() : Time;
    public function getPublicDeparture() : ?Time;
    public function getPublicOrWorkingDeparture() : Time;
}