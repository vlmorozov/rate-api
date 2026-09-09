<?php

declare(strict_types=1);

namespace App\Service\Downloader;

interface DownloaderInterface
{
    public function download(string $url): string;
}
