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
 * Each probe reports REPRODUCED when the documented behaviour still occurs,
 * or NOT REPRODUCED (a warning: perhaps fixed upstream) when it does not.
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
      $rejected = array_filter($classification, static fn ($c) => $c['exception'] && str_ends_with($c['exception'], 'AiBadRequestException'));
      $v1 = in_array('AI provider failed to classify text', $violations, TRUE);
      $rows[] = $this->row('V1', 'ai_validations classification rule, na=fail', $v1 && count($rejected) === count($classification) && $classification !== [] && $http === 0,
        sprintf('violations: %s; %d classification call(s), all rejected before HTTP (%d requests): TextClassificationInput is built without labels.', $violations ? implode(' | ', $violations) : 'none', count($classification), $http));
      $v2 = !array_filter($violations, static fn ($v) => str_contains($v, 'TSS-V2'));
      $rows[] = $this->row('V2', 'ai_validations classification rule, na=skip', $v2 && $rejected !== [],
        'The same provider error is swallowed; the rule silently passes, so spam is never caught.');

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

      // V4: categories never match TypeSafe's nested information.
      $this->recorder->segment('V4');
      $response = $this->providers->createInstance('typesafeai')->moderation($probes['abusive'], 'jev-latest', ['typesafe_smoke'])->getNormalized();
      $info = $response->getInformation();
      $category_hits = [];
      foreach (['harassment', 'violence'] as $category) {
        $probability = $info['categories'][$category]['probability'] ?? NULL;
        if (is_numeric($probability) && $probability > 0.5) {
          $category_hits[] = $category . '=' . number_format((float) $probability, 3);
        }
      }
      $violations = $this->validate(['field_tss_mod_cat' => $probes['abusive']]);
      $v4_violation = (bool) array_filter($violations, static fn ($v) => str_contains($v, 'TSS-V4'));
      if (!$category_hits) {
        $rows[] = $this->row('V4', 'ai_validations moderation rule with categories', NULL, 'Inconclusive: TypeSafe did not score harassment or violence above 0.5 for the probe text.');
      }
      else {
        $rows[] = $this->row('V4', 'ai_validations moderation rule with categories', !$v4_violation,
          sprintf('TypeSafe scored %s under information[categories][...][probability], but the rule reads information[category] and %s.', implode(', ', $category_hits), $v4_violation ? 'still raised TSS-V4' : 'raised no violation'));
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
    if ($items === []) {
      return $this->row('V5', 'ai_content_suggestions "Moderate text"', NULL, 'Inconclusive: the text was not flagged, so no list was shown.');
    }
    $expected = ['Threshold', 'Model', 'Categories', 'Max_probability', 'Max_category'];
    return $this->row('V5', 'ai_content_suggestions "Moderate text"', array_intersect($expected, $items) !== [],
      'Editors would see: ' . implode(', ', $items) . ' — information keys, not the violated categories.');
  }

  /**
   * Builds a result row: reproduced, not reproduced, or inconclusive (NULL).
   */
  private function row(string $id, string $probe, ?bool $reproduced, string $detail): array {
    return [
      'id' => $id,
      'probe' => $probe,
      'result' => match ($reproduced) {
        TRUE => 'REPRODUCED',
        FALSE => 'NOT REPRODUCED',
        NULL => 'WARN',
      },
      'detail' => $detail,
    ];
  }

}
