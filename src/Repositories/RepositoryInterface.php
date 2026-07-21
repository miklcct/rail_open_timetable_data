<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\Date;

interface RepositoryInterface {
    public function getFixedLinkRepository() : FixedLinkRepositoryInterface;
    public function getLocationRepository() : LocationRepositoryInterface;
    public function getServiceRepository(bool $permanent_only = false) : ServiceRepositoryInterface;
    
    public function clear();
    
    public function getGeneratedDate() : ?Date;

    public function setGeneratedDate(?Date $date);

}