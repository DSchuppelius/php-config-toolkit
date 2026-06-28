<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigTypes\StructuredConfigType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests für das Typ-Casting in ConfigTypeAbstract::castValue().
 *
 * Insbesondere Regressionstest für den Typ 'list': fehlte früher im match()
 * und fiel auf `default => (string) $value` zurück, was bei Array-Werten eine
 * "Array to string conversion"-Warnung auslöste.
 */
class ConfigTypeCastTest extends TestCase {
    private function cast(mixed $value, string $type): mixed {
        $type_obj = (new \ReflectionClass(StructuredConfigType::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($type_obj, 'castValue');
        $m->setAccessible(true);
        return $m->invoke($type_obj, $value, $type);
    }

    public function test_list_empty_array_stays_array_without_warning(): void {
        $handler = set_error_handler(function (int $no, string $str): bool {
            $this->fail("Unerwartete PHP-Meldung beim list-Cast: $str");
        });
        try {
            $this->assertSame([], $this->cast([], 'list'));
        } finally {
            restore_error_handler();
            unset($handler);
        }
    }

    public function test_list_json_array_string_is_decoded(): void {
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $this->cast('["10.0.0.1","10.0.0.2"]', 'list'));
    }

    public function test_list_null_yields_empty_array(): void {
        $this->assertSame([], $this->cast(null, 'list'));
    }

    public function test_list_matches_array_and_json_semantics(): void {
        // 'list' verhält sich identisch zu 'array'/'json'
        $input = ['a', 'b'];
        $this->assertSame(
            $this->cast($input, 'array'),
            $this->cast($input, 'list')
        );
    }
}
