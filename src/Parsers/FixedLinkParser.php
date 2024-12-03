<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Parsers;

use LogicException;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\FixedLink;
use Miklcct\RailOpenTimetableData\Models\LocationWithCrs;
use Miklcct\RailOpenTimetableData\Models\Time;
use Miklcct\RailOpenTimetableData\Models\TiplocLocationWithCrs;
use Miklcct\RailOpenTimetableData\Repositories\FixedLinkRepositoryInterface;
use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;
use DateTimeImmutable;
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
                    if (!$origin instanceof LocationWithCrs) {
                        fwrite(STDERR, "Unknown CRS $fields[1] in fixed link\n");
                        $origin = new TiplocLocationWithCrs("$fields[1]----", $fields[1], $fields[1], null);
                    }
                    break;
                case 'D':
                    $destination = $this->locationRepository->getLocationByCrs($fields[1]);
                    if (!$destination instanceof LocationWithCrs) {
                        fwrite(STDERR, "Unknown CRS $fields[1] in fixed link\n");
                        $destination = new TiplocLocationWithCrs("$fields[1]----", $fields[1], $fields[1], null);
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
                        )->setTime(0, 0)
                        );
                    break;
                case 'U':
                    $endDate = Date::fromDateTimeInterface(
                        DateTimeImmutable::createFromFormat(
                            'd/m/Y'
                            , $fields[1]
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
