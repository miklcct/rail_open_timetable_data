<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use MongoDB\BSON\Persistable;

class TocInterchange implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly string $arrivingToc
        , public readonly string $departingToc
        , public readonly int $connectionTime
    ) {}
}