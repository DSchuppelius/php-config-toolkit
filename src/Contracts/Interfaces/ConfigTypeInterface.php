<?php

declare(strict_types=1);

namespace ConfigToolkit\Contracts\Interfaces;

interface ConfigTypeInterface {
    public function parse(array $data): array;
    public static function matches(array $data): bool;
    public function validate(array $data): array;
}
