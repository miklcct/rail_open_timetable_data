<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Miklcct\RailOpenTimetableData\Models\Date;
use MongoDB\Database;
use Psr\SimpleCache\CacheInterface;

class MongodbRepository implements RepositoryInterface {
    public function __construct(public Database $database) {
        $this->locationRepository = new MongodbLocationRepository($database);
    }

    public function getFixedLinkRepository() : FixedLinkRepositoryInterface {
        return $this->fixedLinkRepository ??= new MongodbFixedLinkRepository($this->database);
    }

    public function getLocationRepository() : LocationRepositoryInterface {
        return $this->locationRepository ??= new MongodbLocationRepository($this->database);
    }

    public function getServiceRepository(bool $permanent_only = false) : ServiceRepositoryInterface {
        if ($permanent_only) {
            return $this->permanentServiceRepository ??= new MongodbServiceRepository($this->database, true);
        }
        return $this->serviceRepository ??= new MongodbServiceRepository($this->database, false);
    }
    
    public function addIndexes() : void {
        $this->fixedLinkRepository->addIndexes();
        $this->serviceRepository->addIndexes();
        $this->locationRepository->addIndexes();
    }

    public function getGeneratedDate(): ?Date {
        return $this->database->selectCollection('metadata')->findOne(['generated' => ['$exists' => true]])?->generated;
    }

    public function setGeneratedDate(?Date $date) : void {
        $this->database->selectCollection('metadata')->insertOne(['generated' => $date]);
    }

    public function clear() : void {
        $this->database->drop();
    }

    private ?MongodbFixedLinkRepository $fixedLinkRepository;
    private ?MongodbServiceRepository $serviceRepository;
    private ?MongodbServiceRepository $permanentServiceRepository;
    private ?MongodbLocationRepository $locationRepository;
}