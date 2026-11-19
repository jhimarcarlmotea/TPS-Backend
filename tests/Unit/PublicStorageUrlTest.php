<?php

namespace Tests\Unit;

use App\Support\PublicStorageUrl;
use Tests\TestCase;

class PublicStorageUrlTest extends TestCase
{
    public function test_it_generates_urls_using_the_public_disk_configuration(): void
    {
        $path = 'receipts/example.jpg';
        $baseUrl = rtrim(config('filesystems.disks.public.url'), '/');

        $this->assertSame(
            $baseUrl . '/' . $path,
            PublicStorageUrl::fromPath($path)
        );
    }
}
