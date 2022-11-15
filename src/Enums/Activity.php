<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

// This list is not complete.
// Only activities relevant to passenger operations are listed here.

enum Activity : string {
    case DETACH = '-D';
    case ATTACH_DETACH = '-T';
    case ATTACH = '-U';
    case SET_DOWN = 'D';
    case PASSENGER_CALL = 'T';
    case TRAIN_BEGINS = 'TB';
    case TRAIN_FINISHES = 'TF';
    case REQUEST_STOP = 'R';
    case PICK_UP = 'U';
    case UNADVERTISED = 'N';
    case REVERSE = 'RM';

    public function getDescription() : string {
        return match($this) {
            self::DETACH => 'Detach portion(s)',
            self::ATTACH_DETACH => 'Attach / detach portions(s)',
            self::ATTACH => 'Attach portion(s)',
            self::SET_DOWN => 'Set down only',
            self::REQUEST_STOP => 'Request stop',
            self::PICK_UP => 'Pick up only',
            self::UNADVERTISED => 'Unadvertised stop',
            self::REVERSE => 'Reverse direction',
            default => '',
        };
    }
}