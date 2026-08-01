<?php

namespace satoken;

use think\Service as ThinkService;

class Service extends ThinkService
{
    public function register(): void
    {
        $this->app->bind(SatokenInterface::class, SaToken::class);
    }
}
