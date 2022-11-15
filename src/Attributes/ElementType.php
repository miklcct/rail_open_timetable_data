<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_ALL & ~Attribute::TARGET_CLASS)]
class ElementType {
    public function __construct(public readonly string $type) {}
}