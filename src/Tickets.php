<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\Core\State\StateInterface;

/**
 * Seeds sample tickets and reports automator results against fixtures.
 */
final class Tickets {

  /**
   * Automator ID prefix for ticket automators.
   */
  public const PREFIX = 'node.typesafe_smoke_ticket.';

  /**
   * Target fields in report order.
   */
  public const FIELDS = [
    'field_tss_refund',
    'field_tss_team',
    'field_tss_topics',
    'field_tss_priority',
    'field_tss_frustration',
    'field_tss_churn_risk',
    'field_tss_sentiment',
    'field_tss_needs_human',
    'field_tss_escalate',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly FixtureRepository $fixtures,
    private readonly SmokeContentManager $content,
    private readonly CallRecorder $recorder,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Estimates calls for a seed run: one per base-mode automator per ticket.
   */
  public function estimate(array $only = []): array {
    $tickets = $this->fixtures->tickets($only);
    $calls = 0;
    foreach ($tickets as $ticket) {
      $has_message = trim((string) $ticket['message']) !== '';
      // Eight base-mode automators need a message; the token-mode one always
      // runs. The intake guardrail adds a moderation call when it gets that
      // far.
      $calls += ($has_message ? 8 : 0) + 1 + ($has_message ? 1 : 0);
    }
    return ['tickets' => count($tickets), 'calls' => $calls];
  }

  /**
   * Creates ticket nodes; each save runs the Decision automators.
   *
   * @return array[]
   *   One row per ticket.
   */
  public function seed(array $only, bool $force, int $sleep_ms): array {
    $rows = [];
    $records = $this->state->get(SmokeContentManager::STATE_SEED) ?? [];
    $this->accountSwitcher->switchTo(new UserSession(['uid' => 1]));
    $this->recorder->start('seed-' . date('Ymd-His'));
    try {
      foreach ($this->fixtures->tickets($only) as $id => $fixture) {
        if ($this->content->loadTicket($id)) {
          if (!$force) {
            $rows[] = [
              'ticket' => $id,
              'result' => 'SKIP',
              'nid' => $this->content->tracked()['nodes'][$id],
              'calls' => 0,
              'http' => 0,
              'ms' => 0,
              'detail' => 'Exists; use --force to recreate.',
            ];
            continue;
          }
          $this->content->deleteTicket($id);
        }
        $this->recorder->segment($id);
        $started = microtime(TRUE);
        try {
          $node = $this->content->createTicket($id, $fixture, 1);
          $result = 'OK';
          $detail = $node->label();
        }
        catch (\Throwable $e) {
          $node = NULL;
          $result = 'FAIL';
          $detail = get_class($e) . ': ' . $e->getMessage();
        }
        $this->recorder->closeOpen();
        $snapshot = $this->recorder->snapshot($id);
        $ms = (int) round((microtime(TRUE) - $started) * 1000);
        $records[$id] = [
          'nid' => $node?->id(),
          'at' => time(),
          'ms' => $ms,
          'calls' => $snapshot['calls'],
          'http' => $snapshot['http'],
          'logs' => $snapshot['logs'],
        ];
        $this->state->set(SmokeContentManager::STATE_SEED, $records);
        $rows[] = [
          'ticket' => $id,
          'result' => $result,
          'nid' => $node?->id(),
          'calls' => count($snapshot['calls']),
          'http' => count($snapshot['http']),
          'ms' => $ms,
          'detail' => $detail . ($snapshot['logs'] ? ' | ' . count($snapshot['logs']) . ' automator log entr' . (count($snapshot['logs']) === 1 ? 'y' : 'ies') : ''),
        ];
        if ($sleep_ms > 0) {
          usleep($sleep_ms * 1000);
        }
      }
    }
    finally {
      $this->recorder->stop();
      $this->accountSwitcher->switchBack();
    }
    return $rows;
  }

  /**
   * Evaluates stored results against the fixtures.
   *
   * @return array{rows: array[], summary: array}
   *   Detail rows and aggregates.
   */
  public function report(array $only = []): array {
    $records = $this->state->get(SmokeContentManager::STATE_SEED) ?? [];
    $rows = [];
    $soft = ['total' => 0, 'ok' => 0, 'by_field' => []];
    $hard_failures = 0;
    $latencies = [];
    $tokens_in = 0;
    $tokens_out = 0;
    $blocks = [];
    foreach ($this->fixtures->tickets($only) as $id => $fixture) {
      $node = $this->content->loadTicket($id);
      if (!$node) {
        $rows[] = $this->reportRow($id, '-', 'hard', 'a seeded node', 'not seeded', 'FAIL');
        $hard_failures++;
        continue;
      }
      $record = $records[$id] ?? ['calls' => [], 'http' => [], 'logs' => []];
      foreach ($record['calls'] as $call) {
        if ($call['operation'] === 'decision' && $call['status'] === 'ok' && $call['ms'] !== NULL) {
          $latencies[] = $call['ms'];
        }
        $tokens_in += (int) $call['tokens_in'];
        $tokens_out += (int) $call['tokens_out'];
      }

      foreach (['expect' => 'soft', 'must' => 'hard'] as $key => $kind) {
        foreach ($fixture[$key] as $field => $matcher) {
          $values = Expectations::values($node, $field);
          $ok = Expectations::matches($values, $matcher);
          $rows[] = $this->reportRow($id, $field, $kind, Expectations::describe($matcher), Expectations::format($values), $ok ? 'OK' : ($kind === 'hard' ? 'FAIL' : 'MISS'));
          if ($kind === 'soft') {
            $soft['total']++;
            $soft['ok'] += (int) $ok;
            $soft['by_field'][$field]['total'] = ($soft['by_field'][$field]['total'] ?? 0) + 1;
            $soft['by_field'][$field]['ok'] = ($soft['by_field'][$field]['ok'] ?? 0) + (int) $ok;
          }
          elseif (!$ok) {
            $hard_failures++;
          }
        }
      }

      // Guardrail blocks attributed to automators through the recorder.
      $logs_by_automator = [];
      foreach ($record['logs'] as $log) {
        $automator = $log['automator'] ? substr($log['automator'], strlen(self::PREFIX)) : '(unknown)';
        $logs_by_automator[$automator][] = $log['message'];
        $code = preg_match('/TSS-GR-[A-Z]+/', $log['message'], $m) ? $m[0] : NULL;
        if ($code) {
          $blocks[] = ['ticket' => $id, 'automator' => $automator, 'code' => $code];
        }
        else {
          // Anything other than a smoke guardrail block is a setup, provider
          // or validation error that the direct worker swallowed.
          $rows[] = $this->reportRow($id, $automator, 'hard', 'no automator errors', mb_strimwidth($log['message'], 0, 140, '…'), 'FAIL');
          $hard_failures++;
        }
      }
      foreach (['blocked' => 'hard', 'expect_blocked' => 'soft'] as $key => $kind) {
        foreach ($fixture[$key] as $automator => $code) {
          $messages = implode(' ', $logs_by_automator[$automator] ?? []);
          $ok = str_contains($messages, $code);
          $rows[] = $this->reportRow($id, $automator, $kind, "blocked by $code", $messages === '' ? 'not blocked' : mb_strimwidth($messages, 0, 90, '…'), $ok ? 'OK' : ($kind === 'hard' ? 'FAIL' : 'MISS'));
          if ($kind === 'hard' && !$ok) {
            $hard_failures++;
          }
          elseif ($kind === 'soft') {
            $soft['total']++;
            $soft['ok'] += (int) $ok;
          }
        }
      }
      foreach ($fixture['no_calls'] as $automator) {
        $count = count(array_filter($record['calls'], static fn (array $call): bool => $call['automator'] === self::PREFIX . $automator && $call['status'] !== 'aborted_pre'));
        $ok = $count === 0;
        $rows[] = $this->reportRow($id, $automator, 'hard', 'no provider call', "$count call(s)", $ok ? 'OK' : 'FAIL');
        $hard_failures += (int) !$ok;
      }
    }
    sort($latencies);
    $percentile = static fn (float $p): ?int => $latencies === [] ? NULL : $latencies[(int) min(count($latencies) - 1, floor($p * count($latencies)))];
    $by_field = [];
    foreach ($soft['by_field'] as $field => $counts) {
      $by_field[$field] = sprintf('%d/%d', $counts['ok'], $counts['total']);
    }
    return [
      'rows' => $rows,
      'blocks' => $blocks,
      'summary' => [
        'soft_ok' => $soft['ok'],
        'soft_total' => $soft['total'],
        'soft_percent' => $soft['total'] ? round(100 * $soft['ok'] / $soft['total'], 1) : NULL,
        'soft_by_field' => $by_field,
        'hard_failures' => $hard_failures,
        'decision_calls_ok' => count($latencies),
        'latency_p50_ms' => $percentile(0.5),
        'latency_p95_ms' => $percentile(0.95),
        'tokens_in' => $tokens_in,
        'tokens_out' => $tokens_out,
      ],
    ];
  }

  /**
   * Builds one report row.
   */
  private function reportRow(string $ticket, string $field, string $kind, string $expected, string $actual, string $result): array {
    return [
      'ticket' => $ticket,
      'field' => str_replace('field_tss_', '', $field),
      'kind' => $kind,
      'expected' => $expected,
      'actual' => $actual,
      'result' => $result,
    ];
  }

}
