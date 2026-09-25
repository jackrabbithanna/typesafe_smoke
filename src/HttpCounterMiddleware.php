<?php

declare(strict_types=1);

namespace Drupal\typesafe_smoke;

use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Counts outgoing HTTP requests while the call recorder is active.
 *
 * Providers built through Drupal's HTTP client factory share this handler
 * stack, so the smoke checks can prove that rejected requests never reach
 * the TypeSafe API rather than inferring it from events.
 */
final class HttpCounterMiddleware {

  /**
   * Constructs the middleware.
   */
  public function __construct(
    private readonly CallRecorder $recorder,
  ) {}

  /**
   * Returns the Guzzle middleware.
   */
  public function __invoke(): \Closure {
    return function (callable $handler): \Closure {
      return function (RequestInterface $request, array $options) use ($handler) {
        if (!$this->recorder->isActive()) {
          return $handler($request, $options);
        }
        $started = microtime(TRUE);
        return $handler($request, $options)->then(
          function (ResponseInterface $response) use ($request, $started): ResponseInterface {
            $this->recorder->recordHttp($request, $response->getStatusCode(), (int) round((microtime(TRUE) - $started) * 1000));
            return $response;
          },
          function ($reason) use ($request, $started) {
            $status = is_object($reason) && method_exists($reason, 'getResponse') && $reason->getResponse() ? $reason->getResponse()->getStatusCode() : NULL;
            $this->recorder->recordHttp($request, $status, (int) round((microtime(TRUE) - $started) * 1000), is_object($reason) ? get_class($reason) : (string) $reason);
            return Create::rejectionFor($reason);
          },
        );
      };
    };
  }

}
