<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Parsers;

use Miklcct\RailOpenTimetableData\Enums\Activity;
use Miklcct\RailOpenTimetableData\Enums\AssociationCategory;
use Miklcct\RailOpenTimetableData\Enums\AssociationDay;
use Miklcct\RailOpenTimetableData\Enums\AssociationType;
use Miklcct\RailOpenTimetableData\Enums\BankHoliday;
use Miklcct\RailOpenTimetableData\Enums\Catering;
use Miklcct\RailOpenTimetableData\Enums\Mode;
use Miklcct\RailOpenTimetableData\Enums\Power;
use Miklcct\RailOpenTimetableData\Enums\Reservation;
use Miklcct\RailOpenTimetableData\Enums\ShortTermPlanning;
use Miklcct\RailOpenTimetableData\Enums\TrainCategory;
use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\AssociationCancellation;
use Miklcct\RailOpenTimetableData\Models\AssociationEntry;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Location;
use Miklcct\RailOpenTimetableData\Models\Period;
use Miklcct\RailOpenTimetableData\Models\Points\CallingPoint;
use Miklcct\RailOpenTimetableData\Models\Points\DestinationPoint;
use Miklcct\RailOpenTimetableData\Models\Points\IntermediatePoint;
use Miklcct\RailOpenTimetableData\Models\Points\OriginPoint;
use Miklcct\RailOpenTimetableData\Models\Points\PassingPoint;
use Miklcct\RailOpenTimetableData\Models\Schedule;
use Miklcct\RailOpenTimetableData\Models\ScheduleCancellation;
use Miklcct\RailOpenTimetableData\Models\ScheduleEntry;
use Miklcct\RailOpenTimetableData\Models\ServiceProperty;
use Miklcct\RailOpenTimetableData\Models\Time;
use Miklcct\RailOpenTimetableData\Models\Tiploc;
use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;
use Miklcct\RailOpenTimetableData\Repositories\ServiceRepositoryInterface;
use function array_filter;
use function array_map;
use function fgets;
use function Miklcct\RailOpenTimetableData\get_full_station_name;
use function str_contains;
use function str_split;
use function str_starts_with;

