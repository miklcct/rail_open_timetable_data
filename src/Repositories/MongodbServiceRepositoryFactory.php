<?php
declare(strict_types = 1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use MongoDB\Database;
use Psr\SimpleCache\CacheInterface;

class MongodbServiceRepositoryFactory implements ServiceRepositoryFactoryInterface {
    public function __construct(
        private readonly Database $database
        , private readonly ?CacheInterface $cache
    ) {}

    public function __invoke(bool $permanentOnly = false): ServiceRepositoryInterface {
        return new MongodbServiceRepository($this->database, $this->cache, $permanentOnly);
    }
}