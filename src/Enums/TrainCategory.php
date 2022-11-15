<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

// Currently, only scheduled passenger trains in National Rail system
// are listed here.

enum TrainCategory : string {
    case NONE = '';
    case METRO = 'OL';
    case ORDINARY = 'OO';
    case CHANNEL_TUNNEL = 'XC';
    case EXPRESS = 'XX';
    case SLEEPER = 'XZ';
    case REPLACEMENT_BUS = 'BR';
    case BUS = 'BS';
    case SHIP = 'SS';

    public function isPassengerTrain() : bool {
        return true;
    }

    public function getDescription() : string {
        return match ($this) {
            self::NONE => '',
            self::METRO => 'Metro Train',
            self::ORDINARY => 'Local Train',
            self::CHANNEL_TUNNEL => 'Channel Tunnel Train',
            self::EXPRESS => 'Express Train',
            self::SLEEPER => 'Sleeper',
            self::REPLACEMENT_BUS => 'Rail Replacement Bus',
            self::BUS => 'Service Bus',
            self::SHIP => 'Ship',
        };
    }
}
