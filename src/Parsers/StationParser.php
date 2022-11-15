<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Parsers;

use Miklcct\RailOpenTimetableData\Models\Station;
use Miklcct\RailOpenTimetableData\Models\TocInterchange;
use Miklcct\RailOpenTimetableData\Repositories\LocationRepositoryInterface;
use function array_filter;
use function fgetcsv;
use function fgets;
use function Miklcct\RailOpenTimetableData\get_full_station_name;
use function str_starts_with;

class StationParser {
    public function __construct(
        private readonly Helper $helper
        , private readonly LocationRepositoryInterface $locationRepository
    ) {
    }

    /**
     * Parse station info
     *
     * @param resource $msn_file master station names file (name ends in .MSN)
     * @param resource $tsi_file TOC interchanges file (name ends in .TSI)
     * @return void
     */
    public function parseFile($msn_file, $tsi_file) : void {
        // parse TOC interchanges
        $toc_interchanges = [];
        while (($columns = fgetcsv($tsi_file)) !== false) {
            assert(is_array($columns));
            $toc_interchanges[] = [
                $columns[0],
                new TocInterchange($columns[1], $columns[2], (int)$columns[3]),
            ];
        }

        // skip headers
        do {
            $line = fgets($msn_file);
        } while (str_starts_with($line, '/!!'));

        $stations = [];

        for (
            $line = fgets($msn_file);
            str_starts_with($line, 'A');
            $line = fgets($msn_file)
        ) {
            $columns = $this->helper->parseLine(
                $line, [1, 4, 26, 4, 1, 7, 3, 3, 3, 5, 1, 5, 2, 1, 1, 11, 3]
            );
            $stations[] = new Station(
                tiploc: $columns[5]
                , crsCode: $columns[8]
                , name: get_full_station_name($columns[2])
                , interchange: (int)$columns[4]
                , minorCrsCode: $columns[6]
                , easting: ((int)$columns[9] - 10000) * 100
                , northing: ((int)$columns[11] - 60000) * 100
                , minimumConnectionTime: (int)$columns[12]
                , tocConnectionTimes: array_values(
                    array_map(
                        static fn(array $item) => $item[1]
                        , array_filter(
                            $toc_interchanges
                            , static fn(array $entry) : bool => $entry[0] === $columns[8]
                        )
                    )
                )
            );

        }

        $aliases = [];
        // parse aliases
        while (str_starts_with($line, 'L')) {
            $columns = $this->helper->parseLine($line, [1, 4, 26, 5, 26, 20]);
            $aliases[get_full_station_name($columns[4])] = get_full_station_name($columns[2]);
            $line = fgets($msn_file);
        }

        $this->locationRepository->insertLocations($stations);
        $this->locationRepository->insertAliases($aliases);
    }
}