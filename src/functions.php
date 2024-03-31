<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData;

use DateTimeInterface;
use DateTimeZone;
use Miklcct\RailOpenTimetableData\Enums\TimeType;
use Miklcct\RailOpenTimetableData\Models\Date;
use Miklcct\RailOpenTimetableData\Models\Time;
use MongoDB\Database;
use function Safe\file_get_contents;
use function Safe\json_decode;

/**
 * Rotate an array
 *
 * @param array $array
 * @param int $offset positive to rotate to the left, negative to the right
 * @return array
 */
function array_rotate(array $array, int $offset) : array {
    return array_merge(
        array_slice($array, $offset)
        , array_slice($array, 0, $offset)
    );
}

/**
 * Get the list of all TOCs in code => name format
 *
 * @return array<string, string>
 */
function get_all_tocs() : array {
    static $result;
    $result ??= json_decode(file_get_contents(__DIR__ . '/../resource/toc.json'), true);
    return $result;
}

/**
 * Get the full version of truncated station name
 */
function get_full_station_name(string $name) : string {
    static $mapping;
    $mapping ??= json_decode(file_get_contents(__DIR__ . '/../resource/long_station_names.json'), true);
    return $mapping[$name] ?? $name;
}

function get_generated(Database $database) : ?Date {
    return $database->selectCollection('metadata')->findOne(['generated' => ['$exists' => true]])?->generated;
}

function set_generated(Database $database, ?Date $date) {
    $database->selectCollection('metadata')->insertOne(['generated' => $date]);
}

/**
 * Get the absolute time zone (specified in UTC offset) of a date and time
 *
 * @param Date $date
 * @param Time $time
 * @return DateTimeZone
 */
function get_absolute_time_zone(Date $date, Time $time) : DateTimeZone {
    $date_time = $date->toDateTimeImmutable($time);
    // The difference is to handle departure time in the "missing hour" such as the 01:05 from Waterloo
    $utc_offset = $date_time->getOffset() + ($time->toHalfMinutes() - Time::fromDateTimeInterface($date_time)->toHalfMinutes()) * 30;
    $negative = $utc_offset < 0;
    $hours = intdiv(abs($utc_offset), 60 * 60);
    $minutes = intdiv(abs($utc_offset) - $hours * 60 * 60, 60);
    return new DateTimeZone(sprintf('%s%02d:%02d', $negative ? '-' : '+', $hours, $minutes));
}
