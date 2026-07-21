<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Attributes\ElementType;

readonly class Station extends Location {
    use BsonSerializeTrait;

    public function __construct(
        string $tiploc
        , ?string $crsCode
        , string $name
        , public string $minorCrsCode
        , public int $interchange
        , public int $easting
        , public int $northing
        , public int $minimumConnectionTime
        , array $tocConnectionTimes
    ) {
        parent::__construct($tiploc, $crsCode, $name);
        $this->tocConnectionTimes = $tocConnectionTimes;
    }

    public function getConnectionTime(?string $from_toc, ?string $to_toc) : int {
        foreach ($this->tocConnectionTimes as $interchange) {
            if ($interchange->arrivingToc === $from_toc && $interchange->departingToc === $to_toc) {
                return $interchange->connectionTime;
            }
        }
        return $this->minimumConnectionTime;
    }

    public function getCoordinates() : array {
        return parent::getCoordinates() ?? [$this->easting, $this->northing];
    }

    /** @var TocInterchange[] */
    #[ElementType(TocInterchange::class)]
    public array $tocConnectionTimes;
}