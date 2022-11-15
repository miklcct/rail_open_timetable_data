<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

class TiplocLocation extends Location {
    use BsonSerializeTrait;

    public function __construct(
        string $tiploc
        , string $name
        , public readonly ?int $stanox
    ) {
        parent::__construct($tiploc, $name);
    }
}