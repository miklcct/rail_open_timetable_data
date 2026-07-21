<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

readonly class Tiploc extends Location {
    use BsonSerializeTrait;

    public function __construct(
        string $tiploc
        , ?string $crsCode
        , string $name
        , public ?int $stanox
    ) {
        parent::__construct($tiploc, $crsCode, $name);
    }
}