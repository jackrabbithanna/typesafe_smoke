<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\ai\AiProviderPluginManager;

/**
 * Reproduces known behaviour of contrib modules with TypeSafe.
 *
 * Each probe reports REPRODUCED when the documented problem still occurs,
 * FIXED (a pass) when the fixed behaviour is observed, or WARN when the
 * result is inconclusive, for example because of a model judgement.
 */
final class ContribProbe {

  /**
   * Constructs the probe.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiProviderPluginManager $providers,
    private readonly PluginManagerInterface $suggestions,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FixtureRepository $fixtures,
    private readonly CallRecorder $recorder,
  ) {}

  /**
   * Runs all probes.
   *
   * @return array[]
   *   Result rows.
   */
  public function run(): array {
    $probes = $this->fixtures->probes();
    $this->recorder->start('contrib-' . date('Ymd-His'));
    try {
      $rows = [];

      // V1 and V2 share one field; one validate() runs both rules.
      $this->recorder->segment('V1-V2');
      $violations = $this->validate(['field_tss_classify' => $probes['classify']]);
      $calls = $this->recorder->snapshot('V1-V2')['calls'];
      $http = count($this->recorder->snapshot('V1-V2')['http']);
      $classification = array_values(array_filter($calls, static fn ($c) => $c['operation'] === 'text_classification'));
      $rejected = array_filter($classification, static fn ($c) => (bool) $c['exception']);
      $logs = $this->validationLogs('V1-V2');
      $failed = in_array('AI provider failed to classify text', $violations, TRUE);
      $v1_fired = (bool) array_filter($violations, static fn ($v) => str_starts_with($v, 'TSS-V1'));
      $v2_fired = (bool) array_filter($violations, static fn ($v) => str_starts_with($v, 'TSS-V2'));
      $summary = sprintf('%d classification call(s), %d rejected, %d HTTP request(s); violations: %s.', count($classification), count($rejected), $http, $violations ? '[' . implode(' | ', $violations) . ']' : 'none');
      if ($failed || $rejected) {
        $rows[] = $this->row('V1', 'ai_validations classification rule, na=fail', TRUE, $summary . ' The rule sends no candidate labels (add "labels" to the rule).' . $logs);
      }
      elseif ($classification !== [] && $http > 0) {
        $rows[] = $this->row('V1', 'ai_validations classification rule, na=fail', FALSE, $summary . ($v1_fired ? ' Labels reach TypeSafe and the spam text is rejected.' : ' Labels reach TypeSafe, but the model did not classify the text as spam.'));
      }
      else {
        $rows[] = $this->row('V1', 'ai_validations classification rule, na=fail', NULL, $summary);
      }
      if ($rejected && !$v2_fired) {
        $rows[] = $this->row('V2', 'ai_validations classification rule, na=skip', TRUE, 'The provider error is skipped; the rule silently passes, so spam is never caught.');
      }
      elseif ($v2_fired) {
        $rows[] = $this->row('V2', 'ai_validations classification rule, na=skip', FALSE, 'TSS-V2 rejects the spam text.');
      }
      else {
        $rows[] = $this->row('V2', 'ai_validations classification rule, na=skip', NULL, 'No provider error, but the model did not classify the text as spam (model judgement).');
      }

      // V3: moderation without categories adds a violation when flagged.
      $this->recorder->segment('V3');
      $violations = $this->validate(['field_tss_mod_any' => $probes['abusive']]);
      $flagged = array_filter($violations, static fn ($v) => str_contains($v, 'TSS-V3'));
      $rows[] = [
        'id' => 'V3',
        'probe' => 'ai_validations moderation rule, no categories (control)',
        'result' => $flagged ? 'WORKS' : 'WARN',
        'detail' => $flagged ? 'Abusive text rejected with TSS-V3.' : 'Not rejected: TypeSafe did not flag the text (model judgement).',
      ];

      // V4: the rule reads information[category]; TypeSafe's nested
      // information has no such keys until it returns a flat map.
      $this->recorder->segment('V4');
      $response = $this->providers->createInstance('typesafeai')->moderation($probes['abusive'], 'jev-latest', ['typesafe_smoke'])->getNormalized();
      $info = $response->getInformation();
      $nested = isset($info['categories']) && is_array($info['categories']);
      $category_hits = [];
      foreach (['harassment', 'violence'] as $category) {
        $probability = $nested ? ($info['categories'][$category]['probability'] ?? NULL) : ($info[$category] ?? NULL);
        if (is_numeric($probability) && $probability > 0.5) {
          $category_hits[] = $category . '=' . number_format((float) $probability, 3);
        }
      }
      $violations = $this->validate(['field_tss_mod_cat' => $probes['abusive']]);
      $v4_violation = (bool) array_filter($violations, static fn ($v) => str_contains($v, 'TSS-V4'));
      $logs = $this->validationLogs('V4');
      if (!$category_hits) {
        $rows[] = $this->row('V4', 'ai_validations moderation rule with categories', NULL, 'Inconclusive: TypeSafe did not score harassment or violence above 0.5 for the probe text.');
      }
      elseif ($v4_violation) {
        $rows[] = $this->row('V4', 'ai_validations moderation rule with categories', FALSE, sprintf('TypeSafe scored %s and TSS-V4 rejects the text.', implode(', ', $category_hits)));
      }
      else {
        $rows[] = $this->row('V4', 'ai_validations moderation rule with categories', TRUE,
          sprintf('TypeSafe scored %s under %s, but the rule reads information[category] and raised no violation.%s', implode(', ', $category_hits), $nested ? 'information[categories][...][probability]' : 'information[category]', $logs));
      }

      // V5: the content suggestions "Moderate text" plugin lists info keys.
      $this->recorder->segment('V5');
      $rows[] = $this->moderateSuggestion($probes['abusive']);
      return $rows;
    }
    finally {
      $this->recorder->stop();
    }
  }

