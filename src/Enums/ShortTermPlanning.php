<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum ShortTermPlanning : string {
    case PERMANENT = 'P';
    case NEW = 'N';
    case OVERLAY = 'O';
    case CANCEL = 'C';

    public function getDescription() : string {
        return match ($this) {
            self::PERMANENT => 'Base schedule',
            self::NEW => 'STP schedule',
            self::OVERLAY => 'Overlay schedule',
            self::CANCEL => 'STP cancellation',
        };
    }
}