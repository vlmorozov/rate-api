<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\RatesUnavailableException;
use App\Request\ConvertRequest;
use App\Request\RatesRequest;
use App\Service\CurrencyConverter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', format: 'json')]
final class RateController extends AbstractController
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/rates', name: 'api_rates', methods: ['GET'])]
    public function rates(
        #[MapQueryString(
            validationFailedStatusCode: Response::HTTP_BAD_REQUEST,
            mapWhenEmpty: true,
        )]
        RatesRequest $request,
    ): JsonResponse {
        try {
            $base = $request->base;

            $ratesResult = $this->converter->rates($base);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception);
        }

        return $this->successResponse($ratesResult->rates);
    }

    #[Route('/convert', name: 'api_convert', methods: ['GET'])]
    public function convert(
        #[MapQueryString(
            validationFailedStatusCode: Response::HTTP_BAD_REQUEST,
            mapWhenEmpty: true,
        )]
        ConvertRequest $request,
    ): JsonResponse {
        try {
            $amount = $request->amount;
            $from = $request->from;
            $to = $request->to;

            $convertResult = $this->converter->convert(
                amount: $amount,
                from: $from,
                to: $to,
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception);
        }

        return $this->successResponse($convertResult);
    }

    private function successResponse(array|object $data): JsonResponse
    {
        return $this->json($data, Response::HTTP_OK);
    }

    private function errorResponse(\Throwable $exception): JsonResponse
    {
        $this->logger->error('Rate API request failed.', ['exception' => $exception]);

        $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        $message = 'Internal server error.';

        if ($exception instanceof \InvalidArgumentException) {
            $status = Response::HTTP_BAD_REQUEST;
            $message = $exception->getMessage();
        }

        if ($exception instanceof RatesUnavailableException) {
            $status = Response::HTTP_SERVICE_UNAVAILABLE;
            $message = 'Rates are currently unavailable.';
        }

        return $this->json([
            'success' => false,
            'error' => $message,
        ], $status);
    }
}
