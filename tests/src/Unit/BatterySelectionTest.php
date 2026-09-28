<?php

declare(strict_types=1);

namespace Drupal\Tests\typesafe_smoke\Unit;

use Drupal\typesafe_smoke\Battery;
use Drupal\typesafe_smoke\BatterySelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests selection before any service or API access.
 */
#[Group('typesafe_smoke')]
class BatterySelectionTest extends TestCase {

  /**
   * Defaults, groups and duplicate IDs preserve catalog order.
   */
  public function testSelection(): void {
    $checks = [
      'C01' => 'Capabilities',
      'B01' => 'Yes/no',
      'B09' => 'Large choice',
      'B11' => 'Large score',
      'G01' => 'Guardrail',
    ];
    $this->assertSame($checks, BatterySelection::resolve($checks));
    $this->assertSame($checks, BatterySelection::resolve($checks, '  '));
    $this->assertSame(['B01', 'B09', 'B11', 'G01'], array_keys(BatterySelection::resolve($checks, 'G01, B, B01, B')));
    $this->assertSame(['B01', 'B09', 'B11'], array_keys(BatterySelection::resolve($checks, 'B', TRUE)));
  }

  /**
   * Invalid selectors fail even if other tokens are valid.
   */
  #[DataProvider('invalidSelections')]
  public function testInvalidSelection(string $only, bool $skip_large = FALSE): void {
    // An unconstructed battery proves rejection needs none of its services.
    $battery = (new \ReflectionClass(Battery::class))->newInstanceWithoutConstructor();
    $this->expectException(\InvalidArgumentException::class);
    $battery->run('jev-latest', $only, $skip_large);
  }

  /**
   * Supplies typos, separator-only selections and fully excluded selections.
   */
  public static function invalidSelections(): array {
    return [['B99'], ['B,B99'], ['X'], ['b'], [', ,'], ['B09,B11', TRUE]];
  }

}
