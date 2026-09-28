<?php

declare(strict_types=1);

namespace Drupal\Tests\typesafe_smoke\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LogMessageParser;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\typesafe_smoke\CallRecorder;
use Drupal\typesafe_smoke\CivicrmProbe;
use Drupal\typesafe_smoke\FixtureRepository;
use Drupal\typesafe_smoke\SmokeContentManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests cleanup even when enabling automators or executing saves fails.
 */
#[Group('typesafe_smoke')]
class CivicrmProbeCleanupTest extends TestCase {

  /**
   * Cleanup always stops recording and restores the original account.
   */
  #[DataProvider('failures')]
  public function testCleanup(bool $setup_fails, bool $leave_enabled): void {
    $enabled = FALSE;
    $automator = $this->createMock(ConfigEntityInterface::class);
    $automator->method('id')->willReturn(CivicrmProbe::PREFIX . 'field_tss_mtg_follow_up.default');
    $automator->method('status')->willReturnCallback(static function () use (&$enabled) {
      return $enabled;
    });
    $automator->method('setStatus')->willReturnCallback(static function ($status) use (&$enabled, $automator) {
      $enabled = $status;
      return $automator;
    });
    $automator->method('save')->willReturnCallback(static function () use (&$enabled, $setup_fails) {
      if ($setup_fails && $enabled) {
        throw new \RuntimeException('Enable failed');
      }
      return 1;
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$automator]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('ai_automator')->willReturn($storage);
    $switcher = $this->createMock(AccountSwitcherInterface::class);
    $switcher->expects($this->once())->method('switchTo');
    $switcher->expects($this->once())->method('switchBack');
    $recorder = new CallRecorder(new LogMessageParser());
    $probe = new CivicrmProbe(
      $manager,
      (new \ReflectionClass(SmokeContentManager::class))->newInstanceWithoutConstructor(),
      (new \ReflectionClass(FixtureRepository::class))->newInstanceWithoutConstructor(),
      $recorder,
      $switcher,
      $this->createMock(ConfigFactoryInterface::class),
    );
    try {
      (new \ReflectionMethod($probe, 'withAutomators'))->invoke($probe, static function (): array {
        throw new \RuntimeException('Save failed');
      }, $leave_enabled);
      $this->fail('The original failure should propagate.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame($setup_fails ? 'Enable failed' : 'Save failed', $e->getMessage());
      $this->assertFalse($recorder->isActive());
      $this->assertSame($leave_enabled, $enabled);
    }
  }

  /**
   * Exercises both failure locations and explicit leave-enabled behavior.
   */
  public static function failures(): array {
    return [[TRUE, FALSE], [FALSE, FALSE], [FALSE, TRUE]];
  }

}
