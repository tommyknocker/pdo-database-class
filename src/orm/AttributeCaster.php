<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use ReflectionEnum;
use Throwable;

/** Conversion between explicitly declared model attribute types and database values. */
final class AttributeCaster
{
    public static function fromDatabase(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'json') {
            if (!is_string($value)) {
                throw new InvalidArgumentException('JSON attributes must contain JSON text in the database.');
            }

            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException('JSON attributes must decode to an array or object.');
                }
                return $decoded;
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Invalid JSON attribute.', 0, $e);
            }
        }
        if ($type === DateTimeImmutable::class) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Datetime attributes must contain a nonempty date string.');
            }

            try {
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    throw new InvalidArgumentException('Invalid datetime attribute.');
                }
                return $date;
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Invalid datetime attribute.', 0, $e);
            }
        }
        if (enum_exists($type) && is_subclass_of($type, BackedEnum::class)) {
            try {
                $backing = (new ReflectionEnum($type))->getBackingType()?->getName();
                if ($backing === 'int' && is_string($value)) {
                    $integer = filter_var($value, FILTER_VALIDATE_INT);
                    if ($integer === false) {
                        throw new InvalidArgumentException('Invalid integer enum attribute.');
                    }
                    $value = $integer;
                }
                return $type::from($value);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Invalid enum attribute.', 0, $e);
            }
        }

        throw new InvalidArgumentException("Unsupported attribute cast: {$type}");
    }

    public static function toDatabase(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'json') {
            if (!is_array($value)) {
                throw new InvalidArgumentException('JSON attributes must be assigned an array.');
            }

            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Cannot encode JSON attribute.', 0, $e);
            }
        }
        if ($type === DateTimeImmutable::class) {
            if (!$value instanceof DateTimeInterface) {
                throw new InvalidArgumentException('Datetime attributes must be assigned a DateTimeInterface.');
            }
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        if (enum_exists($type) && is_subclass_of($type, BackedEnum::class)) {
            if (!$value instanceof $type) {
                throw new InvalidArgumentException("Enum attribute requires an instance of {$type}.");
            }
            return $value->value;
        }

        throw new InvalidArgumentException("Unsupported attribute cast: {$type}");
    }
}
