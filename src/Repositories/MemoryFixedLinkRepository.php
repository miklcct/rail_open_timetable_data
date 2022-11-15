<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\FixedLink;
use function array_merge;

class MemoryFixedLinkRepository implements FixedLinkRepositoryInterface {
    public function insert(array $fixed_links) : void {
        $this->fixedLinks = array_merge($this->fixedLinks, $fixed_links);
    }

    public function get(?string $origin_crs, ?string $destination_crs) : array {
        return array_filter(
            $this->fixedLinks
            , static fn(FixedLink $fixed_link) =>
                ($origin_crs === null || $fixed_link->origin->crsCode === $origin_crs)
                    && ($destination_crs === null || $fixed_link->destination->crsCode === $destination_crs)
        );
    }

    /** @var FixedLink[] */
    private array $fixedLinks = [];
}