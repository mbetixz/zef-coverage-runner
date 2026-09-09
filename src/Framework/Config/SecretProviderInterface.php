<?php

declare(strict_types=1);

namespace Zef\Framework\Config {

    interface SecretProviderInterface
    {
        public function get(string $name): ?SecretValue;
    }

}
