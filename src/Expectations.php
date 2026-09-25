<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Reads automator results from entities and evaluates fixture matchers.
 */
final class Expectations {

  /**
   * Returns a field's stored values as scalars.
   *
   * Booleans become TRUE/FALSE, numbers become int or float, and list keys
   * keep their stored type. An empty array means nothing was stored.
   */
  public static function values(ContentEntityInterface $entity, string $field): array {
    if (!$entity->hasField($field)) {
      return [];
    }
    $type = $entity->getFieldDefinition($field)->getType();
    $values = [];
    foreach ($entity->get($field)->getValue() as $item) {
      $value = $item['value'] ?? NULL;
      if ($value === NULL || $value === '') {
        continue;
      }
      $values[] = match ($type) {
        'boolean' => (bool) $value,
        'integer', 'list_integer' => (int) $value,
        'decimal', 'float' => (float) $value,
        default => (string) $value,
      };
    }
    return $values;
  }

  /**
   * Formats values for display.
   */
  public static function format(array $values): string {
    if ($values === []) {
      return '(empty)';
    }
    return implode(', ', array_map(static fn ($value): string => match (TRUE) {
      is_bool($value) => $value ? 'TRUE' : 'FALSE',
      is_float($value) => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.'),
      default => (string) $value,
    }, $values));
  }

  /**
   * Describes a matcher.
   */
  public static function describe(array $matcher): string {
    $parts = [];
    foreach ($matcher as $name => $expected) {
      $parts[] = $name . ' ' . self::format(is_array($expected) ? $expected : [$expected]);
    }
    return implode('; ', $parts);
  }

  /**
   * Evaluates a matcher against stored values.
   */
  public static function matches(array $values, array $matcher): bool {
    $first = $values[0] ?? NULL;
    foreach ($matcher as $name => $expected) {
      $ok = match ($name) {
        'empty' => ($values === []) === (bool) $expected,
        'equals' => $first !== NULL && self::same($first, $expected),
        'in' => $first !== NULL && array_filter((array) $expected, static fn ($option) => self::same($first, $option)) !== [],
        'includes' => array_diff(array_map('strval', (array) $expected), array_map('strval', $values)) === [],
        'excludes' => array_intersect(array_map('strval', (array) $expected), array_map('strval', $values)) === [],
        'min' => is_numeric($first) && $first >= $expected,
        'max' => is_numeric($first) && $first <= $expected,
        default => throw new \InvalidArgumentException("Unknown matcher '$name'."),
      };
      if (!$ok) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Compares a stored value with an expected one.
   */
  private static function same(mixed $actual, mixed $expected): bool {
    if (is_bool($actual) || is_bool($expected)) {
      return (bool) $actual === (bool) $expected;
    }
    if (is_numeric($actual) && is_numeric($expected)) {
      return abs((float) $actual - (float) $expected) < 0.0001;
    }
    return (string) $actual === (string) $expected;
  }

}
