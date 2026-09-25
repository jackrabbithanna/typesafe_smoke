<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Civi\Api4\Activity;
use Civi\Api4\OptionValue;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;

/**
 * Runs the CiviCRM Meeting automators through Drupal and CiviCRM save paths.
 *
 * Civicrm_entity bridges CiviCRM's own pre and post hooks to Drupal entity
 * hooks, so an API4 save can run automators on an entity object that is not
 * the one that gets stored. This probe records what actually persists.
 */
final class CivicrmProbe {

  /**
   * The smoke fields on the meeting bundle.
   */
  public const FIELDS = [
    'field_tss_mtg_follow_up',
    'field_tss_mtg_outcome',
    'field_tss_mtg_note_tone',
    'field_tss_mtg_engagement',
  ];

  /**
   * Automator ID prefix.
   */
  public const PREFIX = 'civicrm_activity.meeting.';

  /**
   * Constructs the probe.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SmokeContentManager $content,
    private readonly FixtureRepository $fixtures,
    private readonly CallRecorder $recorder,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Enables or disables the CiviCRM smoke automators.
   */
  public function setAutomators(bool $enabled): int {
    $storage = $this->entityTypeManager->getStorage('ai_automator');
    $count = 0;
    foreach ($storage->loadByProperties(['entity_type' => 'civicrm_activity', 'bundle' => 'meeting']) as $automator) {
      if (str_starts_with((string) $automator->id(), self::PREFIX . 'field_tss_mtg_') && $automator->status() !== $enabled) {
        $automator->setStatus($enabled)->save();
        $count++;
      }
    }
    return $count;
  }

  /**
   * Runs the selected paths.
   *
   * @param string $paths
   *   One of all, drupal or api4.
   * @param bool $leave_enabled
   *   Keep the automators enabled afterwards (for a manual UI test).
   *
   * @return array[]
   *   One row per path.
   */
  public function run(string $paths, bool $leave_enabled): array {
    if (!$this->content->initCivicrm()) {
      throw new \RuntimeException('CiviCRM is not available.');
    }
    $meetings = $this->fixtures->meetings();
    $contact_id = $this->content->ensureContact();
    $type = OptionValue::get(FALSE)->addWhere('option_group_id:name', '=', 'activity_type')->addWhere('name', '=', 'Meeting')->addSelect('value')->execute()->first();
    $status = OptionValue::get(FALSE)->addWhere('option_group_id:name', '=', 'activity_status')->addWhere('name', '=', 'Completed')->addSelect('value')->execute()->first();

    $rows = [];
    $this->accountSwitcher->switchTo(new UserSession(['uid' => 1]));
    $this->setAutomators(TRUE);
    $this->recorder->start('civicrm-' . date('Ymd-His'));
    try {
      $a_id = NULL;
      $b_id = NULL;
      if ($paths !== 'api4') {
        $rows[] = $this->path('A', 'Drupal entity API create (M1)', function () use ($meetings, $contact_id, $type, $status, &$a_id) {
          $activity = $this->storage()->create([
            'bundle' => 'meeting',
            'activity_type_id' => (int) $type['value'],
            'subject' => $meetings['M1']['subject'],
            'details' => ['value' => $meetings['M1']['details'], 'format' => 'plain_text'],
            'field_ce_meeting_note' => $meetings['M1']['note'],
            'source_contact_id' => $contact_id,
            'status_id' => (int) $status['value'],
            'activity_date_time' => date('Y-m-d H:i:s'),
          ]);
          $activity->save();
          $a_id = (int) $activity->id();
          return $a_id;
        }, $meetings['M1']['expect']);
      }
      if ($paths !== 'drupal') {
        $rows[] = $this->path('B', 'CiviCRM API4 create (M2)', function () use ($meetings, $contact_id, &$b_id) {
          $created = Activity::create(FALSE)
            ->addValue('activity_type_id:name', 'Meeting')
            ->addValue('subject', $meetings['M2']['subject'])
            ->addValue('details', $meetings['M2']['details'])
            ->addValue('source_contact_id', $contact_id)
            ->addValue('status_id:name', 'Completed')
            ->execute()
            ->first();
          $b_id = (int) $created['id'];
          return $b_id;
        }, $meetings['M2']['expect']);
      }
      if ($paths === 'all' && $a_id) {
        $rows[] = $this->path('C', 'CiviCRM API4 update of A (subject only)', function () use ($a_id) {
          Activity::update(FALSE)->addWhere('id', '=', $a_id)->addValue('subject', '[TypeSafe smoke] Renewal conversation (edited via API4)')->execute();
          return $a_id;
        }, []);
      }
      if ($paths === 'all' && $b_id) {
        $rows[] = $this->path('D', 'Drupal entity API update of B (adds note)', function () use ($b_id, $meetings) {
          $activity = $this->storage()->load($b_id);
          $activity->set('field_ce_meeting_note', $meetings['M2']['note']);
          $activity->save();
          return $b_id;
        }, $meetings['M2']['expect']);
      }
    }
    finally {
      $this->recorder->stop();
      if (!$leave_enabled) {
        $this->setAutomators(FALSE);
      }
      $this->accountSwitcher->switchBack();
    }
    return $rows;
  }

