<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiExceptionInterface;
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
use Drupal\ai\OperationType\Decision\Value\ChoiceAnswer;
use Drupal\ai\OperationType\Decision\Value\ScoreAnswer;
use Drupal\ai\OperationType\Decision\Value\ScoreQuestion;
use Drupal\ai\OperationType\Decision\Value\YesNoQuestion;

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
  ) {}

  /**
   * Lists check IDs and labels, grouped by prefix.
   */
  public function checks(): array {
    return [
      'C01' => 'Capabilities declared locally (no HTTP)',
      'C02' => 'Model discovery',
      'B01' => 'Yes/no, positive case',
      'B02' => 'Yes/no, negative case',
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
      'G01' => 'Guardrail: card number blocks before HTTP',
      'G02' => 'Guardrail: length limit blocks before HTTP',
      'G03' => 'Guardrail: TypeSafe moderation blocks abusive state',
      'G04' => 'Guardrail: post-phase block after the call',
      'G05' => 'Guardrail: unsupported plugin is a setup failure',
      'T01' => 'Text classification with labels',
      'T02' => 'Text classification without labels rejected before HTTP',
      'T03' => 'Text classification with custom instructions',
      'T04' => 'Text classification removes duplicate and blank labels',
      'M01' => 'Moderation, benign text',
      'M02' => 'Moderation, abusive text',
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
      'G01' => $this->checkG01(),
      'G02' => $this->checkG02(),
      'G03' => $this->checkG03(),
      'G04' => $this->checkG04(),
      'G05' => $this->checkG05(),
      'T01' => $this->checkT01(),
      'T02' => $this->checkT02(),
      'T03' => $this->checkT03(),
      'T04' => $this->checkT04(),
      'M01' => $this->checkM01(),
      'M02' => $this->checkM02(),
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
    $expected = array_filter(AiModelCapability::cases(), static fn (AiModelCapability $c): bool => $c->getBaseOperationType() === 'decision');
    $missing = array_filter($expected, static fn (AiModelCapability $c): bool => !$declared->has($c));
    $problems = [];
    if ($missing) {
      $problems[] = 'missing ' . implode(', ', array_map(static fn ($c) => $c->value, $missing));
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
    return $this->result('PASS', count($expected) . ' Decision capabilities declared; limits 255 options / 10 levels / no question limit; no HTTP.');
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
   * Check B01: Yes/no, positive case.
   */
  private function checkB01(): array {
    $answer = $this->decide(new DecisionInput('The customer writes: "I was charged twice this month. Please refund the duplicate payment."', [
      'refund' => new YesNoQuestion('Is the customer asking for money back?'),
    ]))->getYesNo('refund');
    $p = $answer->getProbability();
    if ($p < 0 || $p > 1) {
      return ['FAIL', 'Probability out of range: ' . $p];
    }
    return [$p >= 0.5 ? 'PASS' : 'WARN', 'P(yes) = ' . $this->p($p) . ' (expected >= 0.5)'];
  }

  /**
   * Check B02: Yes/no, negative case.
   */
  private function checkB02(): array {
    $answer = $this->decide(new DecisionInput('The customer writes: "The new dashboard is great, thank you!"', [
      'refund' => new YesNoQuestion('Is the customer asking for money back?'),
    ]))->getYesNo('refund');
    $p = $answer->getProbability();
    if ($p < 0 || $p > 1) {
      return ['FAIL', 'Probability out of range: ' . $p];
    }
    return [$p < 0.5 ? 'PASS' : 'WARN', 'P(yes) = ' . $this->p($p) . ' (expected < 0.5)'];
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
      'churn' => new YesNoQuestion(
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
    return $this->result('PASS', sprintf('%d capabilities required; churn P=%s, team %s, risk level %d', count($required), $this->p($response->getYesNo('churn')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('risk')->getMostLikelyLevel()));
  }

  /**
   * Builds the three-question request used by B07 and B08.
   */
  private function multiInput(): DecisionInput {
    return new DecisionInput(['ticket' => ['message' => 'I was charged twice and I am furious. Refund me today or I cancel.']], [
      'refund' => new YesNoQuestion('Is the customer asking for money back?'),
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
    $ok = $response->getYesNo('refund')->isLikely() && $response->getChoice('team')->getChoice() === 'billing' && $response->getScore('anger')->getMostLikelyLevel() === 2;
    return $this->result($ok ? 'PASS' : 'WARN', sprintf('one HTTP request; refund P=%s, team %s, anger level %d', $this->p($response->getYesNo('refund')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('anger')->getMostLikelyLevel()));
  }

  /**
   * Check B08: Same request on jev-preview.
   */
  private function checkB08(): array {
    $response = $this->decide($this->multiInput(), 'jev-preview');
    if (count($response->getAnswers()) !== 3) {
      return ['FAIL', count($response->getAnswers()) . ' answers'];
    }
    return $this->result('PASS', sprintf('response model "%s"; refund P=%s, team %s, anger level %d', $response->getModel(), $this->p($response->getYesNo('refund')->getProbability()), $response->getChoice('team')->getChoice(), $response->getScore('anger')->getMostLikelyLevel()));
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
      $this->decide(new DecisionInput('Hello', ['q' => new YesNoQuestion('Is this a greeting?')]), 'jev-does-not-exist');
      return ['WARN', 'The API accepted an unknown model ID.'];
    }
    catch (AiExceptionInterface $e) {
      $requests = array_slice($this->recorder->snapshot()['http'], $before);
      $statuses = implode(',', array_map(static fn ($r) => (string) $r['status'], $requests));
      return $this->result('PASS', (new \ReflectionClass($e))->getShortName() . " after HTTP $statuses: " . mb_strimwidth($e->getMessage(), 0, 160, '…'));
    }
  }

  /**
   * Check B15: Invalid API key.
   */
  private function checkB15(): array {
    $provider = $this->provider();
    $provider->setAuthentication('tss-invalid-key-000000');
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->decide(new DecisionInput('Hello', ['q' => new YesNoQuestion('Is this a greeting?')]), NULL, $provider);
      return ['FAIL', 'The API accepted an invalid key.'];
    }
    catch (\Throwable $e) {
      $requests = array_slice($this->recorder->snapshot()['http'], $before);
      $statuses = array_map(static fn ($r) => $r['status'], $requests);
      $short = (new \ReflectionClass($e))->getShortName();
      if ($e instanceof AiSetupFailureException && in_array(401, $statuses, TRUE)) {
        return ['PASS', "$short after HTTP 401 (" . count($requests) . ' request, no retry)'];
      }
      return $this->result('WARN', "$short after HTTP " . implode(',', $statuses) . ': ' . mb_strimwidth($e->getMessage(), 0, 160, '…'));
    }
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
      'refund' => new YesNoQuestion('Is the customer asking for money back?'),
    ])), 'TSS-GR-CARD', AiGuardrailModeEnum::PreGenerate, 0);
  }

  /**
   * Check G02: Guardrail: length limit blocks before HTTP.
   */
  private function checkG02(): array {
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_intake', new DecisionInput(['message' => str_repeat('The import failed again today. ', 170)], [
      'bug' => new YesNoQuestion('Is this a bug report?'),
    ])), 'TSS-GR-LENGTH', AiGuardrailModeEnum::PreGenerate, 0);
  }

  /**
   * Check G03: Guardrail: TypeSafe moderation blocks abusive state.
   */
  private function checkG03(): array {
    $before = count($this->recorder->snapshot()['http']);
    try {
      $this->guarded('typesafe_smoke_intake', new DecisionInput(['message' => $this->fixtures->probes()['abusive']], [
        'escalate' => new YesNoQuestion('Should this ticket be escalated?'),
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
   * Check G04: Guardrail: post-phase block after the call.
   */
  private function checkG04(): array {
    return $this->expectBlocked(fn () => $this->guarded('typesafe_smoke_post_probe', new DecisionInput('Hello there!', [
      'post_probe_target' => new YesNoQuestion('Is this a greeting?'),
    ])), 'TSS-GR-POST', AiGuardrailModeEnum::PostGenerate, 1);
  }

  /**
   * Check G05: Guardrail: unsupported plugin is a setup failure.
   */
  private function checkG05(): array {
    return $this->expectRejected(fn () => $this->guarded('typesafe_smoke_unsupported_probe', new DecisionInput('Hello', [
      'q' => new YesNoQuestion('Is this a greeting?'),
    ])), AiSetupFailureException::class);
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

}
