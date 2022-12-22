<?php
declare(strict_types=1);

namespace Miklcct\RailOpenTimetableData\Models;

use BackedEnum;
use DateTimeInterface;
use Miklcct\RailOpenTimetableData\Attributes\ElementType;
use MongoDB\BSON\UTCDateTime;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use stdClass;
use UnexpectedValueException;
use function is_a;
use function method_exists;

// this trait needs to be included in every class in a hierarchy
// due to the way bsonUnserialize works in regard to readonly property
trait BsonSerializeTrait {
    public function bsonSerialize() : array {
        return array_map(
            static fn($value) => $value instanceof DateTimeInterface ? new UTCDateTime($value) : $value
            , (array)$this
        );
    }

    public function bsonUnserialize(array $data) : void {
        $class = new ReflectionClass(self::class);
        if ($class->getParentClass() !== false && method_exists(parent::class, 'bsonUnserialize')) {
            parent::bsonUnserialize($data);
        }
        foreach ($class->getProperties() as $property) {
            $declaring_class_name = $property->getDeclaringClass()->getName();
            if ($declaring_class_name === self::class && $property->isPublic() && !$property->isStatic()) {
                $key = $property->name;
                $type = $property->getType();
                $value = $data[$key];
                if (!$type instanceof ReflectionNamedType) {
                    throw new RuntimeException('This trait supports named type only.');
                }
                if ($type->getName() === 'array') {
                    if ($value instanceof stdClass) {
                        $value = (array)$value;
                    }
                    foreach ($property->getAttributes() as $attribute) {
                        $instance = $attribute->newInstance();
                        if ($instance instanceof ElementType) {
                            foreach ($value as &$element) {
                                $element = self::processValue($instance->type, $element);
                            }
                            unset($element);
                        }
                    }
                }
                /** @noinspection PhpVariableVariableInspection */
                $this->$key = self::processValue($type->getName(), $value);
            }
        }
    }

    private static function processValue(string $type, mixed $value) : mixed {
        if (is_a($type, BackedEnum::class, true)) {
            return $type::tryFrom(is_object($value) ? $value->value : $value);
        }
        if (is_a($type, DateTimeInterface::class, true)) {
            if (!$value instanceof UTCDateTime) {
                throw new UnexpectedValueException('Only BSON UTCDateTime can be loaded into DateTimeInterface');
            }
            return $type::createFromInterface($value->toDateTime());
        }
        return $value;
    }
}