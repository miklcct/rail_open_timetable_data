<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Parsers;

use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Repositories\RepositoryInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use function Miklcct\RailOpenTimetableData\array_rotate;
use function Safe\glob;

function parse_line(string $line, array $widths) : array { 
    return $widths === []
        ? []
        : array_merge(
            [rtrim(substr($line, 0, $widths[0]))]
            , parse_line(substr($line, $widths[0]), array_slice($widths, 1))
        ); 
}

function parse_weekdays(string $weekdays) : array { 
    return array_rotate(
        array_map(static fn(string $char) => $char !== '0', str_split($weekdays))
        , -1
    );
}

function load_timetable_directory(RepositoryInterface $repository, string $path, ?array $tocs = null) : bool {
    $file_names = glob("$path/*.DAT");
    if ($file_names === []) {
        throw new RuntimeException('No data exist!');
    }

    rsort($file_names);
    $prefix = basename($file_names[0], '.DAT');

    $dat_contents = file("$path/$prefix.DAT");
    $date = null;
    foreach ($dat_contents as $line) {
        if (str_contains($line, 'Generated')) {
            preg_match('/\d{2}\/\d{2}\/\d{4}/', $line, $matches);
            sscanf($matches[0], '%d/%d/%d', $day, $month, $year);
            $date = new Date($year, $month, $day);
        }
    }

    if ($date === null) {
        throw new RuntimeException('Cannot get date generated.');
    }

    if ($date->__toString() === $repository->getGeneratedDate()?->__toString()) {
        fwrite(STDERR, "Database is up to date. Exiting.\n");
        return false;
    }
    
    $repository->clear();

    fwrite(STDERR, "Loading station data.\n");
    $time = microtime(true);
    $stations = $repository->getLocationRepository();
    $fixed_links = $repository->getFixedLinkRepository();
    new StationParser($stations)
        ->parseFile(
            fopen("$path/$prefix.MSN", 'rb')
            , fopen("$path/$prefix.TSI", 'rb')
        );
    new FixedLinkParser($stations, $fixed_links)
        ->parseFile(fopen("$path/$prefix.ALF", 'rb'));
    fprintf(STDERR, "Time used: %.3f s\n", microtime(true) - $time);

    fwrite(STDERR, "Loading timetable data.\n");
    $time = microtime(true);
    $timetable = $repository->getServiceRepository();
    foreach (['MCA', 'ZTR'] as $suffix) {
        new TimetableParser($timetable, $stations)
            ->parseFile(
                fopen("$path/$prefix.$suffix", 'rb')
                , $tocs
            );
    }
    fprintf(STDERR, "Time used: %.3f s\n", microtime(true) - $time);

    /** @var CacheInterface $cache */
    $repository->setGeneratedDate($date);
    fwrite(STDERR, "Done!\n");     
    return true;
}