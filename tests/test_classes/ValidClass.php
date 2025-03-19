<?php

declare(strict_types=1);

namespace Tests\test_classes;

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;

class ValidClass implements ConfigTypeInterface {
    public function parse(array $data): array {
        return ["id" => "12345", "data" => "abc"];
    }

    public static function matches(array $data): bool {
        return true;
    }

    public function validate(array $data): array {
        return [];
    }
}
