<?php

declare(strict_types=1);

namespace App\Service\Downloader;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(DownloaderInterface::class)]
final class Downloader implements DownloaderInterface
{
    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    public function download(string $url): string
    {
        return $this->client->request('GET', $url)->getContent();
    }
}
