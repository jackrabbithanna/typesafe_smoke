<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

/**
 * Evaluates execution and persistence independently of model judgment.
 */
final class CivicrmResultEvaluator {

  /**
   * Evaluates raw observations from one save path.
   *
   * @param array $expected_fields
   *   Fields that must each execute exactly once on this path.
   * @param array $snapshot
   *   Recorder calls, generated values and logs for this path.
   * @param array $persisted
   *   Field names mapped to reloaded scalar value lists.
   * @param array $expectations
   *   Soft fixture matchers keyed by field name.
   * @param array $errors
   *   Entity loading or field validation errors.
   * @param bool $hooks_disabled
   *   Whether API4 automation is disabled by site configuration.
   *
   * @return array{result: string, detail: string, matched: int}
   *   Result severity and diagnostic details.
   */
  public static function evaluate(array $expected_fields, array $snapshot, array $persisted, array $expectations = [], array $errors = [], bool $hooks_disabled = FALSE): array {
    $calls = [];
    foreach ($snapshot['calls'] as $call) {
      if ($call['operation'] !== 'decision') {
        continue;
      }
      $id = (string) ($call['automator'] ?? 'unknown');
      $calls[$id][] = $call;
      if ($call['status'] !== 'ok') {
        $errors[] = "$id: call ended with " . $call['status'];
      }
    }
    foreach ($snapshot['logs'] as $log) {
      if (in_array($log['level'], ['emergency', 'alert', 'critical', 'error', 'warning'], TRUE)) {
        $errors[] = 'automator log: ' . $log['message'];
      }
    }
    $generated = [];
    foreach ($snapshot['values'] ?? [] as $value) {
      $generated[$value['field']][] = $value['values'];
    }
    if ($hooks_disabled) {
      if ($calls || $generated || $snapshot['http']) {
        $errors[] = 'Automation ran despite disabled CiviCRM activity hooks.';
      }
      return [
        'result' => $errors ? 'FAIL' : 'SKIP',
        'detail' => $errors ? implode(' | ', $errors) : 'CiviCRM activity hooks are disabled; automation coverage skipped.',
        'matched' => 0,
      ];
    }
    $expected_ids = array_map(static fn (string $field): string => CivicrmProbe::PREFIX . $field . '.default', $expected_fields);
    foreach ($calls as $id => $items) {
      if (!in_array($id, $expected_ids, TRUE)) {
        $errors[] = "$id: unexpected execution";
      }
    }
    foreach ($expected_fields as $field) {
      $id = CivicrmProbe::PREFIX . $field . '.default';
      $count = count($calls[$id] ?? []);
      if ($count !== 1) {
        $errors[] = "$field: expected one call, observed $count";
      }
      $values = $generated[$field] ?? [];
      if (count($values) !== 1 || $values[0] === []) {
        $errors[] = "$field: expected one nonempty generated value list";
      }
      elseif (($persisted[$field] ?? []) !== array_values($values[0])) {
        $errors[] = "$field: generated values did not persist unchanged";
      }
    }
    foreach (array_diff(array_keys($generated), $expected_fields) as $field) {
      $errors[] = "$field: unexpected generated values";
    }
    $matched = 0;
    foreach ($expectations as $field => $matcher) {
      $matched += (int) Expectations::matches($persisted[$field] ?? [], $matcher);
    }
    $soft_miss = $matched < count($expectations);
    return [
      'result' => $errors ? 'FAIL' : ($soft_miss ? 'WARN' : 'PASS'),
      'detail' => $errors ? implode(' | ', $errors) : ($soft_miss ? 'Execution and persistence passed; model judgment differed from the fixture.' : 'Execution and persistence passed.'),
      'matched' => $matched,
    ];
  }

}
