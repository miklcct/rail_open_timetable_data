<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;

class Station extends Location implements LocationWithCrs {
    use BsonSerializeTrait;

    public function __construct(
        string $tiploc
        , public readonly string $crsCode
        , string $name
        , public readonly string $minorCrsCode
        , public readonly int $interchange
        , public readonly int $easting
        , public readonly int $northing
        , public readonly int $minimumConnectionTime
        , array $tocConnectionTimes
    ) {
        parent::__construct($tiploc, $name);
        $this->tocConnectionTimes = $tocConnectionTimes;
    }

    public function getCrsCode() : string {
        return $this->crsCode;
    }

    public function getConnectionTime(?string $from_toc, ?string $to_toc) : int {
        foreach ($this->tocConnectionTimes as $interchange) {
            if ($interchange->arrivingToc === $from_toc && $interchange->departingToc === $to_toc) {
                return $interchange->connectionTime;
            }
        }
        return $this->minimumConnectionTime;
    }

    public function promoteToStation(LocationRepositoryInterface $repository) : Station {
        $station = $repository->getLocationByCrs($this->getCrsCode());
        assert($station instanceof Station);
        return new Station(
            $this->tiploc
            , $station->crsCode
            , $station->name
            , $station->minorCrsCode
            , $station->interchange
            , $station->easting
            , $station->northing
            , $station->minimumConnectionTime
            , $station->tocConnectionTimes
        );
    }


    /** @var TocInterchange[] */
    #[ElementType(TocInterchange::class)]
    public readonly array $tocConnectionTimes;
}