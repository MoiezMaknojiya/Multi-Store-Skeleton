<?php

/*
|--------------------------------------------------------------------------
| Every parameter of a migration carries its type
|--------------------------------------------------------------------------
|
| Every method, property and closure parameter carries a type (01-tech-and-principles.md #3). The repeat anchor's
| migration (2026_10_15_210000) narrowed its rows with `->where(fn ($repeat) => …)`, the one closure in the folder
| without one (the second review of 2026-10-15). The files are read as PHP tokens: a parameter whose first token is
| its variable — or `&`/`...` straight before it — has none.
|
*/

/**
 * Each untyped parameter of the file, as "line N: $name".
 *
 * @return list<string>
 */
function untypedParametersIn(string $path): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize((string) file_get_contents($path)),
        fn (PhpToken $token) => ! $token->isIgnorable(),
    ));
    $untyped = [];

    foreach ($tokens as $index => $token) {
        // `use function …;` imports a function, it declares none.
        if (! $token->is([T_FUNCTION, T_FN]) || ($tokens[$index - 1] ?? null)?->is(T_USE)) {
            continue;
        }

        $at = $index + 1;
        while (isset($tokens[$at]) && $tokens[$at]->text !== '(') {
            $at++;
        }

        // The parameter list alone: a `use (…)` after it is the closure's captures, not its parameters.
        for ($depth = 0, $first = true; isset($tokens[$at]); $at++) {
            $text = $tokens[$at]->text;

            if (in_array($text, ['(', '['], true) && ++$depth === 1) {
                continue;
            }

            if (in_array($text, [')', ']'], true) && --$depth === 0) {
                break;
            }

            if ($depth === 1 && $text === ',') {
                $first = true;

                continue;
            }

            if ($depth === 1 && $first) {
                $first = false;
                $variable = in_array($text, ['&', '...'], true) ? ($tokens[$at + 1] ?? null) : $tokens[$at];

                if ($variable?->is(T_VARIABLE) && ! $tokens[$at]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $untyped[] = "line {$variable->line}: {$variable->text}";
                }
            }
        }
    }

    return $untyped;
}

test('every closure and method parameter in the migrations carries its type', function () {
    $migrations = glob(database_path('migrations/*.php'));
    expect($migrations)->not->toBeEmpty();

    $untyped = collect($migrations)
        ->mapWithKeys(fn (string $path) => [basename($path) => untypedParametersIn($path)])
        ->filter()
        ->all();

    expect($untyped)->toBe([]);
});

test('the reader finds an untyped parameter, by reference and variadic alike, and passes a typed one', function () {
    $path = tempnam(sys_get_temp_dir(), 'typed');
    file_put_contents($path, '<?php $a = fn ($x) => $x; $b = function (int $y, &$z, ...$rest) use ($a) {}; '
        .'$c = fn (Builder $q, ?int $n = null, array $d = [1, 2]) => $q; function f(string $s = "(") {}');

    try {
        expect(untypedParametersIn($path))->toBe(['line 1: $x', 'line 1: $z', 'line 1: $rest']);
    } finally {
        unlink($path);
    }
});
