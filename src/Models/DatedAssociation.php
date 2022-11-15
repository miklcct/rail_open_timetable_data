<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

class DatedAssociation {
    public function __construct(
        public readonly Association $association
        , public readonly DatedService $primaryService
        , public readonly DatedService $secondaryService
    ) {}
}