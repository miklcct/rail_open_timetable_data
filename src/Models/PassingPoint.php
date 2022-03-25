<?php
declare(strict_types=1);

namespace Miklcct\NationalRailJourneyPlanner\Models;

class PassingPoint extends IntermediatePoint {
    public function __construct(
        string $location
        , string $platform
        , string $path
        , string $line
        , public readonly Time $pass
        , int $allowanceHalfMinutes
        , array $activities
        , ?ServiceProperty $servicePropertyChange
    ) {
        parent::__construct(
            $location
            , $platform
            , $path
            , $line
            , $allowanceHalfMinutes
            , $activities
            , $servicePropertyChange
        );
    }
}