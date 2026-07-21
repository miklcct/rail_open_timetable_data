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
        static $cache = [];
        $class_name = self::class;
        $class_info = $cache[$class_name] ?? null;
        if ($class_info === null) {
            $class = new ReflectionClass($class_name);
            $parent_class = $class->getParentClass();
            $properties = [];
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class_name && $property->isPublic() && !$property->isStatic()) {
                    $type = $property->getType();
                    if (!$type instanceof ReflectionNamedType) {
                        throw new RuntimeException('This trait supports named type only.');
                    }
                    $element_type = null;
                    foreach ($property->getAttributes(ElementType::class) as $attribute) {
                        /** @var ElementType $instance */
                        $instance = $attribute->newInstance();
                        $element_type = $instance->type;
                        break;
                    }
                    $properties[] = [
                        'name' => $property->name,
                        'type_name' => $type->getName(),
                        'element_type' => $element_type,
                    ];
                }
            }
            $class_info = $cache[$class_name] = [
                'properties' => $properties,
                'has_parent_unserialize' => $parent_class !== false && method_exists($parent_class->getName(), 'bsonUnserialize'),
            ];
        }

        if ($class_info['has_parent_unserialize']) {
            parent::bsonUnserialize($data);
        }
        foreach ($class_info['properties'] as $prop) {
            $key = $prop['name'];
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            $type_name = $prop['type_name'];
            if ($type_name === 'array') {
                if ($value instanceof stdClass) {
                    $value = (array)$value;
                }
                $element_type = $prop['element_type'];
                if ($element_type !== null) {
                    foreach ($value as &$element) {
                        $element = self::processValue($element_type, $element);
                    }
                    unset($element);
                }
            }
            /** @noinspection PhpVariableVariableInspection */
            $this->$key = self::processValue($type_name, $value);
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