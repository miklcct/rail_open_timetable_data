<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;

class TiplocLocationWithCrs extends TiplocLocation implements LocationWithCrs {
    use BsonSerializeTrait;

    public function __construct(
        string $tiploc
        , public readonly string $crsCode
        , string $name
        , ?int $stanox
    ) {
        parent::__construct($tiploc, $name, $stanox);
    }

    public function getCrsCode() : string {
        return $this->crsCode;
    }

    public function promoteToStation(LocationRepositoryInterface $repository) : ?Station {
        $station = $repository->getLocationByCrs($this->getCrsCode());
        if (!$station instanceof Station) {
            return null;
        }
        return new Station(
            $this->tiploc
            , $station->crsCode
            , $this->name
            , $station->minorCrsCode
            , $station->interchange
            , $station->easting
            , $station->northing
            , $station->minimumConnectionTime
            , $station->tocConnectionTimes
        );
    }
}