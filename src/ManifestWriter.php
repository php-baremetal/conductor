<?php

declare(strict_types=1);

namespace PhpBaremetal\Conductor;

use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Support\Json;

final class ManifestWriter
{
    public function write(string $projectDir, Manifest $manifest): void
    {
        Json::writeAtomic($projectDir . '/' . Manifest::PATH, $manifest->toJson());
    }

    public function remove(string $projectDir): void
    {
        $path = $projectDir . '/' . Manifest::PATH;
        if (is_file($path)) {
            unlink($path);
        }
    }
}
