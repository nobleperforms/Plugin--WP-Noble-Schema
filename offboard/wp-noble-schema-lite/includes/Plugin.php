<?php

declare(strict_types=1);

namespace NobleSchemaLite;

final class Plugin {
    public static function boot(): void {
        (new Frontend())->register();
        (new Meta_Box())->register();
        (new Admin())->register();
    }
}
