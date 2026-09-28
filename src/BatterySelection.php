<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

/**
 * Resolves battery selectors without services, recording or API calls.
 */
final class BatterySelection {

  /**
   * Checks skipped by --skip-large.
   */
  public const LARGE = ['B09', 'B11'];

  /**
   * Returns selected checks in catalog order, retaining individual skip rows.
   */
  public static function resolve(array $checks, string|array $only = [], bool $skip_large = FALSE): array {
    $provided = is_string($only) ? trim($only) !== '' : $only !== [];
    $tokens = is_string($only) ? explode(',', $only) : $only;
    $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens), static fn (string $value): bool => $value !== '')));
    if ($provided && $tokens === []) {
      throw new \InvalidArgumentException('The selection contains no check IDs or groups.');
    }
    $groups = array_unique(array_map(static fn (string $id): string => $id[0], array_keys($checks)));
    $unknown = array_diff($tokens, array_keys($checks), $groups);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Unknown battery selector(s): ' . implode(', ', $unknown) . '. Use --list for check IDs, or groups ' . implode(', ', $groups) . '.');
    }
    $selected = array_filter($checks, static fn (string $id): bool => $tokens === [] || in_array($id, $tokens, TRUE) || in_array($id[0], $tokens, TRUE), ARRAY_FILTER_USE_KEY);
    if ($selected === [] || ($skip_large && array_diff(array_keys($selected), self::LARGE) === [])) {
      throw new \InvalidArgumentException('The selection has no runnable checks after exclusions.');
    }
    return $selected;
  }

}
