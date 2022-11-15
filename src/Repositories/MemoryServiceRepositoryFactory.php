<?php
declare(strict_types = 1);

namespace Miklcct\RailOpenTimetableData\Repositories;

class MemoryServiceRepositoryFactory implements ServiceRepositoryFactoryInterface {
    public function __invoke(bool $permanentOnly = false): ServiceRepositoryInterface
    {
        return new MemoryServiceRepository($permanentOnly);
    }
}