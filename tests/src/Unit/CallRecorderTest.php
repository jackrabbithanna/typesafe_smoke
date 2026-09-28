<?php

declare(strict_types=1);

namespace Drupal\Tests\typesafe_smoke\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LogMessageParser;
use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai_automators\Event\ValuesChangeEvent;
use Drupal\typesafe_smoke\CallRecorder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests recording of early failures and generated field values.
 */
#[Group('typesafe_smoke')]
class CallRecorderTest extends TestCase {

  /**
   * A validation exception needs no preceding pre-generation event.
   */
  public function testEarlyException(): void {
    $recorder = new CallRecorder(new LogMessageParser());
    $event = new AiExceptionEvent(new AiBadRequestException('Invalid input'), 'thread', 'typesafeai', 'decision', [], 'bad input', 'jev-latest');
    $recorder->onException($event);
    $this->assertSame([], $recorder->snapshot()['calls']);
    $recorder->start('test');
    $recorder->segment('B13');
    $recorder->onException($event);
    $recorder->onException($event);
    $calls = $recorder->stop()['calls'];
    $this->assertCount(1, $calls);
    $this->assertSame('exception', $calls[0]['status']);
    $this->assertSame('Invalid input', $calls[0]['detail']);
    $this->assertSame(0, $calls[0]['http']);
    $this->assertFalse($recorder->isActive());
  }

  /**
   * Generated values remain segment-scoped and are reset between runs.
   */
  public function testGeneratedValues(): void {
    $recorder = new CallRecorder(new LogMessageParser());
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getName')->willReturn('field_tss_mtg_follow_up');
    $event = new ValuesChangeEvent([TRUE], $this->createMock(ContentEntityInterface::class), $field, ['id' => 'automator']);
    $recorder->start('test');
    $recorder->segment('A');
    $recorder->onValuesChange($event);
    $recorder->segment('B');
    $this->assertSame([], $recorder->snapshot('B')['values']);
    $this->assertSame([TRUE], $recorder->snapshot('A')['values'][0]['values']);
    $recorder->stop();
    $recorder->onValuesChange($event);
    $this->assertCount(1, $recorder->snapshot()['values']);
    $recorder->start('next');
    $this->assertSame([], $recorder->snapshot()['values']);
  }

}
