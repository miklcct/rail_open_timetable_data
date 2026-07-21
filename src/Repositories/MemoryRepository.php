<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Repositories;

use Closure;
use ErrorException;
use Miklcct\RailOpenTimetableData\Models\Date;
use Safe\DateTimeImmutable;

class MemoryRepository implements RepositoryInterface {
    
    public function __construct(private readonly ?string $directory = null) {
        if ($directory !== null && is_dir($directory)) {
            try {
                $contents = file_get_contents("$directory/generated.txt");
                $this->date = Date::fromDateTimeInterface(new DateTimeImmutable($contents));
            } catch (ErrorException) {
            }
        }
        if (extension_loaded('igbinary')) {
            $this->serialize = igbinary_serialize(...);
            $this->unserialize = igbinary_unserialize(...);
        } else {
            $this->serialize = serialize(...);
            $this->unserialize = unserialize(...);
        }
    }

    public function getFixedLinkRepository() : FixedLinkRepositoryInterface {
        return $this->fixedLinkRepository ??= $this->directory !== null && is_dir($this->directory) 
            ? ($this->unserialize)(file_get_contents("$this->directory/fixed_links.bin")) 
            : new MemoryFixedLinkRepository();
    }

    public function getLocationRepository() : LocationRepositoryInterface {
        return $this->locationRepository ??= $this->directory !== null && is_dir($this->directory)
            ? ($this->unserialize)(file_get_contents("$this->directory/locations.bin"))
            : new MemoryLocationRepository();
    }

    public function getServiceRepository(bool $permanent_only = false) : ServiceRepositoryInterface {
        if ($permanent_only) {
            return $this->permanentServiceRepository ??= $this->getServiceRepository()->makePermanentRepository();
        }
        
        return $this->serviceRepository ??= $this->directory !== null && is_dir($this->directory)
            ? ($this->unserialize)(file_get_contents("$this->directory/timetable.bin"))
            : new MemoryServiceRepository();
    }
    
    public function save(string $directory) : void {
        file_put_contents("$directory/fixed_links.bin", ($this->serialize)($this->fixedLinkRepository));
        file_put_contents("$directory/locations.bin", ($this->serialize)($this->locationRepository));
        file_put_contents("$directory/timetable.bin", ($this->serialize)($this->serviceRepository));
        file_put_contents("$directory/generated.txt", $this->date?->__toString());
    }

    public function getGeneratedDate(): ?Date {
        return $this->date;
    }

    public function setGeneratedDate(?Date $date) : void {
        $this->date = $date;
    }

    public function clear() : void {
        $this->fixedLinkRepository = null;
        $this->locationRepository = null;
        $this->serviceRepository = null;
        $this->permanentServiceRepository = null;
    }
    
    private ?MemoryFixedLinkRepository $fixedLinkRepository;
    private ?MemoryLocationRepository $locationRepository;
    private ?MemoryServiceRepository $serviceRepository;

    private ?MemoryServiceRepository $permanentServiceRepository;
    private ?Date $date = null;
    private readonly Closure $serialize;
    private readonly Closure $unserialize;

}