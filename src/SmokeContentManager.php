<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates, tracks and deletes the content the smoke tests make.
 */
final class SmokeContentManager {

  /**
   * Node bundles owned by this module.
   */
  public const BUNDLES = ['typesafe_smoke_ticket', 'typesafe_smoke_probe'];

  /**
   * State key for tracked content.
   */
  public const STATE_TRACKED = 'typesafe_smoke.tracked';

  /**
   * State key for per-ticket seed records.
   */
  public const STATE_SEED = 'typesafe_smoke.seed';

  /**
   * State key for settings to restore on uninstall.
   */
  public const STATE_RESTORE = 'typesafe_smoke.restore';

  /**
   * Constructs the manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns tracked content.
   *
   * @return array{nodes: array<string, int>, civicrm_activity: int[], civicrm_contact: int|null}
   *   Tracked IDs.
   */
  public function tracked(): array {
    $defaults = ['nodes' => [], 'civicrm_activity' => [], 'civicrm_contact' => NULL];
    return ($this->state->get(self::STATE_TRACKED) ?? []) + $defaults;
  }

  /**
   * Saves tracked content.
   */
  private function track(array $tracked): void {
    $this->state->set(self::STATE_TRACKED, $tracked);
  }

  /**
   * Loads the tracked ticket node for a fixture, if any.
   */
  public function loadTicket(string $fixture_id): ?NodeInterface {
    $nid = $this->tracked()['nodes'][$fixture_id] ?? NULL;
    if ($nid === NULL) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$nid]);
    $node = $storage->load($nid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Creates a ticket node; saving it runs the Decision automators.
   */
  public function createTicket(string $fixture_id, array $fixture, int $uid): NodeInterface {
    $values = [
      'type' => 'typesafe_smoke_ticket',
      'title' => $fixture['title'],
      'uid' => $uid,
      'status' => 0,
      'field_tss_tier' => $fixture['tier'] ?? NULL,
    ];
    if (trim((string) ($fixture['message'] ?? '')) !== '') {
      $values['field_tss_message'] = ['value' => $fixture['message'], 'format' => 'plain_text'];
    }
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $node->save();
    $tracked = $this->tracked();
    $tracked['nodes'][$fixture_id] = (int) $node->id();
    $this->track($tracked);
    return $node;
  }

  /**
   * Deletes one tracked ticket node.
   */
  public function deleteTicket(string $fixture_id): void {
    $node = $this->loadTicket($fixture_id);
    if ($node) {
      $node->delete();
    }
    $tracked = $this->tracked();
    unset($tracked['nodes'][$fixture_id]);
    $this->track($tracked);
  }

  /**
   * Deletes every node of the smoke bundles, tracked or not.
   *
   * @return int
   *   The number of nodes deleted.
   */
  public function deleteNodes(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $deleted = 0;
    do {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', self::BUNDLES, 'IN')->range(0, 50)->execute();
      if ($ids) {
        $storage->delete($storage->loadMultiple($ids));
        $deleted += count($ids);
      }
    } while ($ids);
    $tracked = $this->tracked();
    $tracked['nodes'] = [];
    $this->track($tracked);
    $this->state->delete(self::STATE_SEED);
    return $deleted;
  }

  /**
   * Initializes CiviCRM, or returns FALSE when it is unavailable.
   */
  public function initCivicrm(): bool {
    if (!$this->moduleHandler->moduleExists('civicrm')) {
      return FALSE;
    }
    // The civicrm service exists only when CiviCRM is installed, so it is
    // not injected.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::service('civicrm')->initialize();
    return class_exists('\Civi\Api4\Activity');
  }

  /**
   * Returns the tracked smoke contact, creating it when needed.
   */
  public function ensureContact(): int {
    $tracked = $this->tracked();
    if ($tracked['civicrm_contact']) {
      $existing = Contact::get(FALSE)->addWhere('id', '=', $tracked['civicrm_contact'])->addWhere('is_deleted', '=', FALSE)->selectRowCount()->execute()->rowCount;
      if ($existing) {
        return (int) $tracked['civicrm_contact'];
      }
    }
    $contact = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'TypeSafe')
      ->addValue('last_name', 'Smoke (delete me)')
      ->addValue('source', 'typesafe_smoke module')
      ->execute()
      ->first();
    $tracked['civicrm_contact'] = (int) $contact['id'];
    $this->track($tracked);
    return (int) $contact['id'];
  }

  /**
   * Records a CiviCRM activity ID for later deletion.
   */
  public function trackActivity(int $id): void {
    $tracked = $this->tracked();
    $tracked['civicrm_activity'] = array_values(array_unique(array_merge($tracked['civicrm_activity'], [$id])));
    $this->track($tracked);
  }

  /**
   * Deletes tracked CiviCRM activities and the smoke contact.
   *
   * @return string[]
   *   Problems encountered; empty on success.
   */
  public function deleteCivicrm(): array {
    $tracked = $this->tracked();
    if (!$tracked['civicrm_activity'] && !$tracked['civicrm_contact']) {
      return [];
    }
    $problems = [];
    try {
      if (!$this->initCivicrm()) {
        return ['CiviCRM is unavailable; tracked IDs kept in State ' . self::STATE_TRACKED . '.'];
      }
      foreach ($tracked['civicrm_activity'] as $id) {
        try {
          Activity::delete(FALSE)->addWhere('id', '=', $id)->execute();
        }
        catch (\Throwable $e) {
          $problems[] = "Activity $id: " . $e->getMessage();
        }
      }
      if ($tracked['civicrm_contact']) {
        try {
          Contact::delete(FALSE)->setUseTrash(FALSE)->addWhere('id', '=', $tracked['civicrm_contact'])->execute();
        }
        catch (\Throwable $e) {
          $problems[] = 'Contact ' . $tracked['civicrm_contact'] . ': ' . $e->getMessage();
        }
      }
    }
    catch (\Throwable $e) {
      $problems[] = $e->getMessage();
    }
    if ($problems) {
      foreach ($problems as $problem) {
        $this->logger->warning('Could not delete smoke CiviCRM data: @problem', ['@problem' => $problem]);
      }
      return $problems;
    }
    $tracked['civicrm_activity'] = [];
    $tracked['civicrm_contact'] = NULL;
    $this->track($tracked);
    return [];
  }

}
