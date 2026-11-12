<?php

declare(strict_types=1);

use App\Engines\Scripts\PostgresSql;
use App\Engines\Scripts\Shell;

it('quotes postgres literals and identifiers by doubling the quote', function () {
    $sql = new PostgresSql;

    expect($sql->string("o'b\\r\"\n--x"))->toBe("'o''b\\r\"\n--x'")
        ->and($sql->name('a"b'))->toBe('"a""b"');
});

it('quotes shell words so nothing expands', function () {
    expect((new Shell)->arg('it\'s $HOME'))->toBe("'it'\\''s \$HOME'");
});

it('refuses a NUL byte', function (Closure $quote) {
    expect(fn () => $quote("a\0b"))->toThrow(InvalidArgumentException::class);
})->with([
    'sql string' => [fn (string $value): string => (new PostgresSql)->string($value)],
    'sql name' => [fn (string $value): string => (new PostgresSql)->name($value)],
    'shell word' => [fn (string $value): string => (new Shell)->arg($value)],
]);
