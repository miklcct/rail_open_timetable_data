<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\FixedLink;

interface FixedLinkRepositoryInterface {
    /**
     * @param FixedLink[] $fixed_links
     * @return void
     */
    public function insert(array $fixed_links) : void;

    /**
     * @param string|null $origin_crs
     * @param string|null $destination_crs
     * @return FixedLink[]
     */
    public function get(?string $origin_crs, ?string $destination_crs) : array;
}