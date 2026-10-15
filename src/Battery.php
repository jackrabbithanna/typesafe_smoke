<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiExceptionInterface;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\Entity\AiGuardrailModeEnum;
use Drupal\ai\Guardrail\AiGuardrailHelper;
use Drupal\ai\OperationType\TextClassification\TextClassificationInput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\ai\OperationType\Decision\DecisionRequestValidator;
use Drupal\ai\Exception\AiDecisionBlockedException;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionResponse;
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;
use Drupal\ai\OperationType\Decision\Value\AnswerValidation;
use Drupal\ai\OperationType\Decision\Value\BoxAnswer;
use Drupal\ai\OperationType\Decision\Value\ChoiceAnswer;
use Drupal\ai\OperationType\Decision\Value\ScoreAnswer;
use Drupal\ai\OperationType\Decision\Value\ScoreQuestion;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\key\KeyRepositoryInterface;

/**
 * Live checks of the TypeSafe provider through the AI provider proxy.
 *
 * Each check returns PASS, WARN (a soft, model-judgement expectation was not
 * met), FAIL (a hard expectation failed) or SKIP. HTTP counts come from the
 * HTTP client middleware, so "no HTTP" results are observed, not inferred.
 */
final class Battery {

  /**
   * The provider plugin ID under test.
   */
  public const PROVIDER = 'typesafeai';

  /**
   * The model under test.
   */
  private string $model = 'jev-latest';

  /**
   * The check being run.
   */
  private string $current = '';

  /**
   * Constructs the battery.
   */
  public function __construct(
    private readonly AiProviderPluginManager $providers,
    private readonly CallRecorder $recorder,
    private readonly AiGuardrailHelper $guardrails,
    private readonly FixtureRepository $fixtures,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Lists check IDs and labels, grouped by prefix.
   */
  public function checks(): array {
    return [
      'C01' => 'Capabilities declared locally (no HTTP)',
      'C02' => 'Model discovery',
      'B01' => 'Noul (true/false), positive case',
      'B02' => 'Noul (true/false), negative case',
      'B03' => 'Choice with descriptions over structured state',
      'B04' => 'Choice built with fromOptions()',
      'B05' => 'Score, three levels',
      'B06' => 'Structured instructions, criteria, descriptions and levels',
      'B07' => 'Three question types in one request',
      'B08' => 'Same request on jev-preview',
      'B09' => '255 choice options accepted',
      'B10' => '256 choice options rejected before HTTP',
      'B11' => '10 score levels accepted',
      'B12' => '11 score levels rejected before HTTP',
      'B13' => 'Request without questions rejected before HTTP',
      'B14' => 'Unknown model ID',
      'B15' => 'Invalid API key',
      'B16' => 'Unsupported image file rejected before HTTP',
      'B17' => 'API key with surrounding whitespace still authenticates',
      'B18' => 'Malformed API key rejected before HTTP without echoing it',
      'B19' => 'TypeSafe request ID in the output metadata',
      'G01' => 'Guardrail: card number blocks before HTTP',
      'G02' => 'Guardrail: length limit blocks before HTTP',
      'G03' => 'Guardrail: TypeSafe moderation blocks abusive state',
      'G04' => 'Guardrail: post-phase block on answer text after the call',
      'G05' => 'Guardrail: unsupported plugin is a setup failure',
      'G06' => 'Guardrail: TypeSafe moderation covers question text',
      'G07' => 'Guardrail: moderation stops a request with images before HTTP',
      'T01' => 'Text classification with labels',
      'T02' => 'Text classification without labels rejected before HTTP',
      'T03' => 'Text classification with custom instructions',
      'T04' => 'Text classification removes duplicate and blank labels',
      'M01' => 'Moderation, benign text',
      'M02' => 'Moderation, abusive text',
      'I01' => 'Images: GIF and 1x1 PNG rejected before HTTP',
      'I02' => 'Images: noul about a red rectangle (multipart, images header)',
      'I03' => 'Images: file order kept (red PNG, then blue JPEG)',
      'I04' => 'Box: red rectangle located on jev-preview',
      'I05' => 'Box: rejected before HTTP on jev-latest',
      'I06' => 'Images: smallest and largest accepted sizes, and a progressive JPEG',
      'I07' => 'Images: object and list states keep their paths next to images',
      'I08' => 'Box: each question searches the image it names',
      'I09' => 'Box: absent target, with a presence noul',
      'L01' => 'Long context header on a small request',
      'L02' => 'Long state without long context: rejected with the long_context hint',
      'L03' => 'Long state with long context: answered',
    ];
  }

  /**
   * Runs the selected checks.
   *
   * @param string $model
   *   The model to test.
   * @param string|string[] $only
   *   Check IDs or prefixes (C, B, G, T, M) to run; empty for all.
   * @param bool $skip_large
   *   Skip the 255-option and 10-level acceptance checks.
   *
   * @return array[]
   *   Result rows.
   */
  public function run(string $model, string|array $only = [], bool $skip_large = FALSE): array {
    $checks = $this->select($only, $skip_large);
    $this->model = $model;
    $rows = [];
    foreach ($checks as $id => $label) {
      if ($skip_large && in_array($id, BatterySelection::LARGE, TRUE)) {
        $rows[] = $this->row($id, $label, 'SKIP', 'Skipped with --skip-large.', 0);
        continue;
      }
      $rows[] = $this->runCheck($id, $label, fn (): array => $this->dispatch($id));
    }
    return $rows;
  }

  /**
   * Validates selectors before command preflight or recording starts.
   */
  public function select(string|array $only = [], bool $skip_large = FALSE): array {
    return BatterySelection::resolve($this->checks(), $only, $skip_large);
  }

  /**
   * Runs one check by ID.
   */
  private function dispatch(string $id): array {
    return match ($id) {
      'C01' => $this->checkC01(),
      'C02' => $this->checkC02(),
      'B01' => $this->checkB01(),
      'B02' => $this->checkB02(),
      'B03' => $this->checkB03(),
      'B04' => $this->checkB04(),
      'B05' => $this->checkB05(),
      'B06' => $this->checkB06(),
      'B07' => $this->checkB07(),
      'B08' => $this->checkB08(),
      'B09' => $this->checkB09(),
      'B10' => $this->checkB10(),
      'B11' => $this->checkB11(),
      'B12' => $this->checkB12(),
      'B13' => $this->checkB13(),
      'B14' => $this->checkB14(),
      'B15' => $this->checkB15(),
      'B16' => $this->checkB16(),
      'B17' => $this->checkB17(),
      'B18' => $this->checkB18(),
      'B19' => $this->checkB19(),
      'G01' => $this->checkG01(),
      'G02' => $this->checkG02(),
      'G03' => $this->checkG03(),
      'G04' => $this->checkG04(),
      'G05' => $this->checkG05(),
      'G06' => $this->checkG06(),
      'G07' => $this->checkG07(),
      'T01' => $this->checkT01(),
      'T02' => $this->checkT02(),
      'T03' => $this->checkT03(),
      'T04' => $this->checkT04(),
      'M01' => $this->checkM01(),
      'M02' => $this->checkM02(),
      'I01' => $this->checkI01(),
      'I02' => $this->checkI02(),
      'I03' => $this->checkI03(),
      'I04' => $this->checkI04(),
      'I05' => $this->checkI05(),
      'I06' => $this->checkI06(),
      'I07' => $this->checkI07(),
      'I08' => $this->checkI08(),
      'I09' => $this->checkI09(),
      'L01' => $this->checkL01(),
      'L02' => $this->checkL02(),
      'L03' => $this->checkL03(),
    };
  }

  /**
   * Runs one check inside its own recorder segment.
   */
  private function runCheck(string $id, string $label, callable $check): array {
    $this->current = $id;
    $this->recorder->segment($id);
    $started = microtime(TRUE);
    try {
      [$result, $detail] = $check();
    }
    catch (\Throwable $e) {
      $result = 'FAIL';
      $detail = 'Unexpected ' . (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
    }
    $this->recorder->closeOpen();
    return $this->row($id, $label, $result, $detail, (int) round((microtime(TRUE) - $started) * 1000));
  }

  /**
   * Builds a result row with HTTP and token totals for the segment.
   */
  private function row(string $id, string $label, string $result, string $detail, int $ms): array {
    $snapshot = $this->recorder->snapshot($id);
    $tokens_in = array_sum(array_map(static fn (array $call): int => (int) $call['tokens_in'], $snapshot['calls']));
    $tokens_out = array_sum(array_map(static fn (array $call): int => (int) $call['tokens_out'], $snapshot['calls']));
    return [
      'id' => $id,
      'check' => $label,
      'result' => $result,
      'ms' => $ms,
      'http' => count($snapshot['http']),
      'tokens' => $tokens_in || $tokens_out ? "$tokens_in/$tokens_out" : '',
      'detail' => $detail,
      'calls' => $snapshot['calls'],
      'requests' => $snapshot['http'],
    ];
  }

  /**
   * Returns a fresh provider proxy.
   */
  private function provider(): ProviderProxy {
    return $this->providers->createInstance(self::PROVIDER);
  }

  /**
   * Counts HTTP requests in the current segment.
   */
  private function http(): int {
    return count($this->recorder->snapshot($this->current)['http']);
  }

  /**
   * Counts HTTP requests recorded after a known total.
   */
  private function httpSince(int $before): int {
    return count($this->recorder->snapshot()['http']) - $before;
  }

  /**
   * Runs a decision through the proxy.
   */
  private function decide(DecisionInput $input, ?string $model = NULL, ?ProviderProxy $provider = NULL): DecisionResponse {
    return ($provider ?? $this->provider())->decision($input, $model ?? $this->model, ['typesafe_smoke'])->getNormalized();
  }

  /**
   * Expects an exception before any HTTP request.
   */
  private function expectRejected(callable $call, string $class): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $call();
      return ['FAIL', 'No exception; the request was accepted.'];
    }
    catch (\Throwable $e) {
      $http = $this->httpSince($before);
      $short = (new \ReflectionClass($e))->getShortName();
      if (!$e instanceof $class) {
        return ['FAIL', "Expected $class, got $short: " . $e->getMessage()];
      }
      if ($http !== 0) {
        return ['FAIL', "$short thrown, but only after $http HTTP request(s)."];
      }
      return ['PASS', "$short before HTTP: " . $e->getMessage()];
    }
  }

