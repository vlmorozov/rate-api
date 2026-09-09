<?php

declare(strict_types=1);

namespace App\Tests\Service\Downloader;

use App\Service\Downloader\Downloader;
use App\Service\Downloader\DownloaderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DownloaderTest extends KernelTestCase
{
    public function testDownloadReturnsTheResponseContent(): void
    {
        $content = '{"rate":0.91234567890123456789}';
        $response = new MockResponse($content);
        $downloader = new Downloader(new MockHttpClient($response));

        self::assertInstanceOf(DownloaderInterface::class, $downloader);
        self::assertSame($content, $downloader->download('https://provider.example/rates'));
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://provider.example/rates', $response->getRequestUrl());
    }
}
