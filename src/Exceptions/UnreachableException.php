<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Exceptions;

use RuntimeException;

class UnreachableException extends RuntimeException {
    public function __construct(string $message = 'This should not happen.') {
        parent::__construct($message);
    }
}