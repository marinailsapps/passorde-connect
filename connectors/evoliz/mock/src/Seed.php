<?php

namespace Passorde\Mock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The starting data, all of it invented. It is computed from the date alone, with no random numbers:
 * the same date gives the same data, and deliveries always fall around that date.
 */
final class Seed
{
    public const CLIENTS = 12;

    public const ARTICLES = 36;

    public const DELIVERIES = 24;

    private const CLIENT_NAMES = [
        'Comptoir Fictif Alpha', 'Atelier Fictif B&ecirc;ta', 'Soci&eacute;t&eacute; Fictive Gamma', 'Comptoir Fictif Delta',
        'Atelier Fictif Epsilon', 'Soci&eacute;t&eacute; Fictive Z&ecirc;ta', 'Comptoir Fictif &Ecirc;ta', 'Atelier Fictif Th&ecirc;ta',
        'Soci&eacute;t&eacute; Fictive Iota', 'Comptoir Fictif Kappa', 'Atelier Fictif Lambda', 'Soci&eacute;t&eacute; Fictive Mu',
    ];

    private const CONTACT_NAMES = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa', 'Lambda'];

    private const TOWNS = [['00100', 'Exempleville'], ['00200', 'Fictiveville'], ['00300', 'Modeleville']];

    private const GOODS = ['Bo&icirc;te de vis fictive', 'Rouleau de ruban fictif', 'Carton plat fictif', 'Lot d&#039;&eacute;tiquettes fictives', 'Flacon de colle fictive', 'Paire de gants fictifs'];

    private const VAT_RATES = [20.0, 10.0, 5.5];