  /**
   * Runs one save path and inspects what persisted.
   */
  private function path(string $id, string $label, callable $save, array $expect): array {
    $this->recorder->segment($id);
    $started = microtime(TRUE);
    try {
      $activity_id = $save();
      $this->content->trackActivity($activity_id);
    }
    catch (\Throwable $e) {
      $this->recorder->closeOpen();
      $empty = array_fill_keys(['activity', 'calls', 'http', 'persisted', 'civicrm', 'expectations'], '');
      return [
        'path' => $id,
        'action' => $label,
        'result' => 'FAIL',
        'detail' => get_class($e) . ': ' . $e->getMessage(),
      ] + $empty;
    }
    $this->recorder->closeOpen();
    $snapshot = $this->recorder->snapshot($id);
    $ms = (int) round((microtime(TRUE) - $started) * 1000);

    $this->storage()->resetCache([$activity_id]);
    $activity = $this->storage()->load($activity_id);
    $persisted = [];
    $matched = 0;
    foreach (self::FIELDS as $field) {
      $values = $activity instanceof ContentEntityInterface ? Expectations::values($activity, $field) : [];
      $persisted[] = str_replace('field_tss_mtg_', '', $field) . '=' . Expectations::format($values);
      if (isset($expect[$field]) && Expectations::matches($values, $expect[$field])) {
        $matched++;
      }
    }
    $civi = Activity::get(FALSE)->addWhere('id', '=', $activity_id)->addSelect('subject', 'details', 'activity_type_id:name')->execute()->first();
    $by_automator = [];
    foreach ($snapshot['calls'] as $call) {
      if ($call['operation'] === 'decision') {
        $key = $call['automator'] ? str_replace([self::PREFIX . 'field_tss_mtg_', '.default'], '', $call['automator']) : '?';
        $by_automator[$key] = ($by_automator[$key] ?? 0) + 1;
      }
    }
    $calls = array_sum($by_automator);
    $duplicates = array_filter($by_automator, static fn (int $n): bool => $n > 1);
    $filled = count(array_filter($persisted, static fn (string $p): bool => !str_ends_with($p, '(empty)')));
    $detail = [];
    if ($duplicates) {
      $detail[] = 'duplicate calls: ' . implode(', ', array_map(static fn ($k, $n) => "$k×$n", array_keys($duplicates), $duplicates));
    }
    if ($calls > 0 && $filled === 0) {
      $detail[] = 'automators ran but nothing persisted';
    }
    foreach ($snapshot['logs'] as $log) {
      $detail[] = 'log: ' . mb_strimwidth($log['message'], 0, 100, '…');
    }
    return [
      'path' => $id,
      'action' => $label,
      'result' => 'INFO',
      'activity' => $activity_id,
      'calls' => $calls ? $calls . ' (' . implode(', ', array_map(static fn ($k, $n) => "$k:$n", array_keys($by_automator), $by_automator)) . ')' : '0',
      'http' => count($snapshot['http']),
      'persisted' => implode('; ', $persisted),
      'civicrm' => $civi ? sprintf('type %s; subject "%s"', $civi['activity_type_id:name'], mb_strimwidth((string) $civi['subject'], 0, 40, '…')) : 'missing',
      'expectations' => $expect ? "$matched/" . count($expect) : '-',
      'detail' => ($detail ? implode(' | ', $detail) : '') . " ({$ms} ms)",
    ];
  }

  /**
   * Returns the activity storage.
   */
  private function storage() {
    return $this->entityTypeManager->getStorage('civicrm_activity');
  }

}
