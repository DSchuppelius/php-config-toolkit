<?php

declare(strict_types=1);

namespace Tests\test_classes\sub;

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;

class SubValidClass implements ConfigTypeInterface {
    public function parse(array $data): array {
        return ["id" => "54321", "reversdata" => "cba"];
    }

    public function matches(array $data): bool {
        return true;
    }

    public function validate(array $data): array {
        return [];
    }
}