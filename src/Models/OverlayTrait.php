<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;

trait OverlayTrait {
    public function isSuperior(?self $compare) : bool {
        return $compare === null || $this->shortTermPlanning !== ShortTermPlanning::PERMANENT;
    }
}