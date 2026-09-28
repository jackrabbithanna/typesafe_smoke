<?php

declare(strict_types=1);

namespace Drupal\Tests\typesafe_smoke\Unit;

use Drupal\typesafe_smoke\CivicrmProbe;
use Drupal\typesafe_smoke\CivicrmResultEvaluator;
use Drupal\typesafe_smoke\Drush\Commands\TypeSafeSmokeCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests structural failure severity independently of probabilistic judgments.
 */
#[Group('typesafe_smoke')]
class CivicrmResultEvaluatorTest extends TestCase {

  /**
   * Generates one successful save-path observation.
   */
  private function observation(array $fields): array {
    $snapshot = ['calls' => [], 'values' => [], 'logs' => [], 'http' => []];
    foreach ($fields as $field) {
      $snapshot['calls'][] = [
        'operation' => 'decision',
        'automator' => CivicrmProbe::PREFIX . $field . '.default',
        'status' => 'ok',
      ];
      $snapshot['values'][] = ['field' => $field, 'values' => [TRUE]];
    }
    return $snapshot;
  }

  /**
   * Each enabled save path requires the expected number of executions.
   */
  public function testSuccessfulPaths(): void {
    $all = CivicrmProbe::FIELDS;
    $details = array_values(array_diff($all, ['field_tss_mtg_note_tone']));
    foreach ([$all, $details, $details, $all] as $fields) {
      $result = CivicrmResultEvaluator::evaluate($fields, $this->observation($fields), array_fill_keys($fields, [TRUE]));
      $this->assertSame('PASS', $result['result']);
    }
  }

  /**
   * Hard failures cannot become warnings or successful command exits.
   */
  #[DataProvider('hardFailures')]
  public function testHardFailure(string $fault): void {
    $fields = ['field_tss_mtg_follow_up'];
    $snapshot = $this->observation($fields);
    $persisted = [$fields[0] => [TRUE]];
    $errors = [];
    switch ($fault) {
      case 'missing call':
        $snapshot['calls'] = [];
        break;

      case 'duplicate call':
        $snapshot['calls'][] = $snapshot['calls'][0];
        break;

      case 'unexpected automator':
        $snapshot['calls'][0]['automator'] = 'unrelated';
        break;

      case 'provider failure':
        $snapshot['calls'][0]['status'] = 'exception';
        break;

      case 'swallowed worker error':
        $snapshot['logs'][] = ['level' => 'warning', 'message' => 'Storage failed'];
        break;

      case 'missing generated values':
        $snapshot['values'] = [];
        break;

      case 'duplicate generated values':
        $snapshot['values'][] = $snapshot['values'][0];
        break;

      case 'empty generated values':
        $snapshot['values'][0]['values'] = [];
        break;

      case 'persistence mismatch':
        $persisted[$fields[0]] = [FALSE];
        break;

      case 'missing persisted field':
        $persisted = [];
        break;

      case 'invalid field':
        $errors = ['Invalid field value'];
        break;
    }
    $result = CivicrmResultEvaluator::evaluate($fields, $snapshot, $persisted, [], $errors);
    $this->assertSame('FAIL', $result['result']);
    $this->assertSame(1, $this->exitCode($result, FALSE));
  }

  /**
   * Supplies structural defects that must not pass.
   */
  public static function hardFailures(): array {
    return array_map(static fn ($fault) => [$fault], [
      'missing call', 'duplicate call', 'unexpected automator', 'provider failure',
      'swallowed worker error', 'missing generated values', 'duplicate generated values',
      'empty generated values', 'persistence mismatch', 'missing persisted field', 'invalid field',
    ]);
  }

  /**
   * Only model judgment is soft; strict mode promotes it to a failed exit.
   */
  public function testSoftJudgmentAndDisabledHooks(): void {
    $fields = ['field_tss_mtg_follow_up'];
    $result = CivicrmResultEvaluator::evaluate($fields, $this->observation($fields), [$fields[0] => [TRUE]], [$fields[0] => ['equals' => FALSE]]);
    $this->assertSame('WARN', $result['result']);
    $this->assertSame(0, $this->exitCode($result, FALSE));
    $this->assertSame(1, $this->exitCode($result, TRUE));
    $empty = ['calls' => [], 'values' => [], 'logs' => [], 'http' => []];
    $result = CivicrmResultEvaluator::evaluate($fields, $empty, [], hooks_disabled: TRUE);
    $this->assertSame('SKIP', $result['result']);
    $this->assertSame(0, $this->exitCode($result, TRUE));
    $empty['http'][] = ['status' => 200];
    $this->assertSame('FAIL', CivicrmResultEvaluator::evaluate($fields, $empty, [], hooks_disabled: TRUE)['result']);
    $this->assertSame('FAIL', CivicrmResultEvaluator::evaluate($fields, $this->observation($fields), [], hooks_disabled: TRUE)['result']);
  }

  /**
   * Uses the actual command exit policy without bootstrapping Drupal or Civi.
   */
  private function exitCode(array $result, bool $strict): int {
    $class = new \ReflectionClass(TypeSafeSmokeCommands::class);
    return $class->getMethod('exitCode')->invoke($class->newInstanceWithoutConstructor(), [$result], $strict);
  }

}
