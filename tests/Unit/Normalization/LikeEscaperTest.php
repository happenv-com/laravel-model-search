<?php

declare(strict_types=1);

use Happenv\ModelSearch\Normalization\LikeEscaper;

covers(LikeEscaper::class);

it('like metacharacters are literal', function (): void {
    expect(LikeEscaper::escape('50%_\\'))->toBe('50\%\_\\\\');
});

it('leaves ordinary text untouched', function (): void {
    expect(LikeEscaper::escape('kowalski'))->toBe('kowalski');
});
