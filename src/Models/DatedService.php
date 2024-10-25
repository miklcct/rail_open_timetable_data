<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Points\TimingPoint;
use MongoDB\BSON\Persistable;
use function Miklcct\RailOpenTimetableData\get_absolute_time_zone;

class DatedService implements Persistable {
    use BsonSerializeTrait;

    public function __construct(
        public readonly ServiceEntry $service
        , public readonly Date $date
    ) {}

    public function getId() : string {
        return "{$this->service->uid}_$this->date";
    }

    /**
     * @param TimeType $time_type
     * @param string|null $crs
     * @param DateTimeImmutable|null $from
     * @param DateTimeImmutable|null $to
     * @return ServiceCall[]
     */
    public function getCalls(
        TimeType $time_type
        , ?string $crs = null
        , DateTimeImmutable $from = null
        , DateTimeImmutable $to = null
    ) : array {
        $service = $this->assertService();
        // assume that a train stick to BST / GMT on departure regardless of clock change en-route
        $time_zone = $this->getAbsoluteTimeZone();
        return array_values(
            array_filter(
                array_map(
                    function (TimingPoint $point) use ($time_zone, $service, $crs, $time_type) : ?ServiceCall {
                        $location = $point->location;
                        if ($crs !== null && !($location instanceof LocationWithCrs && $location->getCrsCode() === $crs)) {
                            return null;
                        }
                        $time = $point->getTime($time_type);
                        $timestamp = $time === null ? null : $this->date->toDateTimeImmutable($time, $time_zone);
                        return $time === null ? null : new ServiceCall(
                            $timestamp
                            , $time_type
                            , $this->service->uid
                            , $this->date
                            , $point
                            , $service->mode
                            , $service->toc
                            , $service->getServicePropertyAtTime($time)
                            , $service->shortTermPlanning
                        );
                    }
                    , $service->points
                )
                , static function (?ServiceCall $service_call) use ($to, $from) {
                    $timestamp = $service_call?->timestamp;
                    return $timestamp !== null
                        && ($from === null || $timestamp >= $from)
                        && ($to === null || $timestamp < $to);
                }
            )
        );
    }

    public function getAbsoluteTimeZone() : DateTimeZone {
        $service = $this->assertService();
        $departure = $service->getOrigin()->getWorkingDeparture();
        if (
            $service->toc === 'LO' && $this->service->shortTermPlanning === ShortTermPlanning::NEW
            && $service->period->from->compare($service->period->to) === 0
            && $service->period->from->month === 10
            && $service->period->from->day >= 25
            && $service->period->weekdays[0]
            && $departure->hours === 1
        ) {
            return new DateTimeZone("UTC");
        }
        return get_absolute_time_zone($this->date, $departure);
    }

    private function assertService() : Service {
        $service = $this->service;
        if (!$service instanceof Service) {
            throw new LogicException('The service within DatedService must be a proper Service to get calling points.');
        }
        return $service;
    }
}
