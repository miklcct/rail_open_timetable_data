<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models\Points;

use Miklcct\RailOpenTimetableData\Models\Time;

interface HasArrival {
    public function getWorkingArrival() : Time;
    public function getPublicArrival() : ?Time;
    public function getPublicOrWorkingArrival() : Time;
}