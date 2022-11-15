<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;

interface LocationWithCrs {
    public function getCrsCode() : string;

    public function promoteToStation(LocationRepositoryInterface $location_repository) : ?Station;
}