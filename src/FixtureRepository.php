<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Loads the smoke test fixtures shipped with the module.
 */
final class FixtureRepository {

  /**
   * Constructs the repository.
   */
  public function __construct(
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Returns the ticket fixtures keyed by ID, with generated messages built.
   *
   * @param string[] $only
   *   Optional fixture IDs to keep.
   */
  public function tickets(array $only = []): array {
    $tickets = $this->load('tickets.yml');
    $defaults = [
      'message' => '',
      'expect' => [],
      'must' => [],
      'blocked' => [],
      'expect_blocked' => [],
      'no_calls' => [],
    ];
    foreach ($tickets as &$ticket) {
      $ticket += $defaults;
      if (($ticket['generated'] ?? NULL) === 'long_log') {
        $ticket['message'] = $this->longLog();
      }
    }
    unset($ticket);
    if ($only !== []) {
      $unknown = array_diff($only, array_keys($tickets));
      if ($unknown !== []) {
        throw new \InvalidArgumentException('Unknown ticket fixture(s): ' . implode(', ', $unknown));
      }
      $tickets = array_intersect_key($tickets, array_flip($only));
    }
    return $tickets;
  }

  /**
   * Returns the CiviCRM meeting fixtures keyed by ID.
   */
  public function meetings(): array {
    return $this->load('meetings.yml');
  }

  /**
   * Returns the contrib probe texts.
   */
  public function probes(): array {
    return $this->load('probes.yml');
  }

  /**
   * Builds about 6,000 characters of log output around one real question.
   *
   * Request IDs are hexadecimal and timestamps are split by punctuation, so
   * nothing resembles a payment card number.
   */
  public function longLog(): string {
    $lines = [];
    for ($i = 0; $i < 30; $i++) {
      $lines[] = sprintf('2026-09-24T10:%02d:%02dZ WARN import.worker job=imp-%s row=%d msg="Column mapping skipped: unknown header \'Region\'"', intdiv($i, 2), ($i * 7) % 60, substr(md5((string) $i), 0, 10), 100 + $i);
    }
    $question = "Our nightly CSV import has failed every night this week. It stops at row 130 with the error below. Is this a bug on your side, or is our file wrong? We have not changed the file format.";
    $tail = [];
    for ($i = 0; $i < 12; $i++) {
      $tail[] = sprintf('2026-09-24T10:30:%02dZ ERROR import.worker job=imp-%s row=130 msg="Invalid UTF-8 sequence in column \'Notes\'; aborting batch"', $i * 4, substr(sha1((string) $i), 0, 10));
    }
    return "Hello support,\n\n" . implode("\n", array_slice($lines, 0, 15)) . "\n\n" . $question . "\n\n" . implode("\n", array_slice($lines, 15)) . "\n" . implode("\n", $tail) . "\n\nThanks.";
  }

  /**
   * Loads one fixture file.
   */
  private function load(string $file): array {
    $path = $this->moduleList->getPath('typesafe_smoke') . '/fixtures/' . $file;
    $data = Yaml::decode((string) file_get_contents($path));
    if (!is_array($data)) {
      throw new \RuntimeException("Fixture file $path is empty or invalid.");
    }
    return $data;
  }

}
