<?php
declare(strict_types=1);

namespace Miklcct\NationalRailTimetable\Models\Points;

use Miklcct\NationalRailTimetable\Models\Time;

interface HasArrival {
    public function getWorkingArrival() : Time;
    public function getPublicArrival() : ?Time;
    public function getPublicOrWorkingArrival() : Time;
}