  /**
   * Validates an unsaved probe node and returns violation messages.
   */
  private function validate(array $values): array {
    $values += ['type' => 'typesafe_smoke_probe', 'title' => 'TypeSafe smoke contrib probe'];
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $messages = [];
    foreach ($node->validate() as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * Runs the Moderate text suggestion plugin like the node form would.
   */
  private function moderateSuggestion(string $text): array {
    $config = $this->configFactory->get('ai_content_suggestions.settings')->get('plugins.moderate') ?? [];
    $plugin = $this->suggestions->createInstance('moderate', $config);
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'moderate' => ['target_fields' => ['field_tss_mod_any']],
      'field_tss_mod_any' => [['value' => $text]],
    ]);
    $plugin->updateFormWithResponse($form, $form_state);
    $response = $form['moderate']['response']['response']['#context']['response'] ?? [];
    if (isset($response['error'])) {
      return $this->row('V5', 'ai_content_suggestions "Moderate text"', NULL, 'The plugin reported a provider error.');
    }
    $items = array_map('strval', $response['results']['#items'] ?? []);
    $message = isset($response['message']['#value']) ? ' Message: ' . strip_tags((string) $response['message']['#value']) : '';
    if ($items === []) {
      return $this->row('V5', 'ai_content_suggestions "Moderate text"', NULL, 'Inconclusive: no categories were listed.' . $message);
    }
    // Scored items read "Name (0.97)"; compare the names only.
    $names = array_map(static fn (string $item): string => preg_replace('/ \(\d+(\.\d+)?\)$/', '', $item), $items);
    $metadata = ['Threshold', 'Model', 'Categories', 'Max_probability', 'Max_category', 'Usage'];
    if (array_intersect($metadata, $names) !== []) {
      return $this->row('V5', 'ai_content_suggestions "Moderate text"', TRUE,
        'Editors would see: ' . implode(', ', $items) . ' — keys of TypeSafe\'s nested information, not category names.' . $message);
    }
    return $this->row('V5', 'ai_content_suggestions "Moderate text"', FALSE, 'Editors see the violated categories: ' . implode(', ', $items) . '.' . $message);
  }

  /**
   * Returns the ai_validations log messages recorded in a segment.
   */
  private function validationLogs(string $segment): string {
    $messages = array_column($this->recorder->snapshot($segment)['validation_logs'], 'message');
    return $messages === [] ? '' : ' Logged: ' . implode(' | ', array_unique($messages));
  }

  /**
   * Builds a result row: reproduced, fixed, or inconclusive (NULL).
   */
  private function row(string $id, string $probe, ?bool $reproduced, string $detail): array {
    return [
      'id' => $id,
      'probe' => $probe,
      'result' => match ($reproduced) {
        TRUE => 'REPRODUCED',
        FALSE => 'FIXED',
        NULL => 'WARN',
      },
      'detail' => $detail,
    ];
  }

}
