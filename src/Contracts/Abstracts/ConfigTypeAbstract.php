<?php

declare(strict_types=1);

namespace ConfigToolkit\Contracts\Abstracts;

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;

abstract class ConfigTypeAbstract implements ConfigTypeInterface {
    abstract public function parse(array $data): array;
    abstract public function matches(array $data): bool;
    abstract public function validate(array $data): array;
}
