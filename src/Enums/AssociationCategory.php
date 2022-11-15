<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum AssociationCategory : string {
    case JOIN = 'JJ';
    case DIVIDE = 'VV';
    case NEXT = 'NP';
}