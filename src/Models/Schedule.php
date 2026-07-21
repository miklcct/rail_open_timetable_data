<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use Miklcct\RailOpenTimetableData\Enums\BankHoliday;
use Miklcct\RailOpenTimetableData\Enums\Mode;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Models\Points\DestinationPoint;
use Miklcct\RailOpenTimetableData\Models\Points\OriginOrIntermediatePoint;
use Miklcct\RailOpenTimetableData\Models\Points\OriginPoint;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;

class Schedule extends ScheduleEntry {
    use BsonSerializeTrait;

    public function __construct(
        string $uid
        , Period $period
        , BankHoliday $excludeBankHoliday
        , public readonly Mode $mode
        , public readonly string $toc
        , array $timingPoints
        , ShortTermPlanning $shortTermPlanning
    ) {
        parent::__construct(
            $uid
            , $period
            , $excludeBankHoliday
            , $shortTermPlanning
        );
        $this->timingPoints = $timingPoints;
    }

    /** @var TimingPoint[] */
    #[ElementType(TimingPoint::class)]
    public readonly array $timingPoints;

    public function getOrigin() : OriginPoint {
        return $this->timingPoints[0];
    }

    public function getDestination() : DestinationPoint {
        return $this->timingPoints[count($this->timingPoints) - 1];
    }

    public function hasRsid(string $rsid) : bool {
        return array_any($this->timingPoints, fn($point) => $point instanceof OriginOrIntermediatePoint
            && str_starts_with($point->serviceProperty?->rsid ?? '', $rsid));
    }
}