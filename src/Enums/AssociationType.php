<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum AssociationType : string {
    case PASSENGER = 'P';
    case OPERATING = 'O';
}