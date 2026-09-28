<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Event\AiProviderRequestBaseEvent;
use Drupal\ai_automators\Event\ValuesChangeEvent;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records AI provider calls, HTTP requests and automator log entries.
 *
 * Inactive until start() is called, so it is inert during normal site use.
 * The AI provider proxy dispatches pre-generation events before guardrails
 * run, so a call that is still open when the next automator starts was
 * stopped before the provider ran (a guardrail block or setup failure). Calls
 * that responded but never passed the late post-generation listener were
 * blocked after the provider ran.
 */
final class CallRecorder implements EventSubscriberInterface, LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Whether recording is active.
   */
  private bool $active = FALSE;

  /**
   * The run identifier, added to request tags.
   */
  private string $runId = '';

  /**
   * The current segment label (a fixture or check ID).
   */
  private string $segment = '';

  /**
   * The automator being processed, from ProcessFieldEvent.
   */
  private ?string $automator = NULL;

  /**
   * Recorded calls.
   *
   * @var array<int, array>
   */
  private array $calls = [];

  /**
   * Indexes of open calls, innermost last.
   *
   * @var int[]
   */
  private array $stack = [];

  /**
   * Call indexes keyed by request thread ID.
   *
   * @var array<string, int>
   */
  private array $threads = [];

  /**
   * Recorded HTTP requests.
   *
   * @var array<int, array>
   */
  private array $http = [];

  /**
   * Recorded automator log entries.
   *
   * @var array<int, array>
   */
  private array $logs = [];

  /**
   * Generated field values, captured before field validation and storage.
   *
   * @var array<int, array>
   */
  private array $values = [];

  /**
   * Constructs the recorder.
   */
  public function __construct(
    private readonly LogMessageParserInterface $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 200],
      PostGenerateResponseEvent::EVENT_NAME => [
        ['onPostGenerate', 200],
        ['onPostGenerateComplete', -200],
      ],
      AiExceptionEvent::class => ['onException', 200],
      // Dispatched by AI Automators for every field it considers processing.
      'ai_automator.process_field' => ['onProcessField', -200],
      ValuesChangeEvent::EVENT_NAME => ['onValuesChange', PHP_INT_MIN],
    ];
  }

  /**
   * Starts a new recording.
   */
  public function start(string $run_id): void {
    $this->active = TRUE;
    $this->runId = $run_id;
    $this->segment = '';
    $this->automator = NULL;
    $this->calls = [];
    $this->stack = [];
    $this->threads = [];
    $this->http = [];
    $this->logs = [];
    $this->values = [];
  }

  /**
   * Stops recording and returns everything recorded.
   */
  public function stop(): array {
    $this->closeOpen();
    $this->active = FALSE;
    return $this->snapshot();
  }

  /**
   * Whether recording is active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Starts a segment; later records carry its label.
   */
  public function segment(string $label): void {
    $this->closeOpen();
    $this->segment = $label;
    $this->automator = NULL;
  }

  /**
   * Returns the recorded data, optionally for one segment only.
   */
  public function snapshot(?string $segment = NULL): array {
    $filter = static fn (array $items): array => $segment === NULL ? $items : array_values(array_filter($items, static fn (array $item): bool => $item['segment'] === $segment));
    return [
      'run' => $this->runId,
      'calls' => $filter($this->calls),
      'http' => $filter($this->http),
      'logs' => $filter($this->logs),
      'values' => $filter($this->values),
    ];
  }

  /**
   * Marks open calls as stopped before or after the provider ran.
   */
  public function closeOpen(string $reason = ''): void {
    foreach ($this->calls as $index => $call) {
      if ($call['status'] === 'open') {
        $this->calls[$index]['status'] = 'aborted_pre';
        $this->calls[$index]['detail'] = $this->calls[$index]['detail'] ?: $reason;
      }
      elseif ($call['status'] === 'responded') {
        $this->calls[$index]['status'] = 'blocked_post';
        $this->calls[$index]['detail'] = $this->calls[$index]['detail'] ?: $reason;
      }
    }
    $this->stack = [];
  }

  /**
   * Records the start of a provider call and tags it.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!$this->active) {
      return;
    }
    $tags = $event->getTags();
    $extra = ['typesafe_smoke', 'typesafe_smoke:run:' . $this->runId];
    if ($this->segment !== '') {
      $extra[] = 'typesafe_smoke:segment:' . $this->segment;
    }
    $event->setTags(array_values(array_unique(array_merge($tags, $extra))));

    $this->beginCall($event);
  }

  /**
   * Records a call, including rejections before pre-generation dispatch.
   */
  private function beginCall(AiProviderRequestBaseEvent $event): int {
    $tags = $event->getTags();
    $automator = $this->automator;
    foreach ($tags as $tag) {
      if (is_string($tag) && str_starts_with($tag, 'ai_automator:id:')) {
        $automator = substr($tag, 16);
      }
    }
    $index = count($this->calls);
    $this->calls[] = [
      'segment' => $this->segment,
      'automator' => $automator,
      'operation' => $event->getOperationType(),
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'parent' => $this->stack === [] ? NULL : end($this->stack),
      'status' => 'open',
      'detail' => '',
      'exception' => NULL,
      'started' => microtime(TRUE),
      'ms' => NULL,
      'http' => 0,
      'tokens_in' => NULL,
      'tokens_out' => NULL,
      'response_model' => NULL,
    ];
    $this->stack[] = $index;
    $this->threads[$event->getRequestThreadId()] = $index;
    return $index;
  }

  /**
   * Records the provider response, before post-generation guardrails.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    $index = $this->active ? ($this->threads[$event->getRequestThreadId()] ?? NULL) : NULL;
    if ($index === NULL) {
      return;
    }
    $this->calls[$index]['status'] = 'responded';
    $this->calls[$index]['ms'] = (int) round((microtime(TRUE) - $this->calls[$index]['started']) * 1000);
    $output = $event->getOutput();
    if (method_exists($output, 'getTokenUsage')) {
      $usage = $output->getTokenUsage();
      $this->calls[$index]['tokens_in'] = $usage->input;
      $this->calls[$index]['tokens_out'] = $usage->output;
    }
    $raw = $output->getRawOutput();
    if (is_array($raw) && isset($raw['model']) && is_string($raw['model'])) {
      $this->calls[$index]['response_model'] = $raw['model'];
    }
    $this->pop($index);
  }

  /**
   * Marks a call complete once every post-generation listener has passed.
   */
  public function onPostGenerateComplete(PostGenerateResponseEvent $event): void {
    $index = $this->active ? ($this->threads[$event->getRequestThreadId()] ?? NULL) : NULL;
    if ($index !== NULL && $this->calls[$index]['status'] === 'responded') {
      $this->calls[$index]['status'] = 'ok';
    }
  }

  /**
   * Records a provider exception.
   */
  public function onException(AiExceptionEvent $event): void {
    if (!$this->active) {
      return;
    }
    $index = $this->threads[$event->getRequestThreadId()] ?? $this->beginCall($event);
    $this->calls[$index]['status'] = 'exception';
    $this->calls[$index]['exception'] = get_class($event->getException());
    $this->calls[$index]['detail'] = $event->getMessage();
    $this->calls[$index]['ms'] = (int) round((microtime(TRUE) - $this->calls[$index]['started']) * 1000);
    $this->pop($index);
  }

  /**
   * Tracks which automator runs next; closes calls a guardrail stopped.
   */
  public function onProcessField(object $event): void {
    if (!$this->active) {
      return;
    }
    $this->closeOpen();
    $config = $event->automatorConfig ?? [];
    $this->automator = is_array($config) && isset($config['id']) ? (string) $config['id'] : NULL;
  }

  /**
   * Captures the final generated scalar values without retaining entities.
   */
  public function onValuesChange(ValuesChangeEvent $event): void {
    if (!$this->active) {
      return;
    }
    $this->values[] = [
      'segment' => $this->segment,
      'automator' => $event->getAutomatorConfig()['id'] ?? $this->automator,
      'field' => $event->getFieldDefinition()->getName(),
      'values' => $event->getValues(),
    ];
  }

  /**
   * Records one HTTP request made while recording.
   */
  public function recordHttp(RequestInterface $request, ?int $status, int $ms, ?string $error = NULL): void {
    if (!$this->active) {
      return;
    }
    $index = $this->stack === [] ? NULL : end($this->stack);
    if ($index !== NULL) {
      $this->calls[$index]['http']++;
    }
    $this->http[] = [
      'segment' => $this->segment,
      'call' => $index,
      'method' => $request->getMethod(),
      'host' => $request->getUri()->getHost(),
      'path' => $request->getUri()->getPath(),
      'status' => $status,
      'ms' => $ms,
      'error' => $error,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (!$this->active || ($context['channel'] ?? '') !== 'ai_automator') {
      return;
    }
    $placeholders = $this->parser->parseMessagePlaceholders($message, $context);
    $text = strip_tags(empty($placeholders) ? (string) $message : strtr((string) $message, $placeholders));
    $this->logs[] = [
      'segment' => $this->segment,
      'automator' => $this->automator,
      'level' => $level,
      'message' => $text,
    ];
    // The direct worker logs after catching the exception that ended the
    // automator run, so any call still open was stopped by it.
    $this->closeOpen($text);
  }

  /**
   * Removes a finished call from the open stack.
   */
  private function pop(int $index): void {
    $this->stack = array_values(array_filter($this->stack, static fn (int $open): bool => $open !== $index));
  }

}
