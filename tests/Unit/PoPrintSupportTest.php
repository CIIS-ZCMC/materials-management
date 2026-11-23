<?php

use App\Support\Print\AmountInWords;
use App\Support\Print\Fonts;
use App\Support\Print\Paginator;
use App\Support\Print\PrintFormat;

/*
 * Step 4.6: the small pure pieces of the PO print (they need no database and no PDF engine).
 * The values are the ones printed on the real scans 01 and 02 (docs/PO-PRINT-SPIKE.md).
 */

it('writes the total in words as printed on the PO scans', function (int $centavos, string $expected) {
    expect(AmountInWords::amount($centavos, 'Cents Only'))->toBe($expected);
})->with([
    'scan 01 (5,400,000.00)' => [540000000, 'Five Million Four Hundred Thousand and 0/100 Cents Only'],
    'scan 02 (234,820.00)' => [23482000, 'Two Hundred Thirty-Four Thousand Eight Hundred Twenty and 0/100 Cents Only'],
    'one peso' => [100, 'One and 0/100 Cents Only'],
    'twenty-one pesos' => [2100, 'Twenty-One and 0/100 Cents Only'],
    'centavos are two digits' => [105, 'One and 05/100 Cents Only'],
    'only centavos' => [1, 'Zero and 01/100 Cents Only'],
    'a million and one' => [100000100, 'One Million One and 0/100 Cents Only'],
    'billions' => [123456789012, 'One Billion Two Hundred Thirty-Four Million Five Hundred Sixty-Seven Thousand Eight Hundred Ninety and 12/100 Cents Only'],
]);

it('uses the lower-case ending of form 04A when asked', function () {
    expect(AmountInWords::amount(5180, 'cents only'))->toBe('Fifty-One and 80/100 cents only');
});

it('formats money from whole centavos and quantities with thousands separators', function () {
    expect(PrintFormat::money(540000000))->toBe('5,400,000.00')
        ->and(PrintFormat::money(5))->toBe('0.05')
        ->and(PrintFormat::money(123456))->toBe('1,234.56')
        ->and(PrintFormat::qty(15000))->toBe('15,000');
});

it('puts lines on pages by height and keeps room for the total rows on the last page', function () {
    $pages = Paginator::paginate(array_fill(0, 10, 10.0), 50.0, 50.0, 0.0);
    expect(array_map(fn ($p) => count($p['items']), $pages))->toBe([5, 5]);

    // The last page is full, so one line moves to a new page to make room for the totals.
    $withTotals = Paginator::paginate(array_fill(0, 10, 10.0), 50.0, 50.0, 20.0);
    expect(array_map(fn ($p) => count($p['items']), $withTotals))->toBe([5, 4, 1]);
});

it('gives an empty PO one page, and a totals-only page when a single line leaves no room', function () {
    expect(Paginator::paginate([], 50.0, 50.0, 20.0))->toHaveCount(1);

    $pages = Paginator::paginate(array_fill(0, 5, 40.0), 50.0, 50.0, 20.0);
    expect(array_map(fn ($p) => count($p['items']), $pages))->toBe([1, 1, 1, 1, 1, 0]);
});

it('honours a maximum number of lines per page', function () {
    $pages = Paginator::paginate(array_fill(0, 25, 10.0), 100.0, 100.0, 0.0, 10);
    expect(array_map(fn ($p) => count($p['items']), $pages))->toBe([10, 10, 5]);
});

it('measures text with the shipped Liberation fonts (known value 145.84 pt)', function () {
    $fonts = new Fonts(dirname(__DIR__, 2).'/resources/fonts');

    expect($fonts->real)->toBeTrue()
        ->and($fonts->width('Amoxicillin 250mg capsule (as trihydrate)', Fonts::SANS, false, 8.0))->toEqualWithDelta(145.84, 0.05);
});
