<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use MongoDB\BSON\Persistable;

abstract class AssociationEntry implements Persistable {
    use BsonSerializeTrait;
    use OverlayTrait;

    public function __construct(
        public readonly string $primaryUid
        , public readonly string $secondaryUid
        , public readonly string $primarySuffix
        , public readonly string $secondarySuffix
        , public readonly Period $period
        , public readonly Location $location
        , public readonly ShortTermPlanning $shortTermPlanning
    ) {}

    public function isSame(AssociationEntry $other) : bool {
        return $this->primaryUid === $other->primaryUid
            && $this->secondaryUid === $other->secondaryUid
            && $this->primarySuffix === $other->primarySuffix
            && $this->secondarySuffix === $other->secondarySuffix
            && $this->location->tiploc === $other->location->tiploc;
    }
}