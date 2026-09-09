<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\RatesUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(
    event: KernelEvents::EXCEPTION,
    priority: 10
)]
final class RatesUnavailableExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        ExceptionEvent $event,
    ): void {
        $exception = $event->getThrowable();
        if ('json' !== $event->getRequest()->getRequestFormat() || !$exception instanceof RatesUnavailableException) {
            return;
        }

        $this->logger->error('Rate API request failed.', ['exception' => $exception]);
        $event->setResponse(new JsonResponse([
            'success' => false,
            'error' => 'Rates are currently unavailable.',
        ], Response::HTTP_SERVICE_UNAVAILABLE));
    }
}
