<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Enums;

use Miklcct\RailOpenTimetableData\Models\Date;

enum BankHoliday : string {
    case NONE = '';
    case ENGLAND = 'X';
    case GLASGOW = 'G';

    public function isActive(Date $date) : bool {
        // field has no effect, ref https://www.railforums.co.uk/threads/bank-holiday-excluded-field-in-timetable-data-no-effect.230878/#post-5633762
        return false;
    }
}