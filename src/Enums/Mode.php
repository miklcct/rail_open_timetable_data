<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum Mode : string {
    case TRAIN = '';
    case BUS = 'B';
    case SHIP = 'S';
}