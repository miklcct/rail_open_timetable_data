<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Exceptions;

use RuntimeException;

class UnreachableException extends RuntimeException {
    public function __construct() {
        parent::__construct('This should not happen.');
    }
}