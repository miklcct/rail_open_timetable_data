<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateTimeImmutable;
use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use Miklcct\RailOpenTimetableData\Enums\Mode;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;

class ServiceCallWithDestinationAndCalls extends ServiceCallWithDestination {
    use BsonSerializeTrait;

    public function __construct(
        DateTimeImmutable $timestamp
        , TimeType $timeType
        , string $uid
        , Date $date
        , TimingPoint $call
        , Mode $mode
        , string $toc
        , ServiceProperty $serviceProperty
        , array $origins
        , array $destinations
        , array $precedingCalls
        , array $subsequentCalls
        , ShortTermPlanning $shortTermPlanning = ShortTermPlanning::PERMANENT
    ) {
        parent::__construct($timestamp, $timeType, $uid, $date, $call, $mode, $toc, $serviceProperty, $origins, $destinations, $shortTermPlanning);
        $this->precedingCalls = $precedingCalls;
        $this->subsequentCalls = $subsequentCalls;
    }

    /** @var ServiceCallWithDestination[] */
    #[ElementType(ServiceCallWithDestination::class)]
    public array $precedingCalls;
    /** @var ServiceCallWithDestination[] */
    #[ElementType(ServiceCallWithDestination::class)]
    public array $subsequentCalls;
}