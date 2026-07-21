<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\DomainModels;

use Miklcct\RailOpenTimetableData\Models\Association;
use Miklcct\RailOpenTimetableData\Models\Date;

readonly class AssociationWithService {
    public function __construct(
        public Association $association,
        public int $primaryIndex,
        public Service $secondaryService,
    ) {
        $this->date = $this->association->getAssociationDateFromSecondaryDate($this->secondaryService->date);
    }
    
    public Date $date;
}