readonly class TimetableParser {
    public function __construct(
        private ServiceRepositoryInterface $serviceRepository
        , private LocationRepositoryInterface $locationRepository
    ) {
    }

    /**
     * @param resource $file timetable file (ends with .MCA)
     * @param ?array<string> $tocs The list of TOCs to be imported. If null, all TOCS will be imported.
     */
    public function parseFile($file, ?array $tocs = null) : void {
        if ($tocs !== null) {
            foreach ($tocs as &$toc) {
                $toc = strtoupper($toc);
            }
        }
        $schedules = [];
        $locations = [];
        $associations = [];
        while (($line = fgets($file)) !== false) {
            switch ($transaction_type = substr($line, 0, 2)) {
            case 'AA':
            case 'BS':
                if ($locations !== []) {
                    $this->locationRepository->insertLocations($locations);
                    $locations = [];
                }
                switch ($transaction_type) {
                case 'AA':
                    $associations[] = $this->parseAssociation($line);
                    break;
                case 'BS':
                    $schedule = $this->parseSchedule($file, $line);
                    if (!$schedule instanceof Schedule || $tocs === null || in_array($schedule->toc, $tocs)) {
                        $schedules[] = $schedule;
                    }
                    break;
                }
                break;
            case 'TI':
                $locations[] = $this->parseLocation($line);
                break;
            }

            if (count($schedules) >= 1000) {
                $this->serviceRepository->insertSchedules($schedules);
                $schedules = [];
            }
        }
        $this->serviceRepository->insertSchedules($schedules);
        $this->serviceRepository->insertAssociations($associations);
        $this->locationRepository->insertLocations($locations);
    }

    private function parseAssociation(string $line) : AssociationEntry {
        $columns = parse_line($line, [2, 1, 6, 6, 6, 6, 7, 2, 1, 7, 1, 1, 1, 1, 31, 1]);
        $primaryUid = $columns[2];
        $secondaryUid = $columns[3];
        $primarySuffix = $columns[10];
        $secondarySuffix = $columns[11];
        $period = new Period(
            $this->parseYymmdd($columns[4])
            , $this->parseYymmdd($columns[5])
            , parse_weekdays($columns[6])
        );
        $location = $this->getLocation($columns[9]);
        $shortTermPlanning = ShortTermPlanning::from($columns[15]);
        return $shortTermPlanning === ShortTermPlanning::CANCEL
            ? new AssociationCancellation(
                $primaryUid
                , $secondaryUid
                , $primarySuffix
                , $secondarySuffix
                , $period
                , $location
                , $shortTermPlanning
            )
            : new Association(
                $primaryUid
                , $secondaryUid
                , $primarySuffix
                , $secondarySuffix
                , $period
                , $location
                , AssociationCategory::from($columns[7])
                , AssociationDay::from($columns[8])
                , AssociationType::from($columns[13])
                , $shortTermPlanning
            );
    }

    /**
     * @param resource $file
     * @param string $line
     * @return ScheduleEntry
     */
    private function parseSchedule($file, string $line) : ScheduleEntry {
        $columns = parse_line($line, [2, 1, 6, 6, 6, 7, 1, 1, 2, 4, 4, 1, 8, 1, 3, 4, 3, 6, 1, 1, 1, 1, 4, 4, 1, 1]);
        $uid = $columns[2];
        $from = $this->parseYymmdd($columns[3]);
        $to = $this->parseYymmdd($columns[4]);
        $weekdays = parse_weekdays($columns[5]);
        $excludeBankHoliday = BankHoliday::from($columns[6]);
        $shortTermPlanning = ShortTermPlanning::from($columns[25]);
        if ($shortTermPlanning === ShortTermPlanning::CANCEL) {
            return new ScheduleCancellation(
                $uid
                , new Period($from, $to, $weekdays)
                , $excludeBankHoliday
                , $shortTermPlanning
            );
        }
        $line = fgets($file);
        assert(is_string($line) && str_starts_with($line, 'BX'));
        $bx_columns = parse_line($line, [2, 4, 5, 2, 1, 8]);
        $toc = $bx_columns[3];
        $serviceProperty = new ServiceProperty(
            trainCategory: TrainCategory::from($columns[8])
            , identity: $columns[9]
            , headcode: $columns[10]
            , portionId: $columns[13]
            , power: Power::from($columns[14])
            , timingLoad: $columns[15]
            , speedMph: $columns[16] === '' ? null : (int)$columns[16]
            , doo: $this->isDoo($columns[17])
            , seatingClasses: $this->parseSeatingClasses($columns[18])
            , sleeperClasses: $this->parseSleeperClasses($columns[19])
            , reservation: Reservation::from($columns[20])
            , caterings: $this->parseCaterings($columns[22])
            , rsid: $bx_columns[5]
        );

        $points = [];
        $last_call = null;
        do {
            $line = fgets($file);
            assert($line !== false);
            switch (substr($line, 0, 2)) {
            case 'LO':
                $point = $this->parseOrigin($line, $serviceProperty);
                $last_call = $point->workingDeparture;
                $points[] = $point;
                break;
            case 'LI':
                $point = $this->parseIntermediate($line, $last_call, $serviceProperty);
                $last_call = $point instanceof PassingPoint
                    ? $point->pass
                    : (
                        $point instanceof CallingPoint
                            ? $point->workingDeparture
                            : $last_call
                    );
                $points[] = $point;
                break;
            case 'LT':
                $points[] = $this->parseDestination($line, $last_call);
                break;
            case 'CR':
                $serviceProperty = $this->parseServicePropertyChange($line);
                break;
            }
        } while (!str_starts_with($line, 'LT'));

        return new Schedule(
            $uid
            , new Period($from, $to, $weekdays)
            , $excludeBankHoliday
            , match($columns[7]) {
                'S', '4' => Mode::SHIP,
                'B', '5' => Mode::BUS,
                default => Mode::TRAIN,
            }
            , $toc
            , $points
            , $shortTermPlanning
        );
    }

    private function parseSeatingClasses(string $string) : array {
        return match ($string) {
            '', 'S' => [1 => false, 2 => true],
            'B' => [1 => true, 2 => true],
        };
    }

    private function parseSleeperClasses(string $string) : array {
        return match ($string) {
            '' => [1 => false, 2 => false],
            'B' => [1 => true, 2 => true],
            'F' => [1 => true, 2 => false],
            'S' => [1 => false, 2 => true],
        };
    }

    private function parseOrigin(string $line, ServiceProperty $serviceProperty) : OriginPoint {
        $columns = parse_line($line, [2, 8, 5, 4, 3, 3, 2, 2, 12, 2]);
        $location_columns = parse_line($columns[1], [7, 1]);
        return new OriginPoint(
            location: $this->getLocation($location_columns[0])
            , locationSuffix: (int)$location_columns[1]
            , workingDeparture: Time::fromHhmm($columns[2])
            , publicDeparture: $this->parsePublicTime($columns[3], null)
            , platform: $columns[4]
            , line: $columns[5]
            , engineeringAllowance: $this->parseAllowance($columns[6])
            , pathingAllowance: $this->parseAllowance($columns[7])
            , performanceAllowance: $this->parseAllowance($columns[9])
            , activities: $this->parseActivities($columns[8])
            , serviceProperty: $serviceProperty
        );
    }

    private function parseIntermediate(
        string $line
        , Time $last_call
        , ServiceProperty $change
    )
        : IntermediatePoint {
        $columns = parse_line($line, [2, 8, 5, 5, 5, 4, 4, 3, 3, 3, 12, 2, 2, 2]);
        $location_columns = parse_line($columns[1], [7, 1]);
        return $columns[4] !== ''
            ? new PassingPoint(
                location: $this->getLocation($location_columns[0])
                , locationSuffix: (int)$location_columns[1]
                , pass: Time::fromHhmm($columns[4], $last_call)
                , platform: $columns[7]
                , line: $columns[8]
                , path: $columns[9]
                , activities: $this->parseActivities($columns[10])
                , engineeringAllowance: $this->parseAllowance($columns[11])
                , pathingAllowance: $this->parseAllowance($columns[12])
                , performanceAllowance: $this->parseAllowance($columns[13])
                , serviceProperty: $change
            )
            : new CallingPoint(
                location: $this->getLocation($location_columns[0])
                , locationSuffix: (int)$location_columns[1]
                , workingArrival: Time::fromHhmm($columns[2], $last_call)
                , workingDeparture: Time::fromHhmm($columns[3], $last_call)
                , publicArrival: $this->parsePublicTime($columns[5], $last_call)
                , publicDeparture:
                    $this->parsePublicTime($columns[6], $last_call)
                , platform: $columns[7]
                , line: $columns[8]
                , path: $columns[9]
                , activities: $this->parseActivities($columns[10])
                , engineeringAllowance: $this->parseAllowance($columns[11])
                , pathingAllowance: $this->parseAllowance($columns[12])
                , performanceAllowance: $this->parseAllowance($columns[13])
                , serviceProperty: $change
            );
    }

    private function parseDestination(string $line, Time $last_call)
        : DestinationPoint {
        $columns = parse_line($line, [2, 8, 5, 4, 3, 3, 12]);
        $location_columns = parse_line($columns[1], [7, 1]);
        return new DestinationPoint(
            location: $this->getLocation($location_columns[0])
            , locationSuffix: (int)$location_columns[1]
            , workingArrival: Time::fromHhmm($columns[2], $last_call)
            , publicArrival: $this->parsePublicTime($columns[3], $last_call)
            , platform: $columns[4]
            , path: $columns[5]
            , activities: $this->parseActivities($columns[6])
        );
    }

    private function parseAllowance(string $string) : Time {
        return ($string[1] ?? '') === 'H' ? new Time(0, (int)$string[0], 30) : new Time(0, (int)$string);
    }

    /**
     * @return Activity[]
     */
    private function parseActivities(string $string) : array {
        return array_values(
            array_filter(
                array_map(
                    Activity::tryFrom(...)
                    , parse_line($string, [2, 2, 2, 2, 2, 2])
                )
            )
        );
    }

    private function parsePublicTime(string $string, ?Time $last_call) : ?Time {
        return $string === '0000' ? null : Time::fromHhmm($string, $last_call);
    }

    private function parseServicePropertyChange(string $line)
    : ServiceProperty {
        $columns = parse_line($line, [2, 8, 2, 4, 4, 1, 8, 1, 3, 4, 3, 6, 1, 1, 1, 1, 4, 4, 4, 5, 8]);
        return new ServiceProperty(
            trainCategory: TrainCategory::from($columns[2])
            , identity: $columns[3]
            , headcode: $columns[4]
            , portionId: $columns[7]
            , power: Power::from($columns[8])
            , timingLoad: $columns[9]
            , speedMph: $columns[10] === '' ? null : (int)$columns[10]
            , doo: $this->isDoo($columns[11])
            , seatingClasses: $this->parseSeatingClasses($columns[12])
            , sleeperClasses: $this->parseSleeperClasses($columns[13])
            , reservation: Reservation::from($columns[14])
            , caterings: $this->parseCaterings($columns[16])
            , rsid: $columns[20]
        );
    }

    private function isDoo(string $operating_chars) : bool {
        return str_contains($operating_chars, 'D');
    }

    /**
     * @return Catering[]
     */
    private function parseCaterings(mixed $caterings) : array {
        return array_map(
            Catering::from(...)
            , array_values(array_filter(str_split($caterings)))
        );
    }

    private function parseYymmdd(string $string) : Date {
        $columns = parse_line($string, [2, 2, 2]);
        $year = (int)$columns[0] + 2000;
        $month = (int)$columns[1];
        $day = (int)$columns[2];
        return new Date($year, $month, $day);
    }

    private function parseLocation(string $line) : Tiploc {
        $columns = parse_line($line, [2, 7, 2, 6, 1, 26, 5, 4, 3, 16]);
        $stanox = (int)$columns[6];
        if ($stanox === 0) {
            $stanox = null;
        }
        $crs = $columns[8] === '' ? null : $columns[8];
        return new Tiploc(
            tiploc: $columns[1]
            , name: get_full_station_name($columns[5])
            , crsCode: $crs
            , stanox: $stanox
        );
    }

    private function getLocation(string $location) : ?Location {
        if (substr($location, 3, 4) === '----') {
            // Z-train
            $crs = substr($location, 0, 3);
            $result = $this->locationRepository->getLocationByCrs($crs);
            if ($result === null) {
                fwrite(STDERR, "Unknown CRS $crs referred in Z-train\n");
                return new Tiploc($location, $crs, $crs, null);
            }
            // will not be needed beyond PHP 8.2
            assert($result instanceof Location);
            return $result;
        }
        $result = $this->locationRepository->getLocationByTiploc($location);
        return $result;
    }
}