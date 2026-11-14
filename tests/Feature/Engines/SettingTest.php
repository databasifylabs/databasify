<?php

declare(strict_types=1);

use App\Engines\Settings\Setting;

it('refuses a text setting that ends in a newline', function () {
    $field = Setting::text('maxmemory', 'Max memory', 'Memory.', '[0-9]{1,15}mb', '0');

    expect(Setting::allows($field, '512mb'))->toBeTrue()
        ->and(Setting::allows($field, "512mb\n"))->toBeFalse();
});
