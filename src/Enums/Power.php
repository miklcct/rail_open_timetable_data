<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

enum Power : string {
    case NONE = '';
    case DIESEL = 'D';
    case DEMU = 'DEM';
    case DMU = 'DMU';
    case ELECTRIC = 'E';
    case ELECTRO_DIESEL = 'ED';
    case EMU_LOCOMOTIVE = 'EML';
    case EMU = 'EMU';
    case HST = 'HST';

    public function getDescription() : string {
        return match($this) {
            self::NONE => '',
            self::DIESEL => 'Diesel Locomotive',
            self::DEMU => 'Diesel-Electric Multiple Unit',
            self::DMU => 'Diesel Multiple Unit',
            self::ELECTRIC => 'Electric Locomotive',
            self::ELECTRO_DIESEL => 'Diesel-Electric Locomotive',
            self::EMU_LOCOMOTIVE => 'EMU Locomotive',
            self::EMU => 'Electric Multiple Unit',
            self::HST => 'High Speed Train',
        };
    }
}