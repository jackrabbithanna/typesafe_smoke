<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\ai\AiProviderPluginManager;
use Drupal\key\KeyRepositoryInterface;
use Drupal\typesafe_smoke\Battery;
use Drupal\typesafe_smoke\CivicrmProbe;
use Drupal\typesafe_smoke\ContribProbe;
use Drupal\typesafe_smoke\SmokeContentManager;
use Drupal\typesafe_smoke\Tickets;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Live smoke tests for the TypeSafe AI provider.
 *
 * Every command except status and reset makes real, billed API calls.
 */
final class TypeSafeSmokeCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs the commands.
   */
  public function __construct(
    #[Autowire(service: 'ai.provider')]
    private readonly AiProviderPluginManager $providers,
    #[Autowire(service: 'config.factory')]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $moduleList,
    #[Autowire(service: 'key.repository')]
    private readonly KeyRepositoryInterface $keys,
    private readonly Battery $battery,
    private readonly Tickets $tickets,
    private readonly CivicrmProbe $civicrm,
    private readonly ContribProbe $contrib,
    private readonly SmokeContentManager $content,
  ) {
    parent::__construct();
  }

  /**
   * Checks the setup without calling the API.
   */
  #[CLI\Command(name: 'typesafe-smoke:status', aliases: ['tss:status'])]
  #[CLI\Option(name: 'format', description: 'table or json.')]
  #[CLI\Usage(name: 'drush typesafe-smoke:status', description: 'Checks provider, key, configuration and tracked content.')]
  public function status(array $options = ['format' => 'table']): int {
    $rows = $this->statusRows();
    $this->printRows(['check', 'result', 'detail'], $rows, $options['format']);
    return $this->hasResult($rows, 'FAIL') ? self::EXIT_FAILURE_WITH_CLARITY : self::EXIT_SUCCESS;
  }

  /**
   * Runs live checks of the TypeSafe operations through the AI proxy.
   */
  #[CLI\Command(name: 'typesafe-smoke:battery', aliases: ['tss:battery'])]
  #[CLI\Option(name: 'model', description: 'Model ID to test.')]
  #[CLI\Option(name: 'only', description: 'Comma-separated check IDs or groups (C, B, G, T, M).')]
  #[CLI\Option(name: 'skip-large', description: 'Skip the 255-option and 10-level checks.')]
  #[CLI\Option(name: 'strict', description: 'Treat soft (WARN) results as failures.')]
  #[CLI\Option(name: 'list', description: 'List the checks without running them.')]
  #[CLI\Option(name: 'format', description: 'table or json.')]
  #[CLI\Usage(name: 'drush typesafe-smoke:battery --only=B,G', description: 'Runs the Decision and guardrail checks.')]
  public function battery(
    array $options = [
      'model' => 'jev-latest',
      'only' => '',
      'skip-large' => FALSE,
      'strict' => FALSE,
      'list' => FALSE,
      'format' => 'table',
    ],
  ): int {
    if ($options['list']) {
      $this->printRows(['id', 'check'], array_map(static fn ($id, $label) => ['id' => $id, 'check' => $label], array_keys($this->battery->checks()), $this->battery->checks()), $options['format']);
      return self::EXIT_SUCCESS;
    }
    if (!$this->preflight()) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    \Drupal::service('typesafe_smoke.call_recorder')->start('battery-' . date('Ymd-His'));
    try {
      $rows = $this->battery->run($options['model'], $this->csv($options['only']), (bool) $options['skip-large']);
    }
    finally {
      \Drupal::service('typesafe_smoke.call_recorder')->stop();
    }
    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    else {
      $this->printRows(['id', 'check', 'result', 'ms', 'http', 'tokens', 'detail'], $rows, 'table');
      $this->printSummary($rows);
    }
    return $this->exitCode($rows, (bool) $options['strict']);
  }

  /**
   * Creates the sample tickets; their automators call TypeSafe on save.
   */
  #[CLI\Command(name: 'typesafe-smoke:seed', aliases: ['tss:seed'])]
  #[CLI\Option(name: 'only', description: 'Comma-separated ticket IDs, e.g. T01,T14.')]
  #[CLI\Option(name: 'force', description: 'Delete and recreate tickets that already exist.')]
  #[CLI\Option(name: 'dry-run', description: 'Show the tickets and estimated API calls only.')]
  #[CLI\Option(name: 'sleep-ms', description: 'Pause between tickets.')]
  #[CLI\Usage(name: 'drush typesafe-smoke:seed --dry-run', description: 'Estimates the API calls.')]
  public function seed(array $options = ['only' => '', 'force' => FALSE, 'dry-run' => FALSE, 'sleep-ms' => 0]): int {
    $only = $this->csv($options['only']);
    $estimate = $this->tickets->estimate($only);
    $this->io()->note(sprintf('%d ticket(s); about %d TypeSafe API calls (one per base-mode automator, plus token mode and moderation).', $estimate['tickets'], $estimate['calls']));
    if ($options['dry-run']) {
      return self::EXIT_SUCCESS;
    }
    if (!$this->preflight()) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    $rows = $this->tickets->seed($only, (bool) $options['force'], (int) $options['sleep-ms']);
    $this->printRows(['ticket', 'result', 'nid', 'calls', 'http', 'ms', 'detail'], $rows, 'table');
    $this->io()->text('Next: drush typesafe-smoke:report');
    return $this->hasResult($rows, 'FAIL') ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Compares automator results with the fixture expectations.
   */
  #[CLI\Command(name: 'typesafe-smoke:report', aliases: ['tss:report'])]
  #[CLI\Option(name: 'only', description: 'Comma-separated ticket IDs.')]
  #[CLI\Option(name: 'only-mismatches', description: 'Hide rows that matched.')]
  #[CLI\Option(name: 'strict', description: 'Treat soft misses as failures.')]
  #[CLI\Option(name: 'format', description: 'table or json.')]
  public function report(
    array $options = [
      'only' => '',
      'only-mismatches' => FALSE,
      'strict' => FALSE,
      'format' => 'table',
    ],
  ): int {
    $report = $this->tickets->report($this->csv($options['only']));
    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    else {
      $rows = $options['only-mismatches'] ? array_filter($report['rows'], static fn ($row) => $row['result'] !== 'OK') : $report['rows'];
      $this->printRows(['ticket', 'field', 'kind', 'expected', 'actual', 'result'], array_values($rows), 'table');
      if ($report['blocks']) {
        $this->io()->section('Guardrail blocks');
        $this->printRows(['ticket', 'automator', 'code'], $report['blocks'], 'table');
      }
      $summary = $report['summary'];
      $this->io()->section('Summary');
      $this->io()->definitionList(
        ['Soft agreement' => sprintf('%d/%d (%s%%)', $summary['soft_ok'], $summary['soft_total'], $summary['soft_percent'] ?? '-')],
        ['By field' => implode(', ', array_map(static fn ($f, $v) => str_replace('field_tss_', '', $f) . " $v", array_keys($summary['soft_by_field']), $summary['soft_by_field']))],
        ['Hard failures' => (string) $summary['hard_failures']],
        ['Decision calls' => sprintf('%d ok; latency p50 %s ms, p95 %s ms', $summary['decision_calls_ok'], $summary['latency_p50_ms'] ?? '-', $summary['latency_p95_ms'] ?? '-')],
        ['Tokens' => sprintf('%d in / %d out', $summary['tokens_in'], $summary['tokens_out'])],
      );
    }
    $failed = $report['summary']['hard_failures'] > 0 || ($options['strict'] && $report['summary']['soft_ok'] < $report['summary']['soft_total']);
    return $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Runs the CiviCRM Meeting automators through both save paths.
   */
  #[CLI\Command(name: 'typesafe-smoke:civicrm', aliases: ['tss:civicrm'])]
  #[CLI\Option(name: 'path', description: 'all, drupal or api4.')]
  #[CLI\Option(name: 'leave-enabled', description: 'Leave the meeting automators enabled for a manual CiviCRM UI test. Every Meeting save then calls TypeSafe.')]
  #[CLI\Option(name: 'disable', description: 'Only disable the meeting automators.')]
  #[CLI\Option(name: 'cleanup', description: 'Only delete the tracked smoke activities and contact.')]
  #[CLI\Option(name: 'format', description: 'table or json.')]
  public function civicrm(
    array $options = [
      'path' => 'all',
      'leave-enabled' => FALSE,
      'disable' => FALSE,
      'cleanup' => FALSE,
      'format' => 'table',
    ],
  ): int {
    if ($options['disable']) {
      $this->io()->success($this->civicrm->setAutomators(FALSE) . ' meeting automator(s) disabled.');
      return self::EXIT_SUCCESS;
    }
    if ($options['cleanup']) {
      $problems = $this->content->deleteCivicrm();
      foreach ($problems as $problem) {
        $this->io()->warning($problem);
      }
      return $problems ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
    }
    if (!in_array($options['path'], ['all', 'drupal', 'api4'], TRUE)) {
      throw new \InvalidArgumentException('--path must be all, drupal or api4.');
    }
    if (!$this->preflight()) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    $rows = $this->civicrm->run($options['path'], (bool) $options['leave-enabled']);
    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    else {
      foreach ($rows as $row) {
        $this->io()->section("Path {$row['path']}: {$row['action']}");
        $this->io()->definitionList(...array_map(static fn ($k, $v) => [$k => (string) $v], array_keys($row), $row));
      }
    }
    if ($options['leave-enabled']) {
      $this->io()->warning('The meeting automators are still enabled: every Meeting activity save on this site now calls TypeSafe. Run drush typesafe-smoke:civicrm --disable when done.');
    }
    return $this->hasResult($rows, 'FAIL') ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Reproduces known contrib module behaviour with TypeSafe.
   */
  #[CLI\Command(name: 'typesafe-smoke:contrib', aliases: ['tss:contrib'])]
  #[CLI\Option(name: 'format', description: 'table or json.')]
  public function contrib(array $options = ['format' => 'table']): int {
    if (!$this->preflight()) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    $rows = $this->contrib->run();
    $this->printRows(['id', 'probe', 'result', 'detail'], $rows, $options['format']);
    return self::EXIT_SUCCESS;
  }

  /**
   * Deletes smoke content.
   */
  #[CLI\Command(name: 'typesafe-smoke:reset', aliases: ['tss:reset'])]
  #[CLI\Option(name: 'nodes', description: 'Delete smoke tickets and probe nodes.')]
  #[CLI\Option(name: 'civicrm', description: 'Delete tracked CiviCRM activities and the smoke contact.')]
  #[CLI\Option(name: 'logs', description: 'Delete AI log entries tagged typesafe_smoke.')]
  #[CLI\Option(name: 'all', description: 'All of the above.')]
  public function reset(array $options = ['nodes' => FALSE, 'civicrm' => FALSE, 'logs' => FALSE, 'all' => FALSE]): int {
    $all = (bool) $options['all'];
    if (!$all && !$options['nodes'] && !$options['civicrm'] && !$options['logs']) {
      $this->io()->warning('Nothing selected. Use --nodes, --civicrm, --logs or --all.');
      return self::EXIT_SUCCESS;
    }
    if ($all || $options['nodes']) {
      $this->io()->text($this->content->deleteNodes() . ' node(s) deleted.');
    }
    if ($all || $options['civicrm']) {
      foreach ($this->content->deleteCivicrm() as $problem) {
        $this->io()->warning($problem);
      }
      $this->io()->text('Tracked CiviCRM data deleted.');
    }
    if ($all || $options['logs']) {
      $this->io()->text($this->deleteLogs() . ' AI log entr(ies) deleted.');
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Builds the status rows.
   */
  private function statusRows(): array {
    $rows = [];
    $add = static function (string $check, string $result, string $detail) use (&$rows): void {
      $rows[] = ['check' => $check, 'result' => $result, 'detail' => $detail];
    };

    $readonly = Settings::get('config_readonly', FALSE);
    $add('Config writable', $readonly ? 'FAIL' : 'PASS', $readonly ? 'config_readonly is on; install, uninstall and the CiviCRM automator toggle will fail.' : 'config_readonly is off.');

    foreach (['ai_logging', 'field_widget_actions', 'ai_validations', 'ai_content_suggestions'] as $module) {
      $path = $this->moduleList->getPath($module);
      $add("Module path: $module", str_contains($path, 'contrib/ai/modules/') ? 'FAIL' : 'PASS', $path);
    }

    $provider = $this->providers->createInstance(Battery::PROVIDER);
    foreach (['decision' => TRUE, 'text_classification' => TRUE, 'moderation' => TRUE, 'chat' => FALSE] as $operation => $expected) {
      $usable = $provider->isUsable($operation);
      $add("typesafeai usable for $operation", $usable === $expected ? 'PASS' : 'FAIL', $usable ? 'usable' : 'not usable');
    }

    $key_id = (string) $this->configFactory->get('ai_provider_typesafeai.settings')->get('api_key');
    $key = $key_id !== '' ? $this->keys->getKey($key_id) : NULL;
    $length = $key ? strlen((string) $key->getKeyValue()) : 0;
    $add('API key', $length > 0 ? 'PASS' : 'FAIL', $key ? "Key entity \"$key_id\" holds a $length-character value." : 'No key configured.');

    $defaults = $this->configFactory->get('ai.settings')->get('default_providers') ?? [];
    foreach (['decision', 'text_classification', 'moderation'] as $operation) {
      $default = $defaults[$operation] ?? [];
      $add("Default provider: $operation", ($default['provider_id'] ?? '') === Battery::PROVIDER ? 'PASS' : 'WARN', ($default['provider_id'] ?? 'none') . ' / ' . ($default['model_id'] ?? 'none'));
    }

    $global = $this->configFactory->get('ai.settings')->get('global_guardrails') ?? [];
    $add('No global guardrails', $global ? 'WARN' : 'PASS', $global ? 'Global sets apply to every call: ' . implode(', ', (array) $global) : 'None.');
    $self = array_filter($this->configFactory->get('ai.external_moderation')->get('moderations') ?? [], static fn ($m) => ($m['provider'] ?? '') === Battery::PROVIDER);
    $add('No external moderation of typesafeai', $self ? 'FAIL' : 'PASS', $self ? 'typesafeai requests are moderated externally; if TypeSafe moderates itself this recurses.' : 'None.');

    $automators = array_filter(array_keys($this->entityTypeManager->getStorage('ai_automator')->loadMultiple()), static fn ($id) => str_starts_with($id, 'node.typesafe_smoke_ticket.') || str_starts_with($id, CivicrmProbe::PREFIX . 'field_tss_mtg_'));
    $guardrails = array_filter(array_keys($this->entityTypeManager->getStorage('ai_guardrail')->loadMultiple()), static fn ($id) => str_starts_with($id, 'typesafe_smoke_'));
    $sets = array_filter(array_keys($this->entityTypeManager->getStorage('ai_guardrail_set')->loadMultiple()), static fn ($id) => str_starts_with($id, 'typesafe_smoke_'));
    $counts = sprintf('%d automators, %d guardrails, %d guardrail sets', count($automators), count($guardrails), count($sets));
    $add('Smoke configuration', [count($automators), count($guardrails), count($sets)] === [20, 5, 4] ? 'PASS' : 'FAIL', $counts . ' (expected 20/5/4)');

    $meeting_automators = $this->entityTypeManager->getStorage('ai_automator')->loadByProperties([
      'entity_type' => 'civicrm_activity',
      'bundle' => 'meeting',
      'status' => TRUE,
    ]);
    $enabled = array_filter($meeting_automators, static fn ($a) => str_starts_with((string) $a->id(), CivicrmProbe::PREFIX . 'field_tss_mtg_'));
    $add('CiviCRM meeting automators disabled', $enabled ? 'WARN' : 'PASS', $enabled ? count($enabled) . ' enabled: every Meeting save calls TypeSafe. Run --disable.' : 'Disabled until typesafe-smoke:civicrm runs.');

    $field_manager = \Drupal::service('entity_field.manager');
    $status_fields = [];
    foreach ([['node', 'typesafe_smoke_ticket'], ['civicrm_activity', 'meeting']] as [$entity_type, $bundle]) {
      if (isset($field_manager->getFieldDefinitions($entity_type, $bundle)['ai_automator_status'])) {
        $status_fields[] = "$entity_type.$bundle";
      }
    }
    $add('No automator status field', $status_fields ? 'WARN' : 'PASS', $status_fields ? 'Present on ' . implode(', ', $status_fields) . ' (added when a field on the bundle is saved in the UI).' : 'None.');

    $logging = $this->configFactory->get('ai_logging.settings');
    $add('AI logging', $logging->get('prompt_logging') ? 'PASS' : 'WARN', $logging->get('prompt_logging') ? 'Prompts and responses are logged at /admin/config/ai/logging/collection.' : 'Prompt logging is off.');

    $tracked = $this->content->tracked();
    $add('Tracked content', 'INFO', sprintf('%d ticket(s), %d CiviCRM activit(ies), contact %s', count($tracked['nodes']), count($tracked['civicrm_activity']), $tracked['civicrm_contact'] ?? 'none'));
    return $rows;
  }

  /**
   * Runs the status checks that must pass before spending API calls.
   */
  private function preflight(): bool {
    $failures = array_filter($this->statusRows(), static fn ($row) => $row['result'] === 'FAIL');
    if ($failures) {
      $this->io()->error('Preflight failed; no API calls were made.');
      $this->printRows(['check', 'result', 'detail'], array_values($failures), 'table');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Deletes AI log entries created by smoke runs.
   */
  private function deleteLogs(): int {
    if (!$this->entityTypeManager->hasDefinition('ai_log')) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('ai_log');
    $deleted = 0;
    // Recorded runs add the typesafe_smoke tag; ticket automators also carry
    // their bundle tag when they run outside a recorded command (for example
    // from the node form).
    foreach (['typesafe_smoke', 'ai_automator:bundle:typesafe_smoke_ticket'] as $tag) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('tags', $tag)->execute();
      if ($ids) {
        $storage->delete($storage->loadMultiple($ids));
        $deleted += count($ids);
      }
    }
    return $deleted;
  }

  /**
   * Prints rows as a table or JSON.
   */
  private function printRows(array $columns, array $rows, string $format): void {
    if ($format === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      return;
    }
    $table = [];
    foreach ($rows as $row) {
      $table[] = array_map(static fn ($column) => is_scalar($row[$column] ?? '') ? (string) ($row[$column] ?? '') : json_encode($row[$column]), $columns);
    }
    $this->io()->table($columns, $table);
  }

  /**
   * Prints battery totals.
   */
  private function printSummary(array $rows): void {
    $counts = array_count_values(array_column($rows, 'result'));
    $ms = array_column($rows, 'ms');
    $this->io()->text(sprintf('%s | %d HTTP requests | %d ms total', implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($counts), $counts)), array_sum(array_column($rows, 'http')), array_sum($ms)));
  }

  /**
   * Whether any row has the given result.
   */
  private function hasResult(array $rows, string $result): bool {
    return in_array($result, array_column($rows, 'result'), TRUE);
  }

  /**
   * Returns the exit code for result rows.
   */
  private function exitCode(array $rows, bool $strict): int {
    return $this->hasResult($rows, 'FAIL') || ($strict && $this->hasResult($rows, 'WARN')) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Splits a comma-separated option.
   */
  private function csv(string $value): array {
    return array_values(array_filter(array_map('trim', explode(',', $value))));
  }

}