    public static function today(): string
    {
        return getenv('PASSORDE_MOCK_TODAY') ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(string $today): array
    {
        $vatRates = [
            ['vatid' => 111, 'rate' => 20, 'account' => ['accountid' => 211, 'code' => '44571020', 'label' => 'TVA fictive 20 %']],
            ['vatid' => 112, 'rate' => 10, 'account' => ['accountid' => 212, 'code' => '44571010', 'label' => 'TVA fictive 10 %']],
            ['vatid' => 113, 'rate' => 5.5, 'account' => ['accountid' => 213, 'code' => '44571005', 'label' => 'TVA fictive 5,5 %']],
        ];
        $classifications = [
            ['classificationid' => 301, 'code' => '707', 'label' => 'Ventes fictives de marchandises', 'enabled' => true],
            ['classificationid' => 302, 'code' => '706', 'label' => 'Prestations fictives', 'enabled' => true],
        ];
        $payterms = [['paytermid' => 31, 'label' => '&Agrave; r&eacute;ception'], ['paytermid' => 37, 'label' => '30 jours']];
        $paytypes = [['paytypeid' => 41, 'label' => 'Virement'], ['paytypeid' => 42, 'label' => 'Ch&egrave;que']];
        $users = [
            ['userid' => 91, 'firstname' => 'Utilisateur', 'lastname' => 'Un', 'email' => 'utilisateur.un@mock.invalid', 'enabled' => true],
            ['userid' => 92, 'firstname' => 'Utilisateur', 'lastname' => 'Deux', 'email' => 'utilisateur.deux@mock.invalid', 'enabled' => true],
        ];

        $clients = self::clients();
        $articles = self::articles($classifications);

        return [
            'today' => $today,
            'next' => ['client' => 6001, 'contact' => 7001, 'article' => 4001, 'delivery' => 9001, 'item' => 90001, 'number' => self::DELIVERIES + 1, 'token' => 1],
            'clients' => $clients,
            'contacts' => self::contacts($clients),
            'articles' => $articles,
            'deliveries' => self::deliveries($today, $clients, $articles, $payterms, $paytypes),
            'vat_rates' => $vatRates,
            'classifications' => $classifications,
            'payterms' => $payterms,
            'paytypes' => $paytypes,
            'users' => $users,
            'switches' => ['read_only' => false, 'client_patch_500' => false, 'delivery_post_500' => false, 'rate_limit_remaining' => null],
            'tokens' => [],
            'rate' => ['minute' => 0, 'used' => 0],
            'requests' => [],
            'forbidden_hits' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function clients(): array
    {
        $clients = [];

        foreach (self::CLIENT_NAMES as $index => $name) {
            [$postcode, $town] = self::TOWNS[$index % count(self::TOWNS)];
            $number = $index + 1;
            $id = 5000 + $number;

            $clients[$id] = Rows::client(
                $id,
                sprintf('FICTIF%02d', $number),
                $name,
                'Professionnel',
                ['addr' => $number.' rue de la Fiction', 'addr2' => $index % 4 === 0 ? 'B&acirc;timent A' : null, 'postcode' => $postcode, 'town' => $town, 'iso2' => 'FR'],
                'NC',
                sprintf('00 00 00 00 %02d', $number),
                null,
                $number !== 11,
            );
        }

        return $clients;
    }

    /**
     * Every client has a favourite contact except the last, which has none; the first has a second one.
     *
     * @param  array<int, array<string, mixed>>  $clients
     * @return array<int, array<string, mixed>>
     */
    private static function contacts(array $clients): array
    {
        $contacts = [];
        $id = 6500;

        $list = array_values($clients);

        foreach (self::CONTACT_NAMES as $index => $name) {
            $id++;
            $contacts[$id] = Rows::contact($id, $list[$index], $name, 'Contact', 'contact.'.strtolower($name).'@mock.invalid', true);
        }

        $id++;
        $contacts[$id] = Rows::contact($id, $clients[5001], 'Secondaire', 'Contact', 'secondaire.alpha@mock.invalid', false);

        return $contacts;
    }

    /**
     * Two articles out of three manage their stock, which can start below zero. The last reference holds an ampersand.
     *
     * @param  list<array<string, mixed>>  $classifications
     * @return array<int, array<string, mixed>>
     */
    private static function articles(array $classifications): array
    {
        $articles = [];

        for ($number = 1; $number <= self::ARTICLES; $number++) {
            $id = 3000 + $number;
            $managed = $number % 3 !== 0;
            $reference = $number === self::ARTICLES ? sprintf('FIC&amp;%03d', $number) : sprintf('FIC-%03d', $number);

            $articles[$id] = Rows::article(
                $id,
                $reference,
                sprintf('%s n&deg; %03d', self::GOODS[$number % count(self::GOODS)], $number),
                round(1.5 + $number * 0.75, 2),
                self::VAT_RATES[$number % count(self::VAT_RATES)],
                $classifications[$number % 2 === 0 ? 0 : 1],
                $managed,
                $managed ? (float) (($number * 7) % 40 - ($number === 4 ? 40 : 0)) : null,
                'product',
                $number !== 35,
            );
        }

        return $articles;
    }

    /**
     * One every eight days, from just ahead of the date to about six months before it. The older ones include invoiced
     * deliveries, and every other one has no external number, as when typed in Evoliz's own screens.
     *
     * @param  array<int, array<string, mixed>>  $clients
     * @param  array<int, array<string, mixed>>  $articles
     * @param  list<array<string, mixed>>  $payterms
     * @param  list<array<string, mixed>>  $paytypes
     * @return array<int, array<string, mixed>>
     */
    private static function deliveries(string $today, array $clients, array $articles, array $payterms, array $paytypes): array
    {
        $clientList = array_values($clients);
        $articleList = array_values($articles);
        $origin = new DateTimeImmutable($today);
        $deliveries = [];

        for ($number = 1; $number <= self::DELIVERIES; $number++) {
            $date = $origin->modify(sprintf('%+d days', 16 - $number * 8))->format('Y-m-d');
            $id = 8000 + $number;
            $items = [];

            for ($line = 0; $line <= $number % 3; $line++) {
                $items[] = Rows::item($id * 10 + $line, $articleList[($number * 5 + $line * 11) % self::ARTICLES], ($number + $line) % 4 + 1);
            }

            $external = $number % 2 === 0 ? sprintf('EXT-%04d', $number) : null;

            $deliveries[$id] = Rows::delivery(
                $id,
                sprintf('BL-MOCK-%05d', $number),
                $clientList[$number % self::CLIENTS],
                $date,
                $items,
                $payterms[$number % 2],
                $number % 3 === 0 ? null : $paytypes[$number % 2],
                true,
                $external,
                $external === null ? null : 'R&eacute;f&eacute;rence '.$external,
                $number > 12 && $number % 3 === 0 ? $origin->modify(sprintf('%+d days', 22 - $number * 8))->format('Y-m-d') : null,
            );
        }

        return $deliveries;
    }
}
