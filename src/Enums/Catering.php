<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum Catering : string {
    case BUFFET = 'C';
    case FIRST_CLASS_RESTAURANT = 'F';
    case HOT_FOOD = 'H';
    case FIRST_CLASS_MEAL = 'M';
    case WHEELCHAIR_ONLY = 'P';
    case RESTAURANT = 'R';
    case TROLLEY = 'T';
}