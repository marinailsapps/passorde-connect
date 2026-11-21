<?php

use Passorde\Records\Money;
use Passorde\Records\Text;

it('cleans HTML fragments into plain text', function (mixed $input, ?string $expected) {
    expect(Text::clean($input))->toBe($expected);
})->with([
    'entities' => ['&Eacute;tiquette &amp; caf&eacute;', 'Étiquette & café'],
    'nbsp entity' => ['250&nbsp;g', '250 g'],
    'nbsp character' => ["250\u{00A0}g", '250 g'],
    'narrow nbsp' => ["1\u{202F}000", '1 000'],
    'tags' => ['<p><b>Gras</b></p>', 'Gras'],
    'blocks keep words apart' => ['<p>Un</p><p>Deux</p><br/>Trois', 'Un Deux Trois'],
    'script body' => ['<script>alert(1)</script>Texte', 'Texte'],
    'whitespace' => ["  a \n\t b  ", 'a b'],
    'encoded tag stays text' => ['a &lt; b', 'a < b'],
    'empty after cleaning' => ['<p>&nbsp;</p>', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'array' => [['x'], null],
    'number' => [42, '42'],
    'invalid UTF-8' => ["caf\xE9", 'caf?'],
]);

it('pins Text::clean on HTML as Evoliz stores it', function (string $html, string $expected) {
    expect(Text::clean($html))->toBe($expected);
})->with([
    'designation with markup and entities' => [
        "<p><strong>Caf&eacute; moulu</strong>&nbsp;&ndash; 250&#8239;g</p>\n<p>Origine&nbsp;: <em>Br&eacute;sil</em></p>",
        'Café moulu – 250 g Origine : Brésil',
    ],
    'list and line breaks' => [
        "<ul>\n  <li>Lot&nbsp;A</li><li>Lot B</li>\n</ul>Ligne 1<br>Ligne 2<br />Ligne 3",
        'Lot A Lot B Ligne 1 Ligne 2 Ligne 3',
    ],
    'comment, attributes, style and script' => [
        '<div class="x" style="color:red"><!-- note --><style>p{}</style>Prix&nbsp;HT<script type="text/javascript">x()</script></div>',
        'Prix HT',
    ],
    'encoded markup stays text, quotes decoded' => [
        '<p>&lt;b&gt;pas gras&lt;/b&gt; &quot;cit&eacute;&quot; &#039;ok&#039; &amp;amp;</p>',
        '<b>pas gras</b> "cité" \'ok\' &amp;',
    ],
    'table cells and headings' => [
        '<h2>Titre</h2><table><tr><td>A</td><td>B</td></tr><tr><td>C</td></tr></table>',
        'Titre A B C',
    ],
    'all kinds of blanks' => [
        "\u{00A0}\t Un \u{202F}\u{2009} deux\r\n\u{3000}trois \u{00A0}",
        'Un deux trois',
    ],
]);

it('joins the non-empty parts', function () {
    expect(Text::join(['A&nbsp;B', null, '<p></p>', 'C'], ', '))->toBe('A B, C')
        ->and(Text::join([null, ''], ', '))->toBeNull();
});

it('lower-cases and validates e-mail addresses', function (mixed $input, ?string $expected) {
    expect(Text::email($input))->toBe($expected);
})->with([
    [' Camille@Exemple.ORG ', 'camille@exemple.org'],
    ['pas-une-adresse', null],
    ['', null],
    [null, null],
]);

it('formats amounts with two decimals', function (mixed $input, ?string $expected) {
    expect(Money::orNull($input))->toBe($expected);
})->with([
    [12.5, '12.50'],
    ['80', '80.00'],
    [' 1.5 ', '1.50'],
    [-3.25, '-3.25'],
    [-0.001, '0.00'],
    [0, '0.00'],
    [1234567.891, '1234567.89'],
    [null, null],
    ['', null],
    ['abc', null],
    [[], null],
]);

it('falls back to zero when asked to', function () {
    expect(Money::orZero(null))->toBe('0.00')
        ->and(Money::orZero('2'))->toBe('2.00');
});
