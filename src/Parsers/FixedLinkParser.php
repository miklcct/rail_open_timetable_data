<?php
declare(strict_types=1);

namespace Miklcct\NationalRailTimetable\Parsers;

use DateTimeZone;
use LogicException;
use Miklcct\NationalRailTimetable\Models\Date;
use Miklcct\NationalRailTimetable\Models\FixedLink;
use Miklcct\NationalRailTimetable\Models\Station;
use Miklcct\NationalRailTimetable\Models\Time;
use Miklcct\NationalRailTimetable\Repositories\FixedLinkRepositoryInterface;
use Miklcct\NationalRailTimetable\Repositories\LocationRepositoryInterface;
use Safe\DateTimeImmutable;
use function explode;
use function fgetcsv;

class FixedLinkParser {
    public function __construct(
        private readonly Helper $helper
        , private readonly LocationRepositoryInterface $locationRepository
        , private readonly FixedLinkRepositoryInterface $fixedLinkRepository
    ) {
    }

    /**
     * @param resource $file additional fixed links file (name ends with .ALF)
     * @return void
     */
    public function parseFile(
        $file
    ) : void {
        $fixed_links = [];
        while (($columns = fgetcsv($file)) !== false) {
            $mode = null;
            $origin = null;
            $destination = null;
            $transferTime = null;
            $startTime = null;
            $endTime = null;
            $priority = null;
            $startDate = null;
            $endDate = null;
            $weekdays = null;
            foreach ($columns as $column) {
                $fields = explode('=', $column);
                switch ($fields[0]) {
                case 'M':
                    $mode = $fields[1];
                    break;
                case 'O':
                    $origin = $this->locationRepository->getLocationByCrs($fields[1]);
                    if (!$origin instanceof Station) {
                        throw new LogicException('Fixed links must start and end with a station.');
                    }
                    break;
                case 'D':
                    $destination = $this->locationRepository->getLocationByCrs($fields[1]);
                    if (!$destination instanceof Station) {
                        throw new LogicException('Fixed links must start and end with a station.');
                    }

                    break;
                case 'T':
                    $transferTime = (int)$fields[1];
                    break;
                case 'S':
                    $startTime = Time::fromHhmm($fields[1]);
                    break;
                case 'E':
                    $endTime = Time::fromHhmm($fields[1]);
                    break;
                case 'P':
                    $priority = (int)$fields[1];
                    break;
                case 'F':
                    $startDate = Date::fromDateTimeInterface(
                        DateTimeImmutable::createFromFormat(
                            'd/m/Y'
                            , $fields[1]
                            , new DateTimeZone('Europe/London')
                        )->setTime(0, 0)
                        );
                    break;
                case 'U':
                    $endDate = Date::fromDateTimeInterface(
                        DateTimeImmutable::createFromFormat(
                            'd/m/Y'
                            , $fields[1]
                            , new DateTimeZone('Europe/London')
                        )->setTime(0, 0)
                    );
                    break;
                case 'R':
                    $weekdays = $this->helper->parseWeekdays($fields[1]);
                    break;
                }
            }
            $fixed_links[] = new FixedLink(
                $mode
                , $origin
                , $destination
                , $transferTime
                , $startTime
                , $endTime
                , $priority
                , $startDate
                , $endDate
                , $weekdays
            );
            $fixed_links[] = new FixedLink(
                $mode
                , $destination
                , $origin
                , $transferTime
                , $startTime
                , $endTime
                , $priority
                , $startDate
                , $endDate
                , $weekdays
            );
        }
        $this->fixedLinkRepository->insert($fixed_links);
    }
}