<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Enums\AssociationCategory;
use Miklcct\RailOpenTimetableData\Enums\AssociationDay;
use Miklcct\RailOpenTimetableData\Enums\AssociationType;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;

class Association extends AssociationEntry {
    use BsonSerializeTrait;

    public function __construct(
        string $primaryUid
        , string $secondaryUid
        , string $primarySuffix
        , string $secondarySuffix
        , Period $period
        , Location $location
        , public readonly AssociationCategory $category
        , public readonly AssociationDay $day
        , public readonly AssociationType $type
        , ShortTermPlanning $shortTermPlanning
    ) {
        parent::__construct(
            $primaryUid
            , $secondaryUid
            , $primarySuffix
            , $secondarySuffix
            , $period
            , $location
            , $shortTermPlanning
        );
    }

    public function getAssociationDateFromSecondaryDate(Date $date): Date {
        return match ($this->day) {
            AssociationDay::TOMORROW => $date->addDays(-1),
            AssociationDay::TODAY => $date,
            AssociationDay::YESTERDAY => $date->addDays(1),
        };
    }
    
    public function getSecondaryDateForAssociationDate(Date $date): Date {
        return match ($this->day) {
            AssociationDay::TOMORROW => $date->addDays(1),
            AssociationDay::TODAY => $date,
            AssociationDay::YESTERDAY => $date->addDays(-1),
        };
    }
    
    public function isActive(Date $date, bool $secondary = false) : bool {
        return $this->period->isActive($secondary ? $this->getAssociationDateFromSecondaryDate($date) : $date);
    }
}