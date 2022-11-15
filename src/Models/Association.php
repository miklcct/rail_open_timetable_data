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
}