<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(
    event: KernelEvents::EXCEPTION
)]
final class ValidationExceptionListener
{
    public function __invoke(
        ExceptionEvent $event,
    ): void {
        if ('json' !== $event->getRequest()->getRequestFormat()) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $validation = $exception->getPrevious();
        if (!$validation instanceof ValidationFailedException) {
            return;
        }

        $errors = [];
        foreach ($validation->getViolations() as $violation) {
            $field = $violation->getPropertyPath() ?: 'request';
            $errors[$field][] = (string) $violation->getMessage();
        }

        $event->setResponse(new JsonResponse([
            'success' => false,
            'error' => 'Validation failed.',
            'errors' => $errors,
        ], $exception->getStatusCode(), $exception->getHeaders()));
    }
}
