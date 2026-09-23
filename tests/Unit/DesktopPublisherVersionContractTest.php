<?php

namespace Tests\Unit;

use JsonException;
use Tests\TestCase;

class DesktopPublisherVersionContractTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_server_desktop_package_and_build_metadata_share_one_version(): void
    {
        $package = json_decode(
            (string) file_get_contents(base_path('desktop-publisher/package.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $packageLock = json_decode(
            (string) file_get_contents(base_path('desktop-publisher/package-lock.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $version = (string) config('geoflow.desktop_publisher.version');

        $this->assertSame($version, $package['version']);
        $this->assertSame($version, $packageLock['version']);
        $this->assertSame($version, $packageLock['packages']['']['version']);

        $dockerIgnore = (string) file_get_contents(base_path('.dockerignore'));
        $this->assertStringContainsString(
            "!dist/desktop-publisher/GEOFlow-Desktop-Publisher-{$version}-win-x64.exe",
            $dockerIgnore,
        );
        $this->assertStringContainsString(
            "!dist/desktop-publisher/GEOFlow-Desktop-Publisher-{$version}-win-x64.exe.sig",
            $dockerIgnore,
        );
    }
}
