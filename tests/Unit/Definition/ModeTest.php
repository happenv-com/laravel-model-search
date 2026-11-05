<?php

declare(strict_types=1);

use Happenv\ModelSearch\Definition\Mode;

covers(Mode::class);

it('ranks the stronger modes first', function (): void {
    expect(Mode::Exact->rank())->toBeLessThan(Mode::Prefix->rank())
        ->and(Mode::Prefix->rank())->toBeLessThan(Mode::Suffix->rank())
        ->and(Mode::Suffix->rank())->toBeLessThan(Mode::Infix->rank())
        ->and(Mode::Infix->rank())->toBeLessThan(Mode::Fuzzy->rank());
});

it('round trips through its rank', function (): void {
    foreach ([Mode::Exact, Mode::Prefix, Mode::Suffix, Mode::Infix, Mode::Fuzzy] as $mode) {
        expect(Mode::fromRank($mode->rank()))->toBe($mode);
    }
});