  /**
   * Returns a result pair: status and detail.
   */
  private function result(string $status, string $detail): array {
    return [$status, $detail];
  }

  /**
   * Checks normalization using the precision declared on the typed answer.
   */
  private function validDistribution(ChoiceAnswer|ScoreAnswer|array $answer): bool {
    try {
      AnswerValidation::distribution(
        is_array($answer) ? $answer : $answer->getProbabilities(),
        is_array($answer) ? NULL : $answer->getPrecision(),
      );
      return TRUE;
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Formats a probability.
   */
  private function p(float $value): string {
    return number_format($value, 3);
  }

  /**
   * Check C01: Capabilities declared locally (no HTTP).
   */
  private function checkC01(): array {
    $provider = $this->provider();
    $declared = $provider->getDecisionCapabilities($this->model);
    // Jev answers every question: every Decision capability except refusals.
    // Image files follow the images setting, and box questions also need Jev
    // 1.14 and an AI release that has them. tryFrom() keeps this working on AI
    // versions without the refusal or box capabilities.
    $refusals = AiModelCapability::tryFrom('decision_refusals');
    $box = AiModelCapability::tryFrom('decision_box');
    $images = $this->imagesEnabled();
    $with_box = $this->expectsBox($this->model);
    $expected = array_filter(AiModelCapability::cases(), static fn (AiModelCapability $c): bool => $c->getBaseOperationType() === 'decision' && $c !== $refusals && ($images || $c !== AiModelCapability::DecisionImageFiles) && ($with_box || $c !== $box));
    $missing = array_filter($expected, static fn (AiModelCapability $c): bool => !$declared->has($c));
    $unexpected = array_filter($declared->getCapabilities(), static fn (AiModelCapability $c): bool => !in_array($c, $expected, TRUE));
    $problems = [];
    if ($missing) {
      $problems[] = 'missing ' . implode(', ', array_map(static fn ($c) => $c->value, $missing));
    }
    if ($unexpected) {
      $problems[] = 'unexpectedly declared ' . implode(', ', array_map(static fn ($c) => $c->value, $unexpected));
    }
    if ($declared->maxFiles !== NULL) {
      $problems[] = 'a file limit is declared; TypeSafe documents none';
    }
    if ($declared->maxChoiceOptions !== 255 || $declared->maxScoreLevels !== 10 || $declared->maxQuestions !== NULL) {
      $problems[] = sprintf('limits %s/%s/%s, expected 255/10/null', var_export($declared->maxChoiceOptions, TRUE), var_export($declared->maxScoreLevels, TRUE), var_export($declared->maxQuestions, TRUE));
    }
    if (!$provider->isUsable('decision', [AiModelCapability::DecisionChoice, AiModelCapability::DecisionScore])) {
      $problems[] = 'isUsable(decision, [choice, score]) is FALSE';
    }
    if ($provider->isUsable('chat')) {
      $problems[] = 'isUsable(chat) is TRUE';
    }
    if ($provider->getConfiguredModels('decision', [AiModelCapability::ChatTools]) !== []) {
      $problems[] = 'a chat capability filter returned models';
    }
    if ($this->http() !== 0) {
      $problems[] = $this->http() . ' HTTP request(s) made';
    }
    if ($problems) {
      return $this->result('FAIL', implode('; ', $problems));
    }
    return $this->result('PASS', sprintf('%d Decision capabilities declared; images %s, box %s; limits 255 options / 10 levels / no question limit; no HTTP.', count($expected), $images ? 'on' : 'off', $with_box ? 'yes' : 'no'));
  }

  /**
   * Check C02: Model discovery.
   */
  private function checkC02(): array {
    $models = $this->provider()->getConfiguredModels('decision');
    $http = $this->http();
    if (!isset($models['jev-latest'])) {
      return ['FAIL', 'jev-latest is not listed: ' . implode(', ', array_keys($models))];
    }
    return ['PASS', implode(', ', array_keys($models)) . ($http ? " (fetched, $http request)" : ' (from cache)')];
  }

  /**
   * Check B01: Noul (true/false), positive case.
   */
  private function checkB01(): array {
    $answer = $this->decide(new DecisionInput('The customer writes: "I was charged twice this month. Please refund the duplicate payment."', [
      'refund' => new NoulQuestion('Is the customer asking for money back?'),
    ]))->getNoul('refund');
    $p = $answer->getProbability();
    if ($p < 0 || $p > 1) {
      return ['FAIL', 'Probability out of range: ' . $p];
    }
    return [$p > 0.5 ? 'PASS' : 'WARN', 'P(true) = ' . $this->p($p) . ' (expected > 0.5)'];
  }

  /**
   * Check B02: Noul (true/false), negative case.
   */
  private function checkB02(): array {
    $answer = $this->decide(new DecisionInput('The customer writes: "The new dashboard is great, thank you!"', [
      'refund' => new NoulQuestion('Is the customer asking for money back?'),
    ]))->getNoul('refund');
    $p = $answer->getProbability();
    if ($p < 0 || $p > 1) {
      return ['FAIL', 'Probability out of range: ' . $p];
    }
    return [$p < 0.5 ? 'PASS' : 'WARN', 'P(true) = ' . $this->p($p) . ' (expected < 0.5)'];
  }

  /**
   * Check B03: Choice with descriptions over structured state.
   */
  private function checkB03(): array {
    $options = [
      'billing' => 'Charges, invoices, refunds or subscriptions',
      'technical' => 'Bugs, errors, crashes or integration problems',
      'sales' => 'Pricing, plans or quotes',
    ];
    $state = [
      'ticket' => [
        'subject' => 'Export crashes',
        'message' => 'The desktop app crashes every time I export a PDF.',
      ],
    ];
    $answer = $this->decide(new DecisionInput($state, [
      'team' => new ChoiceQuestion('Which team should handle `ticket`?', $options),
    ]))->getChoice('team');
    $probabilities = $answer->getProbabilities();
    if (!isset($options[$answer->getChoice()]) || array_diff(array_keys($options), array_keys($probabilities)) || !$this->validDistribution($answer) || $answer->getConfidence() < 0 || $answer->getConfidence() > 1) {
      return ['FAIL', 'Malformed choice answer: ' . json_encode($answer->toArray())];
    }
    return $this->result($answer->getChoice() === 'technical' ? 'PASS' : 'WARN', sprintf('%s (p=%s, confidence %s; expected technical)', $answer->getChoice(), $this->p($answer->getProbability($answer->getChoice())), $this->p($answer->getConfidence())));
  }

  /**
   * Check B04: Choice built with fromOptions().
   */
  private function checkB04(): array {
    $answer = $this->decide(new DecisionInput(['text' => 'Wo ist der Bahnhof, bitte?'], [
      'language' => ChoiceQuestion::fromOptions('What language is `text` written in?', [
        'english',
        'german',
        'spanish',
        'french',
      ]),
    ]))->getChoice('language');
    if (!$this->validDistribution($answer)) {
      return ['FAIL', 'Probabilities do not sum to 1.'];
    }
    return $this->result($answer->getChoice() === 'german' ? 'PASS' : 'WARN', $answer->getChoice() . ' (confidence ' . $this->p($answer->getConfidence()) . '; expected german)');
  }

  /**
   * Check B05: Score, three levels.
   */
  private function checkB05(): array {
    $answer = $this->decide(new DecisionInput('Our production site has been down for an hour and customers cannot check out.', [
      'urgency' => new ScoreQuestion('How urgent is the situation described?', [
        'Can wait a week',
        'Handle today',
        'Drop everything now',
      ]),
    ]))->getScore('urgency');
    $level = $answer->getMostLikelyLevel();
    if (count($answer->getProbabilities()) !== 3 || $level < 0 || $level > 2 || $answer->getScore() < 0 || $answer->getScore() > 2 || !$this->validDistribution($answer)) {
      return ['FAIL', 'Malformed score answer: ' . json_encode($answer->toArray())];
    }
    return $this->result($level === 2 ? 'PASS' : 'WARN', sprintf('most likely level %d, score %s (expected level 2)', $level, number_format($answer->getScore(), 2)));
  }

  /**
   * Check B06: Structured instructions, criteria, descriptions and levels.
   */
  private function checkB06(): array {
    $state = [
      'ticket' => [
        'tier' => 'enterprise',
        'message' => 'We are moving to another vendor next month unless the export bug is fixed.',
      ],
    ];
    $input = new DecisionInput($state, [
      'churn' => new NoulQuestion(
        [
          'question' => 'Is the customer at risk of leaving?',
          'focus' => 'Explicit or implied intent to cancel or switch vendors.',
        ],
        [
          'true' => ['summary' => 'The customer says or implies they may leave.'],
          'false' => ['summary' => 'No sign of leaving.'],
        ],
      ),
      'team' => new ChoiceQuestion(['question' => 'Which team owns this?', 'context' => 'Route by the root cause.'], [
        'technical' => ['name' => 'Technical', 'covers' => ['bugs', 'exports']],
        'sales' => ['name' => 'Sales', 'covers' => ['renewals', 'pricing']],
      ]),
      'risk' => new ScoreQuestion(['question' => 'How much revenue is at risk?'], [
        ['level' => 'Low', 'meaning' => 'Small account or no intent to leave'],
        ['level' => 'Medium', 'meaning' => 'Some intent to leave'],
        ['level' => 'High', 'meaning' => 'Large account with a stated deadline'],
      ]),
    ]);
    $provider = $this->provider();
    $required = DecisionRequestValidator::getRequiredCapabilities($input);
    $declared = $provider->getDecisionCapabilities($this->model);
    if (!$declared->supports($required)) {
      return ['FAIL', 'Declared capabilities do not cover the request.'];
    }
    $response = $this->decide($input, NULL, $provider);
    return $this->result('PASS', sprintf('%d capabilities required; churn P=%s, team %s, risk level %d', count($required), $this->p($response->getNoul('churn')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('risk')->getMostLikelyLevel()));
  }

  /**
   * Builds the three-question request used by B07 and B08.
   */
  private function multiInput(): DecisionInput {
    return new DecisionInput(['ticket' => ['message' => 'I was charged twice and I am furious. Refund me today or I cancel.']], [
      'refund' => new NoulQuestion('Is the customer asking for money back?'),
      'team' => new ChoiceQuestion('Which team should handle `ticket`?', [
        'billing' => 'Billing',
        'technical' => 'Technical',
        'sales' => 'Sales',
      ]),
      'anger' => new ScoreQuestion('How angry is the customer?', ['Calm', 'Annoyed', 'Furious']),
    ]);
  }

  /**
   * Check B07: Three question types in one request.
   */
  private function checkB07(): array {
    $response = $this->decide($this->multiInput());
    $calls = array_filter($this->recorder->snapshot()['calls'], static fn (array $c): bool => $c['segment'] === 'B07' && $c['operation'] === 'decision');
    if (count($response->getAnswers()) !== 3 || count($calls) !== 1 || $this->http() !== 1) {
      return $this->result('FAIL', sprintf('%d answers, %d calls, %d HTTP requests (expected 3/1/1)', count($response->getAnswers()), count($calls), $this->http()));
    }
    $ok = $response->getNoul('refund')->isLikely() && $response->getChoice('team')->getChoice() === 'billing' && $response->getScore('anger')->getMostLikelyLevel() === 2;
    return $this->result($ok ? 'PASS' : 'WARN', sprintf('one HTTP request; refund P=%s, team %s, anger level %d', $this->p($response->getNoul('refund')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('anger')->getMostLikelyLevel()));
  }

  /**
   * Check B08: Same request on jev-preview.
   */
  private function checkB08(): array {
    $response = $this->decide($this->multiInput(), 'jev-preview');
    if (count($response->getAnswers()) !== 3) {
      return ['FAIL', count($response->getAnswers()) . ' answers'];
    }
    return $this->result('PASS', sprintf('response model "%s"; refund P=%s, team %s, anger level %d', $response->getModel(), $this->p($response->getNoul('refund')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('anger')->getMostLikelyLevel()));
  }

  /**
   * Builds a choice question with the given number of numbered options.
   */
  private function numberedChoice(int $count): ChoiceQuestion {
    $options = [];
    for ($i = 1; $i <= $count; $i++) {
      $options['opt_' . $i] = 'Option number ' . $i;
    }
    return new ChoiceQuestion('Which option number is written in `text`?', $options);
  }

  /**
   * Builds a score question with the given number of levels.
   */
  private function numberedScore(int $count): ScoreQuestion {
    $levels = [];
    for ($i = 1; $i <= $count; $i++) {
      $levels[] = 'The number ' . $i;
    }
    return new ScoreQuestion('Which number from 1 to ' . $count . ' is written in `text`?', $levels);
  }

  /**
   * Check B09: 255 choice options accepted.
   */
  private function checkB09(): array {
    $answer = $this->decide(new DecisionInput(['text' => 'Option 17'], ['pick' => $this->numberedChoice(255)]))->getChoice('pick');
    if (count($answer->getProbabilities()) !== 255) {
      return ['FAIL', count($answer->getProbabilities()) . ' probabilities returned'];
    }
    return $this->result($answer->getChoice() === 'opt_17' ? 'PASS' : 'WARN', 'accepted; chose ' . $answer->getChoice() . ' (expected opt_17)');
  }

  /**
   * Check B10: 256 choice options rejected before HTTP.
   */
  private function checkB10(): array {
    return $this->expectRejected(fn () => $this->decide(new DecisionInput(['text' => 'Option 17'], ['pick' => $this->numberedChoice(256)])), AiBadRequestException::class);
  }

  /**
   * Check B11: 10 score levels accepted.
   */
  private function checkB11(): array {
    $answer = $this->decide(new DecisionInput(['text' => 'Seven (7)'], ['n' => $this->numberedScore(10)]))->getScore('n');
    if (count($answer->getProbabilities()) !== 10) {
      return ['FAIL', count($answer->getProbabilities()) . ' level probabilities returned'];
    }
    return $this->result($answer->getMostLikelyLevel() === 6 ? 'PASS' : 'WARN', 'accepted; most likely level index ' . $answer->getMostLikelyLevel() . ' (expected 6, "The number 7")');
  }

  /**
   * Check B12: 11 score levels rejected before HTTP.
   */
  private function checkB12(): array {
    return $this->expectRejected(fn () => $this->decide(new DecisionInput(['text' => 'Seven'], ['n' => $this->numberedScore(11)])), AiBadRequestException::class);
  }

  /**
   * Check B13: Request without questions rejected before HTTP.
   */
  private function checkB13(): array {
    return $this->expectRejected(fn () => $this->decide(new DecisionInput('Anything', [])), AiBadRequestException::class);
  }

  /**
   * Check B14: Unknown model ID.
   */
  private function checkB14(): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->decide(new DecisionInput('Hello', ['q' => new NoulQuestion('Is this a greeting?')]), 'jev-does-not-exist');
      return ['WARN', 'The API accepted an unknown model ID.'];
    }
    catch (AiExceptionInterface $e) {
      $requests = array_slice($this->recorder->snapshot()['http'], $before);
      $statuses = implode(',', array_map(static fn ($r) => (string) $r['status'], $requests));
      return $this->result('PASS', (new \ReflectionClass($e))->getShortName() . " after HTTP $statuses: " . mb_strimwidth($e->getMessage(), 0, 160, '…'));
    }
  }

  /**
   * Check B16: Unsupported image file rejected before HTTP.
   */
  private function checkB16(): array {
    // A 1x1 PNG: with images off Jev declares no image support, and with
    // images on the image is below TypeSafe's minimum size. Either way no
    // request is sent.
    $photo = new ImageFile((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', TRUE), 'image/png', 'listing.png');
    return $this->expectRejected(fn () => $this->decide(new DecisionInput(['listing' => ['color' => 'red']], ['color_matches' => new NoulQuestion('The product in `images[0]` matches `listing.color`.')], [$photo])), $this->imagesEnabled() ? AiBadRequestException::class : AiMissingFeatureException::class);
  }

  /**
   * Check B15: Invalid API key.
   */
  private function checkB15(): array {
    $provider = $this->provider();
    $provider->setAuthentication('tss-invalid-key-000000');
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->decide(new DecisionInput('Hello', ['q' => new NoulQuestion('Is this a greeting?')]), NULL, $provider);
      return ['FAIL', 'The API accepted an invalid key.'];
    }
    catch (\Throwable $e) {
      $requests = array_slice($this->recorder->snapshot()['http'], $before);
      $statuses = array_map(static fn ($r) => $r['status'], $requests);
      $short = (new \ReflectionClass($e))->getShortName();
      if (str_contains($e->getMessage(), 'tss-invalid-key-000000')) {
        return ['FAIL', "$short echoed the API key."];
      }
      if ($e instanceof AiSetupFailureException && in_array(401, $statuses, TRUE)) {
        // The provider quotes TypeSafe's x-typesafe-request-id in API errors.
        $request_id = preg_match('/request ID (req_\w+)/', $e->getMessage(), $matches) ? $matches[1] : NULL;
        return $this->result($request_id !== NULL ? 'PASS' : 'WARN', "$short after HTTP 401 (" . count($requests) . ' request, no retry)' . ($request_id !== NULL ? "; request ID $request_id" : '; no request ID in the message'));
      }
      return $this->result('WARN', "$short after HTTP " . implode(',', $statuses) . ': ' . mb_strimwidth($e->getMessage(), 0, 160, '…'));
    }
  }

  /**
   * Check B17: API key with surrounding whitespace still authenticates.
   */
  private function checkB17(): array {
    $key_id = (string) $this->configFactory->get('ai_provider_typesafeai.settings')->get('api_key');
    $key = trim((string) ($key_id !== '' ? $this->keyRepository->getKey($key_id)?->getKeyValue() : ''));
    if ($key === '') {
      return ['SKIP', 'No configured TypeSafe key value to pad.'];
    }
    $provider = $this->provider();
    // Key files and environment values often end with a newline.
    $provider->setAuthentication("  $key\n");
    try {
      $answer = $this->decide(new DecisionInput('Hello there, nice to meet you!', ['q' => new NoulQuestion('Is this a greeting?')]), NULL, $provider)->getNoul('q');
    }
    catch (\Throwable $e) {
      // Providers without key normalization let the HTTP library echo the
      // Authorization header; never print the key.
      return ['FAIL', (new \ReflectionClass($e))->getShortName() . ': ' . str_replace($key, '[key]', $e->getMessage())];
    }
    return ['PASS', 'accepted after trimming; greeting ' . $this->p($answer->getProbability())];
  }

  /**
   * Check B18: Malformed API key rejected before HTTP without echoing it.
   */
  private function checkB18(): array {
    $key = 'tss-secret key-000000';
    $provider = $this->provider();
    $provider->setAuthentication($key);
    [$status, $detail] = $this->expectRejected(fn () => $this->decide(new DecisionInput('Hello', ['q' => new NoulQuestion('Is this a greeting?')]), NULL, $provider), AiSetupFailureException::class);
    if (str_contains($detail, 'tss-secret')) {
      return ['FAIL', 'The error echoed the key: ' . str_replace($key, '[key]', $detail)];
    }
    return [$status, $detail];
  }

  /**
   * Check B19: TypeSafe request ID in the output metadata.
   */
  private function checkB19(): array {
    $output = $this->provider()->decision(new DecisionInput('Help! My payouts have been failing for 3 days.', ['urgent' => new NoulQuestion('Does this convey urgency?')]), $this->model, ['typesafe_smoke']);
    $request_id = $output->getMetadata()['request_id'] ?? NULL;
    if (!is_string($request_id) || !preg_match('/^req_\w+$/', $request_id)) {
      return ['FAIL', 'No TypeSafe request ID in the output metadata: ' . var_export($request_id, TRUE)];
    }
    $urgency = $output->getNormalized()->getNoul('urgent')->getProbability();
    return ['PASS', "request_id $request_id; urgency " . $this->p($urgency)];
  }

  /**
   * Runs a decision with a guardrail set attached.
   */
  private function guarded(string $set, DecisionInput $input): DecisionResponse {
    /** @var \Drupal\ai\OperationType\Decision\DecisionInput $guarded */
    $guarded = $this->guardrails->applyGuardrailSetToChatInput($set, $input);
    return $this->decide($guarded);
  }

  /**
   * Expects a Decision block with a message code and phase.
   */
  private function expectBlocked(callable $call, string $code, AiGuardrailModeEnum $phase, int $max_http): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $call();
      return ['FAIL', 'Not blocked.'];
    }
    catch (AiDecisionBlockedException $e) {
      $http = $this->httpSince($before);
      if ($e->phase !== $phase || !str_contains($e->getMessage(), $code)) {
        return ['FAIL', sprintf('Blocked in %s with "%s"', $e->phase->value, $e->getMessage())];
      }
      if ($http > $max_http) {
        return ['FAIL', "Blocked, but after $http HTTP request(s)."];
      }
      return ['PASS', sprintf('%s block after %d HTTP request(s): %s', $e->phase->value, $http, $e->getMessage())];
    }
  }

  /**
   * Check G01: Guardrail: card number blocks before HTTP.
   */
  private function checkG01(): array {
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_pii', new DecisionInput(['message' => 'Refund to card 4111 1111 1111 1111 please.'], [
      'refund' => new NoulQuestion('Is the customer asking for money back?'),
    ])), 'TSS-GR-CARD', AiGuardrailModeEnum::PreGenerate, 0);
  }

  /**
   * Check G02: Guardrail: length limit blocks before HTTP.
   */
  private function checkG02(): array {
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_intake', new DecisionInput(['message' => str_repeat('The import failed again today. ', 170)], [
      'bug' => new NoulQuestion('Is this a bug report?'),
    ])), 'TSS-GR-LENGTH', AiGuardrailModeEnum::PreGenerate, 0);
  }

  /**
   * Check G03: Guardrail: TypeSafe moderation blocks abusive state.
   */
  private function checkG03(): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->guarded('typesafe_smoke_intake', new DecisionInput(['message' => $this->fixtures->probes()['abusive']], [
        'escalate' => new NoulQuestion('Should this ticket be escalated?'),
      ]));
      $calls = array_column(array_filter($this->recorder->snapshot()['calls'], static fn ($c) => $c['segment'] === 'G03'), 'operation');
      return ['WARN', 'Not flagged by moderation; the decision ran. Calls: ' . implode(', ', $calls)];
    }
    catch (AiDecisionBlockedException $e) {
      $calls = array_filter($this->recorder->snapshot()['calls'], static fn ($c) => $c['segment'] === 'G03');
      $moderation = array_filter($calls, static fn ($c) => $c['operation'] === 'moderation');
      $http = $this->httpSince($before);
      if (!str_contains($e->getMessage(), 'TSS-GR-MOD') || count($moderation) !== 1 || $http !== 1) {
        return $this->result('FAIL', sprintf('Blocked with "%s"; %d moderation call(s), %d HTTP request(s) (expected 1/1)', $e->getMessage(), count($moderation), $http));
      }
      return ['PASS', 'Blocked by TypeSafe moderation; 1 moderation request, no decision request.'];
    }
  }

  /**
   * Check G04: Guardrail: post-phase block on answer text after the call.
   *
   * Post-generation RegEx checks scan answer text only: chosen options and
   * score legends, never question IDs or numbers. TypeSafe echoes level
   * descriptions in the legend, so the probe matches whatever the model rates.
   */
  private function checkG04(): array {
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_post_probe', new DecisionInput('Hello there!', [
      'formality' => new ScoreQuestion('How formal is this greeting?', ['post_probe_target', 'Formal']),
    ])), 'TSS-GR-POST', AiGuardrailModeEnum::PostGenerate, 1);
  }

  /**
   * Check G05: Guardrail: unsupported plugin is a setup failure.
   */
  private function checkG05(): array {
    return $this->expectRejected(fn () => $this->guarded('typesafe_smoke_unsupported_probe', new DecisionInput('Hello', [
      'q' => new NoulQuestion('Is this a greeting?'),
    ])), AiSetupFailureException::class);
  }

  /**
   * Check G06: Guardrail: TypeSafe moderation covers question text.
   *
   * Moderation checks the question instructions and criteria as well as the
   * state, so abusive text in a question is stopped although the state is
   * benign. Like G03, a miss is a model judgment and only a warning. In
   * a first run, the same sentence quoted inside a neutral question was not
   * flagged, so the probe makes the statement itself the question text.
   */
  private function checkG06(): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->guarded('typesafe_smoke_intake', new DecisionInput(['message' => 'Hello there!'], [
        // The statement itself is abusive; quoting abuse inside a neutral
        // question is reasonably not flagged.
        'statement' => new NoulQuestion($this->fixtures->probes()['abusive']),
      ]));
      return ['WARN', 'Question text was not flagged by moderation; the decision ran.'];
    }
    catch (AiDecisionBlockedException $e) {
      $http = $this->httpSince($before);
      if ($e->phase !== AiGuardrailModeEnum::PreGenerate || !str_contains($e->getMessage(), 'TSS-GR-MOD') || $http !== 1) {
        return $this->result('FAIL', sprintf('Blocked in %s with "%s" after %d HTTP request(s) (expected pre, TSS-GR-MOD, 1)', $e->phase->value, $e->getMessage(), $http));
      }
      return ['PASS', 'Abusive question text blocked by moderation; 1 moderation request, no decision request.'];
    }
  }

  /**
   * Runs text classification through the proxy.
   *
   * @return \Drupal\ai\OperationType\TextClassification\TextClassificationItem[]
   *   The items.
   */
  private function classify(string $text, array $labels, ?ProviderProxy $provider = NULL): array {
    return ($provider ?? $this->provider())->textClassification(new TextClassificationInput($text, $labels), $this->model, ['typesafe_smoke'])->getNormalized();
  }

  /**
   * Summarizes classification items.
   */
  private function items(array $items): string {
    return implode(', ', array_map(fn ($item) => $item->getLabel() . '=' . $this->p((float) $item->getConfidenceScore()), $items));
  }

  /**
   * Check T01: Text classification with labels.
   */
  private function checkT01(): array {
    $items = $this->classify('The checkout was fast and painless.', ['positive', 'negative', 'neutral']);
    $scores = array_map(static fn ($i) => (float) $i->getConfidenceScore(), $items);
    $sorted = $scores;
    rsort($sorted);
    if (count($items) !== 3 || $scores !== $sorted || !$this->validDistribution($scores)) {
      return ['FAIL', 'Malformed items: ' . $this->items($items)];
    }
    return [$items[0]->getLabel() === 'positive' ? 'PASS' : 'WARN', $this->items($items)];
  }

  /**
   * Check T02: Text classification without labels rejected before HTTP.
   */
  private function checkT02(): array {
    return $this->expectRejected(fn () => $this->classify('Anything at all', []), AiBadRequestException::class);
  }

  /**
   * Check T03: Text classification with custom instructions.
   */
  private function checkT03(): array {
    $provider = $this->provider();
    $provider->setConfiguration(['instructions' => 'Which department should receive the message in `text`?']);
    $labels = ['billing', 'technical', 'sales'];
    $items = $this->classify('Seit dem letzten Update stürzt die App beim PDF-Export ab.', $labels, $provider);
    return $this->result($items[0]->getLabel() === 'technical' ? 'PASS' : 'WARN', $this->items($items) . ' (expected technical first)');
  }

  /**
   * Check T04: Text classification removes duplicate and blank labels.
   */
  private function checkT04(): array {
    $items = $this->classify('I need a copy of last month\'s invoice.', ['billing', ' billing ', '', 'technical']);
    $labels = array_map(static fn ($i) => $i->getLabel(), $items);
    sort($labels);
    if ($labels !== ['billing', 'technical']) {
      return $this->result('FAIL', 'Labels: ' . implode(', ', $labels));
    }
    return $this->result('PASS', $this->items($items));
  }

  /**
   * Runs moderation through the proxy, always passing the model.
   */
  private function moderate(string $text): array {
    $response = $this->provider()->moderation($text, $this->model, ['typesafe_smoke'])->getNormalized();
    return [(bool) $response->isFlagged(), $response->getInformation()];
  }

  /**
   * Checks the shape of TypeSafe moderation information.
   */
  private function moderationShape(array $info): ?string {
    foreach (['threshold', 'model', 'categories', 'max_probability', 'max_category'] as $key) {
      if (!array_key_exists($key, $info)) {
        return "missing '$key'";
      }
    }
    if (!is_array($info['categories']) || $info['categories'] === []) {
      return 'no categories';
    }
    foreach ($info['categories'] as $key => $category) {
      if (!isset($category['probability'], $category['flagged'], $category['description'])) {
        return "category '$key' lacks probability/flagged/description";
      }
    }
    return NULL;
  }

  /**
   * Check M01: Moderation, benign text.
   */
  private function checkM01(): array {
    [$flagged, $info] = $this->moderate($this->fixtures->probes()['benign']);
    if ($problem = $this->moderationShape($info)) {
      return ['FAIL', $problem];
    }
    $detail = sprintf('%d categories; flagged=%s; max %s=%s', count($info['categories']), $flagged ? 'yes' : 'no', $info['max_category'], $this->p((float) $info['max_probability']));
    return $this->result(!$flagged ? 'PASS' : 'WARN', $detail);
  }

  /**
   * Check M02: Moderation, abusive text.
   */
  private function checkM02(): array {
    [$flagged, $info] = $this->moderate($this->fixtures->probes()['abusive']);
    if ($problem = $this->moderationShape($info)) {
      return ['FAIL', $problem];
    }
    if ($flagged !== ((float) $info['max_probability'] >= (float) $info['threshold'])) {
      return ['FAIL', 'flagged does not match max_probability >= threshold'];
    }
    $hits = array_keys(array_filter($info['categories'], static fn ($c) => !empty($c['flagged'])));
    return $this->result($flagged ? 'PASS' : 'WARN', sprintf('flagged=%s; categories over threshold: %s', $flagged ? 'yes' : 'no', $hits ? implode(', ', $hits) : 'none'));
  }

  /**
   * Whether the TypeSafe images setting is on.
   */
  private function imagesEnabled(): bool {
    return (bool) $this->configFactory->get('ai_provider_typesafeai.settings')->get('images_enabled');
  }

  /**
   * Whether a model should declare box questions.
   *
   * Mirrors the provider: images on, an AI release with box questions, and
   * jev-preview or Jev 1.14.0 and later.
   */
  private function expectsBox(string $model): bool {
    if (!$this->imagesEnabled() || AiModelCapability::tryFrom('decision_box') === NULL) {
      return FALSE;
    }
    return $model === 'jev-preview' || (preg_match('/^jev-(\d+\.\d+\.\d+)$/D', $model, $matches) === 1 && version_compare($matches[1], '1.14.0', '>='));
  }

  /**
   * Returns a SKIP result when images are off or box questions are missing.
   */
  private function imageSkip(bool $box = FALSE): ?array {
    if (!$this->imagesEnabled()) {
      return ['SKIP', 'TypeSafe images are off (ai_provider_typesafeai.settings:images_enabled).'];
    }
    if ($box && AiModelCapability::tryFrom('decision_box') === NULL) {
      return ['SKIP', 'This AI release has no Decision box questions.'];
    }
    return NULL;
  }

  /**
   * Builds an image with GD: a solid background and an optional rectangle.
   *
   * @param string $format
   *   The format: "png" or "jpeg".
   * @param int[] $background
   *   The background color as red, green and blue.
   * @param int $width
   *   The width in pixels.
   * @param int $height
   *   The height in pixels.
   * @param int[]|null $rectangle
   *   The rectangle's left, top, right and bottom pixel edges (right and
   *   bottom exclusive), or NULL for none.
   * @param int[] $color
   *   The rectangle color as red, green and blue.
   * @param bool $progressive
   *   Whether to save a JPEG as progressive instead of baseline.
   */
  private function image(
    string $format,
    array $background,
    int $width = 300,
    int $height = 300,
    ?array $rectangle = NULL,
    array $color = [220, 20, 20],
    bool $progressive = FALSE,
  ): ImageFile {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$background));
    if ($rectangle !== NULL) {
      [$left, $top, $right, $bottom] = $rectangle;
      imagefilledrectangle($image, $left, $top, $right - 1, $bottom - 1, (int) imagecolorallocate($image, ...$color));
    }
    imageinterlace($image, $progressive);
    ob_start();
    if ($format === 'png') {
      imagepng($image);
    }
    else {
      imagejpeg($image, NULL, 90);
    }
    return new ImageFile((string) ob_get_clean(), 'image/' . $format, "smoke.$format");
  }

  /**
   * A 600 × 400 white PNG with a red rectangle at x 150–300, y 100–300.
   */
  private function redRectangle(): ImageFile {
    return $this->image('png', [255, 255, 255], 600, 400, [150, 100, 300, 300]);
  }

  /**
   * Describes the last HTTP request of the current check.
   */
  private function lastRequest(): string {
    $requests = $this->recorder->snapshot($this->current)['http'];
    $request = end($requests);
    if (!is_array($request)) {
      return 'no request';
    }
    return ($request['multipart'] ?? FALSE ? 'multipart' : 'JSON') . '; headers: ' . (($request['danger_zone'] ?? []) ? implode(', ', $request['danger_zone']) : 'none');
  }

  /**
   * Check I01: Images: GIF and 1x1 PNG rejected before HTTP.
   */
  private function checkI01(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $details = [];
    $gd = imagecreatetruecolor(300, 300);
    ob_start();
    imagegif($gd);
    $images = [
      'GIF' => new ImageFile((string) ob_get_clean(), 'image/gif', 'smoke.gif'),
      '1x1 PNG' => $this->image('png', [255, 255, 255], 1, 1),
    ];
    foreach ($images as $name => $image) {
      [$status, $detail] = $this->expectRejected(fn () => $this->decide(new DecisionInput('A product photo.', ['q' => new NoulQuestion('Is `images[0]` a photo of a shoe?')], [$image])), AiBadRequestException::class);
      if ($status !== 'PASS') {
        return [$status, "$name: $detail"];
      }
      $details[] = "$name: " . mb_strimwidth($detail, 0, 140, '…');
    }
    return ['PASS', implode(' | ', $details)];
  }

  /**
   * Check I02: Images: noul about a red rectangle (multipart, images header).
   */
  private function checkI02(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $response = $this->decide(new DecisionInput('A test image.', ['red' => new NoulQuestion('Is there a red rectangle in `images[0]`?')], [$this->redRectangle()]));
    $request = $this->lastRequest();
    if ($this->http() !== 1 || !str_starts_with($request, 'multipart') || !str_contains($request, 'TypeSafe-Danger-Zone-Images')) {
      return ['FAIL', sprintf('%d HTTP request(s); %s', $this->http(), $request)];
    }
    $p = $response->getNoul('red')->getProbability();
    return $this->result($p > 0.5 ? 'PASS' : 'WARN', sprintf('model %s; red rectangle P=%s; %s', $response->getModel(), $this->p($p), $request));
  }

  /**
   * Check I03: Images: file order kept (red PNG, then blue JPEG).
   */
  private function checkI03(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $colors = ['red' => 'Mostly red.', 'blue' => 'Mostly blue.', 'green' => 'Mostly green.'];
    $response = $this->decide(new DecisionInput('Two test images.', [
      'first' => new ChoiceQuestion('What color fills `images[0]`?', $colors),
      'second' => new ChoiceQuestion('What color fills `images[1]`?', $colors),
    ], [$this->image('png', [220, 20, 20]), $this->image('jpeg', [20, 40, 220])]));
    if ($this->http() !== 1) {
      return ['FAIL', $this->http() . ' HTTP requests; expected 1'];
    }
    $first = $response->getChoice('first')->getChoice();
    $second = $response->getChoice('second')->getChoice();
    return $this->result($first === 'red' && $second === 'blue' ? 'PASS' : 'WARN', sprintf('images[0] %s (%s), images[1] %s (%s); %s', $first, $this->p($response->getChoice('first')->getConfidence()), $second, $this->p($response->getChoice('second')->getConfidence()), $this->lastRequest()));
  }

  /**
   * Check I04: Box: red rectangle located on jev-preview.
   */
  private function checkI04(): array {
    if ($skip = $this->imageSkip(TRUE)) {
      return $skip;
    }
    $response = $this->decide(new DecisionInput('A test image.', [
      'present' => ['type' => 'noul', 'instructions' => 'Is there a red rectangle in `images[0]`?'],
      'where' => ['type' => 'box', 'instructions' => 'the red rectangle in `images[0]`'],
    ], [$this->redRectangle()]), 'jev-preview');
    $box = $response->getBox('where');
    $expected = ['top' => 0.25, 'bottom' => 0.75, 'left' => 0.25, 'right' => 0.5];
    $errors = array_map(static fn (string $edge): float => abs($box->getBox()[$edge] - $expected[$edge]), array_keys($expected));
    $pixels = $box->toPixels(600, 400);
    $detail = sprintf('model %s; present P=%s; box top %s, bottom %s, left %s, right %s = (%d,%d)-(%d,%d) px of 600 × 400, expected (150,100)-(300,300); confidence %s; %s',
      $response->getModel(),
      $this->p($response->getNoul('present')->getProbability()),
      $this->p($box->getTop()),
      $this->p($box->getBottom()),
      $this->p($box->getLeft()),
      $this->p($box->getRight()),
      $pixels['left'],
      $pixels['top'],
      $pixels['right'],
      $pixels['bottom'],
      $this->p($box->getConfidence()),
      $this->lastRequest(),
    );
    return $this->result(max($errors) <= 0.05 ? 'PASS' : 'WARN', $detail);
  }

  /**
   * Check I05: Box: rejected before HTTP on jev-latest.
   */
  private function checkI05(): array {
    if ($skip = $this->imageSkip(TRUE)) {
      return $skip;
    }
    // Update this check once jev-latest points to Jev 1.14 and declares box.
    [$status, $detail] = $this->expectRejected(fn () => $this->decide(new DecisionInput('A test image.', [
      'where' => ['type' => 'box', 'instructions' => 'the red rectangle in `images[0]`'],
    ], [$this->redRectangle()]), 'jev-latest'), AiMissingFeatureException::class);
    if ($status === 'PASS' && !str_contains($detail, 'decision_box')) {
      return ['FAIL', 'Rejected for another feature: ' . $detail];
    }
    return [$status, $detail];
  }

  /**
   * Check L01: Long context header on a small request.
   */
  private function checkL01(): array {
    $provider = $this->provider();
    $provider->setConfiguration(['long_context' => TRUE]);
    $answer = $this->decide(new DecisionInput('Hello there, nice to meet you!', ['q' => new NoulQuestion('Is this a greeting?')]), NULL, $provider)->getNoul('q');
    $request = $this->lastRequest();
    if ($this->http() !== 1 || !str_contains($request, 'TypeSafe-Danger-Zone-Long-Context')) {
      return ['FAIL', sprintf('%d HTTP request(s); %s', $this->http(), $request)];
    }
    return ['PASS', 'greeting P=' . $this->p($answer->getProbability()) . "; $request"];
  }

  /**
   * Gets the input tokens of the current check's calls.
   */
  private function tokensIn(): int {
    $calls = array_filter($this->recorder->snapshot()['calls'], fn (array $call): bool => $call['segment'] === $this->current);
    return array_sum(array_map(static fn (array $call): int => (int) $call['tokens_in'], $calls));
  }

  /**
   * Gets the largest edge difference between a box and the expected edges.
   */
  private function boxError(BoxAnswer $box, array $expected): float {
    return max(array_map(static fn (string $edge): float => abs($box->getBox()[$edge] - $expected[$edge]), array_keys($expected)));
  }

  /**
   * Formats a box's edges.
   */
  private function edges(BoxAnswer $box): string {
    return sprintf('top %s, bottom %s, left %s, right %s', $this->p($box->getTop()), $this->p($box->getBottom()), $this->p($box->getLeft()), $this->p($box->getRight()));
  }

  /**
   * Check G07: Guardrail: moderation stops a request with images before HTTP.
   *
   * Moderation is text-only, so AI stops any Decision request with files.
   * This pins the documented limitation; it fails once core changes it.
   */
  private function checkG07(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $question = new NoulQuestion('Is `images[0]` a photo of a shoe?');
    $input = new DecisionInput('A product photo.', ['shoe' => $question], [$this->image('png', [255, 255, 255])]);
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_intake', $input), 'cannot be moderated', AiGuardrailModeEnum::PreGenerate, 0);
  }

  /**
   * Check I06: Images: smallest and largest sizes, and a progressive JPEG.
   *
   * The provider checks TypeSafe's documented limits locally; the API must
   * accept images right at them. TypeSafe only recommends baseline JPEGs, so
   * a rejected progressive JPEG is a warning that the local checks miss it.
   */
  private function checkI06(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $questions = ['solid' => new NoulQuestion('Is `images[0]` a single solid color?')];
    $images = [
      '280 × 280 PNG' => [$this->image('png', [40, 160, 40], 280, 280), FALSE],
      '1568 × 1568 JPEG' => [$this->image('jpeg', [40, 160, 40], 1568, 1568), FALSE],
      'progressive 600 × 400 JPEG' => [$this->image('jpeg', [40, 160, 40], 600, 400, NULL, [220, 20, 20], TRUE), TRUE],
    ];
    $status = 'PASS';
    $details = [];
    foreach ($images as $name => [$image, $soft]) {
      $before = $this->tokensIn();
      try {
        $p = $this->decide(new DecisionInput('A test image.', $questions, [$image]))->getNoul('solid')->getProbability();
        $details[] = sprintf('%s accepted (%d input tokens, solid P=%s)', $name, $this->tokensIn() - $before, $this->p($p));
      }
      catch (AiBadRequestException $e) {
        if (!$soft) {
          return ['FAIL', "$name rejected: " . mb_strimwidth($e->getMessage(), 0, 240, '…')];
        }
        $status = 'WARN';
        $details[] = "$name rejected: " . mb_strimwidth($e->getMessage(), 0, 240, '…');
      }
    }
    if ($this->http() !== 3) {
      return ['FAIL', $this->http() . ' HTTP requests; expected 3. ' . implode('; ', $details)];
    }
    return [$status, implode('; ', $details)];
  }

  /**
   * Check I07: Images: object and list states keep their paths next to images.
   */
  private function checkI07(): array {
    if ($skip = $this->imageSkip()) {
      return $skip;
    }
    $red = $this->image('png', [220, 20, 20]);
    // An object state gains "images"; its own paths still work.
    $ticket = ['subject' => 'Wrong color delivered', 'ordered_color' => 'blue'];
    $object = $this->decide(new DecisionInput(['ticket' => $ticket], [
      'matches' => new NoulQuestion('Is the product in `images[0]` the color given in `ticket.ordered_color`?'),
    ], [$red]))->getNoul('matches')->getProbability();
    // A list state is sent as "items".
    $list = $this->decide(new DecisionInput(['A blue mug', 'A red mug'], [
      'which' => new ChoiceQuestion('Which entry in `items` describes the color of `images[0]`?', [
        'first' => 'The first entry in `items`.',
        'second' => 'The second entry in `items`.',
      ]),
    ], [$red]))->getChoice('which');
    if ($this->http() !== 2) {
      return ['FAIL', $this->http() . ' HTTP requests; expected 2'];
    }
    $ok = $object < 0.5 && $list->getChoice() === 'second';
    return $this->result($ok ? 'PASS' : 'WARN', sprintf('object state: red image matches ordered blue P=%s (expected < 0.5); list state: %s (%s; expected second)', $this->p($object), $list->getChoice(), $this->p($list->getConfidence())));
  }

  /**
   * Check I08: Box: each question searches the image it names.
   */
  private function checkI08(): array {
    if ($skip = $this->imageSkip(TRUE)) {
      return $skip;
    }
    // The red rectangles sit in different places in the two images.
    $images = [
      $this->image('png', [255, 255, 255], 600, 400, [60, 80, 180, 240]),
      $this->image('png', [255, 255, 255], 600, 400, [400, 160, 560, 320]),
    ];
    $expected = [
      'first' => ['top' => 0.2, 'bottom' => 0.6, 'left' => 0.1, 'right' => 0.3],
      'second' => ['top' => 0.4, 'bottom' => 0.8, 'left' => 400 / 600, 'right' => 560 / 600],
    ];
    $response = $this->decide(new DecisionInput('Two test images.', [
      'first' => ['type' => 'box', 'instructions' => 'the red rectangle in `images[0]`'],
      'second' => ['type' => 'box', 'instructions' => 'the red rectangle in `images[1]`'],
    ], $images), 'jev-preview');
    $details = [];
    $ok = TRUE;
    foreach (['first' => 'second', 'second' => 'first'] as $id => $other) {
      $box = $response->getBox($id);
      $error = $this->boxError($box, $expected[$id]);
      $ok = $ok && $error <= 0.05;
      $swapped = $error > 0.05 && $this->boxError($box, $expected[$other]) <= 0.05 ? ' (boxed the other image)' : '';
      $details[] = sprintf('%s: %s, off by %s%s, confidence %s', $id, $this->edges($box), $this->p($error), $swapped, $this->p($box->getConfidence()));
    }
    return $this->result($ok ? 'PASS' : 'WARN', 'model ' . $response->getModel() . '; ' . implode('; ', $details));
  }

  /**
   * Check I09: Box: absent target, with a presence noul.
   *
   * A box is always returned, so the presence noul is what tells callers to
   * ignore it. The image has a blue rectangle where the red one would be.
   */
  private function checkI09(): array {
    if ($skip = $this->imageSkip(TRUE)) {
      return $skip;
    }
    $response = $this->decide(new DecisionInput('A test image.', [
      'present' => new NoulQuestion('Is there a red rectangle in `images[0]`?'),
      'where' => ['type' => 'box', 'instructions' => 'the red rectangle in `images[0]`'],
    ], [$this->image('png', [255, 255, 255], 600, 400, [150, 100, 300, 300], [20, 40, 220])]), 'jev-preview');
    $present = $response->getNoul('present')->getProbability();
    $box = $response->getBox('where');
    $on_blue = $this->boxError($box, ['top' => 0.25, 'bottom' => 0.75, 'left' => 0.25, 'right' => 0.5]) <= 0.05;
    return $this->result($present < 0.5 ? 'PASS' : 'WARN', sprintf('present P=%s (expected < 0.5); box %s%s, confidence %s', $this->p($present), $this->edges($box), $on_blue ? ' (on the blue rectangle)' : '', $this->p($box->getConfidence())));
  }

  /**
   * Builds a Decision input longer than the default 64k-token context.
   *
   * About 3,600 log lines (roughly 380,000 characters) with one refund
   * request far from both ends.
   */
  private function longLog(): DecisionInput {
    $cities = ['Lisbon', 'Osaka', 'Denver', 'Nairobi', 'Oslo', 'Lima', 'Perth', 'Cairo'];
    $lines = [];
    for ($i = 1; $i <= 3600; $i++) {
      $lines[] = sprintf('Line %d: Order %d shipped from the %s warehouse on day %d and is expected within %d business days.', $i, 10000 + $i, $cities[$i % 8], $i % 28 + 1, $i % 5 + 2);
    }
    $lines[2718] = 'Line 2719: The customer asked for a refund of order 12719 because the box arrived damaged.';
    return new DecisionInput(['log' => implode("\n", $lines)], [
      'refund' => new NoulQuestion('Does any line in `log` describe a customer asking for a refund?'),
    ]);
  }

  /**
   * Check L02: Long state without long context: rejected with the hint.
   *
   * This checks the provider's max_tokens_exceeded detection against the
   * API's real error.
   */
  private function checkL02(): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->decide($this->longLog());
      $tokens = number_format($this->tokensIn());
      return ['WARN', "Accepted without long context ($tokens input tokens); TypeSafe may have raised the limit."];
    }
    catch (AiBadRequestException $e) {
      $requests = array_slice($this->recorder->snapshot()['http'], $before);
      $statuses = implode(',', array_map(static fn (array $request): string => (string) $request['status'], $requests));
      $message = mb_strimwidth($e->getMessage(), 0, 320, '…');
      if (count($requests) !== 1 || $requests[0]['status'] !== 400) {
        return ['FAIL', "Expected one HTTP 400, got $statuses: $message"];
      }
      if (!str_contains($e->getMessage(), 'long_context')) {
        $problem = "Rejected without the long_context hint, so the provider's detection does not match the API";
        return ['FAIL', "$problem: $message"];
      }
      return ['PASS', "AiBadRequestException after one HTTP 400: $message"];
    }
  }

  /**
   * Check L03: Long state with long context: answered.
   */
  private function checkL03(): array {
    $provider = $this->provider();
    $provider->setConfiguration(['long_context' => TRUE]);
    $p = $this->decide($this->longLog(), NULL, $provider)->getNoul('refund')->getProbability();
    $request = $this->lastRequest();
    $tokens = $this->tokensIn();
    if ($this->http() !== 1 || !str_contains($request, 'TypeSafe-Danger-Zone-Long-Context')) {
      return ['FAIL', sprintf('%d HTTP request(s); %s', $this->http(), $request)];
    }
    if ($tokens <= 64000) {
      $detail = sprintf('Only %s input tokens, within the default context', number_format($tokens));
      return ['WARN', "$detail, so this does not test long context; refund P=" . $this->p($p)];
    }
    return $this->result($p > 0.5 ? 'PASS' : 'WARN', sprintf('%s input tokens; refund line found P=%s; %s', number_format($tokens), $this->p($p), $request));
  }

}
