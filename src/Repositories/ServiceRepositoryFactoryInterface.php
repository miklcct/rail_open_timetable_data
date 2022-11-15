<?php
declare(strict_types = 1);

namespace Miklcct\RailOpenTimetableData\Repositories;

interface ServiceRepositoryFactoryInterface {
    public function __invoke(bool $permanentOnly = false) : ServiceRepositoryInterface;
}