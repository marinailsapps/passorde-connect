<?php

namespace Passorde\Mock;

use DateTimeImmutable;

/**
 * Answers one request and gives back the state that follows it.
 *
 * Where Evoliz's behaviour has been observed, this copies it. Where it has not, this takes the view least kind to
 * the caller: the filters match loosely, so a caller must compare exactly (see docs/api-to-confirm.md).
 */
final class Mock
{
    public const COMPANY = 1000;

    public const PUBLIC_KEY = 'mock-public-key';

    public const SECRET_VALUE = 'mock-secret-value';

    private const RATE_LIMIT = 100;

    private const TOKEN_SECONDS = 1200;

    private const KEPT_REQUESTS = 2000;

    private const INVOICING = ['invoices', 'invoice', 'progress-invoice', 'credits', 'recoveries'];

    private const CLIENT_TYPES = ['Particulier', 'Professionnel', 'Administration publique'];

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(private array $state) {}

    /**
     * @return array{0: array<string, mixed>, 1: Answer}
     */
    public function handle(string $method, string $uri, string $authorization, string $rawBody, string $base): array
    {
        $method = strtoupper($method);
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $body = json_decode($rawBody, true);
        $body = is_array($body) ? $body : [];

        if (str_starts_with($path, '/__mock/')) {
            $answer = $this->control($method, $path, $body);

            return [$this->state, $answer];
        }

        $answer = $this->api($method, $path, $query, $body, $authorization, $base);

        // The method, the path and the status only: a login body is never kept, even a mistaken real key.
        $this->state['requests'][] = ['method' => $method, 'path' => $path, 'query' => $query, 'status' => $answer->status];
        $this->state['requests'] = array_slice($this->state['requests'], -self::KEPT_REQUESTS);

        return [$this->state, $answer];
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function control(string $method, string $path, array $body): Answer
    {
        if ($method === 'POST' && $path === '/__mock/reset') {
            $this->state = Seed::build(Seed::today());

            return new Answer(200, ['reset' => true, 'today' => $this->state['today']]);
        }

        if ($method === 'POST' && $path === '/__mock/switches') {
            foreach ($body as $name => $value) {
                if (! array_key_exists($name, $this->state['switches'])) {
                    return new Answer(400, ['error' => 'Unknown switch', 'known' => array_keys($this->state['switches'])]);
                }

                $this->state['switches'][$name] = $value;
            }

            return new Answer(200, $this->state['switches']);
        }

        if ($method === 'GET' && $path === '/__mock/state') {
            return new Answer(200, [
                'today' => $this->state['today'],
                'switches' => $this->state['switches'],
                'counts' => array_map(fn (string $kind): int => count($this->state[$kind]), [
                    'clients' => 'clients', 'contacts' => 'contacts', 'articles' => 'articles', 'deliveries' => 'deliveries',
                ]),
                'requests' => $this->state['requests'],
                'forbidden_hits' => $this->state['forbidden_hits'],
            ]);
        }

        return new Answer(404, ['error' => 'Not Found', 'known' => ['POST /__mock/reset', 'POST /__mock/switches', 'GET /__mock/state']]);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function api(string $method, string $path, array $query, array $body, string $authorization, string $base): Answer
    {
        if ($path === '/api/login') {
            return $method === 'POST' ? $this->login($body) : self::notAllowed($method, 'api/login', 'POST');
        }

        if (preg_match('#^/api/v1/companies/([^/]+)(/.*)$#', $path, $parts) !== 1) {
            return self::error(404, 'Not Found', 'Not Found');
        }

        if (! $this->tokenIsValid($authorization)) {
            return new Answer(401, ['error' => 'Unauthenticated']);
        }

        if ($parts[1] !== (string) self::COMPANY) {
            return self::error(403, 'Forbidden', 'This action is unauthorized.');
        }

        $route = $parts[2];
        $refusal = $this->refusal($method, $route);

        // A 405 from Evoliz carries no rate-limit header: it never reaches the throttle.
        if ($refusal !== null && $refusal->status === 405) {
            return $refusal;
        }

        return $this->throttled(function () use ($refusal, $method, $route, $query, $body, $base): Answer {
            if ($refusal !== null) {
                return $refusal;
            }

            if ($method !== 'GET' && $this->state['switches']['read_only'] === true) {
                return self::error(403, 'Forbidden', 'This action is unauthorized.');
            }

            return $this->route($method, $route, $query, $body, $base);
        });
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function login(array $body): Answer
    {
        return $this->throttled(function () use ($body): Answer {
            if (($body['public_key'] ?? null) !== self::PUBLIC_KEY || ($body['secret_key'] ?? null) !== self::SECRET_VALUE) {
                return self::error(401, 'Unauthorized', 'Invalid public or secret key');
            }

            $token = 'mock-token-'.$this->next('token');
            $expires = time() + self::TOKEN_SECONDS;
            $this->state['tokens'][$token] = $expires;

            return new Answer(200, ['access_token' => $token, 'expires_at' => gmdate('Y-m-d\TH:i:s.000000\Z', $expires), 'scopes' => ['*']]);
        });
    }

    private function tokenIsValid(string $authorization): bool
    {
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';

        return ($this->state['tokens'][$token] ?? 0) > time();
    }

    /**
     * What Passorde must never call: recorded, so that a test can show it never did.
     */
    private function refusal(string $method, string $route): ?Answer
    {
        $segments = array_map(fn (string $s): string => strtolower(rawurldecode($s)), explode('/', trim($route, '/')));
        $answer = null;

        if (array_intersect($segments, self::INVOICING) !== []) {
            $answer = self::error(403, 'Forbidden', 'Invoicing does not exist on this mock.');
        } elseif ($segments[0] === 'deliveries' && count($segments) === 2 && $method === 'DELETE') {
            $answer = self::error(403, 'Forbidden', 'Only draft deliveries can be deleted');
        } elseif ($segments[0] === 'deliveries' && count($segments) === 2 && $method !== 'GET') {
            $answer = self::notAllowed($method, self::scoped($route), 'GET, HEAD, DELETE');
        } elseif ($segments[0] === 'deliveries' && count($segments) > 2) {
            $answer = self::notAllowed($method, self::scoped($route), 'none on this mock');
        }

        if ($answer !== null) {
            $this->state['forbidden_hits'][] = ['method' => $method, 'path' => $route, 'status' => $answer->status];
        }

        return $answer;
    }

    /**
     * @param  callable(): Answer  $work
     */
    private function throttled(callable $work): Answer
    {
        $minute = (int) floor(time() / 60);

        if ($this->state['rate']['minute'] !== $minute) {
            $this->state['rate'] = ['minute' => $minute, 'used' => 0];
        }

        $used = (int) $this->state['rate']['used'] + 1;
        $this->state['rate']['used'] = $used;
        $forced = $this->state['switches']['rate_limit_remaining'];
        $remaining = is_int($forced) ? $forced : max(0, self::RATE_LIMIT - $used);
        $headers = ['X-RateLimit-Limit' => self::RATE_LIMIT, 'X-RateLimit-Remaining' => $remaining];

        if ($forced === 0 || $used > self::RATE_LIMIT) {
            return self::error(429, 'Too Many Requests', 'Only '.self::RATE_LIMIT.' requests per minute are allowed.')
                ->with($headers + ['Retry-After' => 60 - time() % 60, 'X-RateLimit-Reset' => ($minute + 1) * 60]);
        }

        return $work()->with($headers);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function route(string $method, string $route, array $query, array $body, string $base): Answer
    {
        $id = null;

        if (preg_match('#^(/[a-z-]+)/(\d+)$#', $route, $parts) === 1) {
            $route = $parts[1];
            $id = (int) $parts[2];
        }

        $listings = ['/vat-rates' => 'vat_rates', '/sale-classifications' => 'classifications', '/payterms' => 'payterms', '/paytypes' => 'paytypes', '/users' => 'users'];

        if (isset($listings[$route]) && $id === null) {
            return $method === 'GET'
                ? $this->page(array_values($this->state[$listings[$route]]), $query, $route, $base)
                : self::notAllowed($method, self::scoped($route), 'GET, HEAD');
        }

        return match ($route) {
            '/clients' => $id === null ? $this->clients($method, $query, $body, $base) : $this->client($method, $id, $body),
            '/contacts-clients' => $id === null ? $this->contacts($method, $query, $body, $base) : $this->contact($method, $id, $body),
            '/articles' => $id === null ? $this->articles($method, $query, $body, $base) : $this->article($method, $id, $body),
            '/deliveries' => $id === null ? $this->deliveries($method, $query, $body, $base) : $this->delivery($id),
            default => self::error(404, 'Not Found', 'Not Found'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function clients(string $method, array $query, array $body, string $base): Answer
    {
        if ($method === 'POST') {
            return $this->createClient($body);
        }

        if ($method !== 'GET') {
            return self::notAllowed($method, self::scoped('/clients'), 'GET, HEAD, POST');
        }

        $rows = array_filter($this->state['clients'], fn (array $row): bool => self::contains($row['code'], $query['code'] ?? null));

        return $this->page(array_values($rows), $query, '/clients', $base);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function createClient(array $body): Answer
    {
        $address = is_array($body['address'] ?? null) ? $body['address'] : [];
        // The API documentation requires postcode and town too, but clients without them were accepted: see api-to-confirm.md.
        $errors = self::required($body, ['name', 'type']) + self::required($address, ['iso2'], 'address.');

        // The API documentation does not require it, but Evoliz does.
        if (trim((string) ($body['vat_number'] ?? '')) === '') {
            $errors['vat_number'] = ['The vat number field is required.'];
        }

        if (isset($body['type']) && ! in_array($body['type'], self::CLIENT_TYPES, true)) {
            $errors['type'] = ['The selected type is invalid.'];
        }

        $code = trim((string) ($body['code'] ?? ''));
        $code = $code === '' ? 'CL'.$this->state['next']['client'] : $code;

        foreach ($this->state['clients'] as $client) {
            if ($client['code'] === $code) {
                $errors['code'] = ['The code has already been taken.'];
            }
        }

        if ($errors !== []) {
            return self::invalid($errors);
        }

        $id = $this->next('client');
        $this->state['clients'][$id] = Rows::client(
            $id,
            $code,
            self::encoded((string) $body['name']),
            (string) $body['type'],
            $address,
            (string) $body['vat_number'],
            isset($body['phone']) ? (string) $body['phone'] : null,
            isset($body['mobile']) ? (string) $body['mobile'] : null,
        );

        return new Answer(201, $this->state['clients'][$id]);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function client(string $method, int $id, array $body): Answer
    {
        if (! isset($this->state['clients'][$id])) {
            return self::error(404, 'Not Found', 'Client not found');
        }

        if ($method === 'GET') {
            return new Answer(200, $this->state['clients'][$id]);
        }

        if ($method !== 'PATCH') {
            return self::notAllowed($method, self::scoped('/clients/'.$id), 'GET, HEAD, PATCH');
        }

        $row = $this->state['clients'][$id];

        foreach (['phone', 'mobile', 'vat_number'] as $field) {
            if (array_key_exists($field, $body)) {
                $row[$field] = $body[$field];
            }
        }

        if (array_key_exists('name', $body)) {
            $row['name'] = self::encoded((string) $body['name']);
        }

        if (is_array($body['address'] ?? null)) {
            $flat = $row['address'];
            $flat['iso2'] = $flat['country']['iso2'];
            $row['address'] = Rows::address(array_merge($flat, $body['address']));
        }

        $this->state['clients'][$id] = $row;

        // Seen on a live account: the change is stored and the answer is a 500.
        return $this->state['switches']['client_patch_500'] === true
            ? new Answer(500, ['message' => 'Server Error'])
            : new Answer(200, $row);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function contacts(string $method, array $query, array $body, string $base): Answer
    {
        if ($method === 'POST') {
            return $this->createContact($body);
        }

        if ($method !== 'GET') {
            return self::notAllowed($method, self::scoped('/contacts-clients'), 'GET, HEAD, POST');
        }

        $rows = array_filter($this->state['contacts'], fn (array $row): bool => ! isset($query['clientid']) || (int) $query['clientid'] === $row['client']['clientid']);

        return $this->page(array_values($rows), $query, '/contacts-clients', $base);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function createContact(array $body): Answer
    {
        $errors = self::required($body, ['clientid', 'lastname']);
        $client = $this->state['clients'][(int) ($body['clientid'] ?? 0)] ?? null;
        $email = $body['email'] ?? null;

        if (isset($body['clientid']) && $client === null) {
            $errors['clientid'] = ['The selected clientid is invalid.'];
        }

        if ($client !== null && $client['enabled'] !== true) {
            $errors['clientid'] = ['Contacts of a disabled client are read-only.'];
        }

        $errors += self::emailErrors($email);

        if (($body['favorite'] ?? false) === true && $email === null) {
            $errors['favorite'] = ['A favorite contact needs an email.'];
        }

        if ($errors !== [] || $client === null) {
            return self::invalid($errors);
        }

        $id = $this->next('contact');
        $favourite = ($body['favorite'] ?? false) === true;
        $this->state['contacts'][$id] = Rows::contact($id, $client, (string) $body['lastname'], isset($body['firstname']) ? (string) $body['firstname'] : null, is_string($email) ? $email : null, $favourite);

        if ($favourite) {
            $this->dropOtherFavourites($id);
        }

        return new Answer(201, $this->state['contacts'][$id]);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function contact(string $method, int $id, array $body): Answer
    {
        if (! isset($this->state['contacts'][$id])) {
            return self::error(404, 'Not Found', 'Contact not found');
        }

        if ($method === 'GET') {
            return new Answer(200, $this->state['contacts'][$id]);
        }

        if ($method !== 'PATCH') {
            return self::notAllowed($method, self::scoped('/contacts-clients/'.$id), 'GET, HEAD, PATCH');
        }

        $row = $this->state['contacts'][$id];

        if (array_key_exists('email', $body)) {
            $errors = self::emailErrors($body['email']);

            if ($errors !== []) {
                return self::invalid($errors);
            }

            // Seen on a live account: the email of a favourite cannot be removed, and the answer is a 500.
            if ($body['email'] === null && ($row['favorite'] === true || ($body['favorite'] ?? false) === true)) {
                return new Answer(500, ['message' => 'Server Error']);
            }
        }

        foreach (['lastname', 'firstname', 'email', 'favorite'] as $field) {
            if (array_key_exists($field, $body)) {
                $row[$field] = $body[$field];
            }
        }

        $this->state['contacts'][$id] = $row;

        if (($body['favorite'] ?? false) === true) {
            $this->dropOtherFavourites($id);
        }

        return new Answer(200, $this->state['contacts'][$id]);
    }

    private function dropOtherFavourites(int $id): void
    {
        foreach ($this->state['contacts'] as $otherId => $other) {
            if ($otherId !== $id && $other['client']['clientid'] === $this->state['contacts'][$id]['client']['clientid']) {
                $this->state['contacts'][$otherId]['favorite'] = false;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function articles(string $method, array $query, array $body, string $base): Answer
    {
        if ($method === 'POST') {
            return $this->createArticle($body);
        }

        if ($method !== 'GET') {
            return self::notAllowed($method, self::scoped('/articles'), 'GET, HEAD, POST');
        }

        $rows = array_filter($this->state['articles'], fn (array $row): bool => self::contains(html_entity_decode((string) $row['reference'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $query['reference'] ?? null));

        return $this->page(array_values($rows), $query, '/articles', $base);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function createArticle(array $body): Answer
    {
        $errors = self::required($body, ['reference', 'nature', 'designation']);

        foreach (['unit_price', 'vat_rate'] as $field) {
            if (! is_numeric($body[$field] ?? null)) {
                $errors[$field] = ['The '.str_replace('_', ' ', $field).' field is required.'];
            }
        }

        $reference = self::encoded((string) ($body['reference'] ?? ''));

        foreach ($this->state['articles'] as $article) {
            if (strcasecmp((string) $article['reference'], $reference) === 0) {
                $errors['reference'] = ['The reference has already been taken.'];
            }
        }

        $classification = null;

        foreach ($this->state['classifications'] as $candidate) {
            if ($candidate['classificationid'] === ($body['sale_classificationid'] ?? null)) {
                $classification = $candidate;
            }
        }

        if (isset($body['sale_classificationid']) && $classification === null) {
            $errors['sale_classificationid'] = ['The selected sale classificationid is invalid.'];
        }

        if ($errors !== []) {
            return self::invalid($errors);
        }

        $managed = ($body['stock_management'] ?? false) === true;
        $id = $this->next('article');
        $this->state['articles'][$id] = Rows::article(
            $id,
            $reference,
            (string) $body['designation'],
            (float) $body['unit_price'],
            (float) $body['vat_rate'],
            $classification,
            $managed,
            $managed ? (float) ($body['stocked_quantity'] ?? 0) : null,
            (string) $body['nature'],
        );

        return new Answer(201, $this->state['articles'][$id]);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function article(string $method, int $id, array $body): Answer
    {
        if (! isset($this->state['articles'][$id])) {
            return self::error(404, 'Not Found', 'Article not found');
        }

        if ($method === 'GET') {
            return new Answer(200, $this->state['articles'][$id]);
        }

        if ($method !== 'PATCH') {
            return self::notAllowed($method, self::scoped('/articles/'.$id), 'GET, HEAD, PATCH');
        }

        if (! is_numeric($body['stocked_quantity'] ?? null)) {
            return self::invalid(['stocked_quantity' => ['The stocked quantity field is required.']]);
        }

        if ($this->state['articles'][$id]['stock_management'] !== true) {
            return self::invalid(['stocked_quantity' => ['This article does not manage its stock.']]);
        }

        // An overwrite, not a movement.
        $this->state['articles'][$id]['stocked_quantity'] = (float) $body['stocked_quantity'];

        return new Answer(200, $this->state['articles'][$id]);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $body
     */
    private function deliveries(string $method, array $query, array $body, string $base): Answer
    {
        if ($method === 'POST') {
            return $this->createDelivery($body);
        }

        if ($method !== 'GET') {
            return self::notAllowed($method, self::scoped('/deliveries'), 'GET, HEAD, POST');
        }

        if (array_key_exists('search', $query)) {
            return self::error(400, 'Unexpected filter', 'Not found filter:  `SearchFilter`.');
        }

        [$from, $to, $errors] = $this->period($query);

        if ($errors !== []) {
            return self::invalid($errors);
        }

        $rows = array_filter($this->state['deliveries'], fn (array $row): bool => (! isset($query['clientid']) || (int) $query['clientid'] === $row['client']['clientid'])
            && ($from === null || $row['documentdate'] >= $from)
            && ($to === null || $row['documentdate'] <= $to));

        return $this->page(array_values($rows), $query, '/deliveries', $base);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array{0: string|null, 1: string|null, 2: array<string, list<string>>}
     */
    private function period(array $query): array
    {
        $period = $query['period'] ?? null;
        $year = substr((string) $this->state['today'], 0, 4);

        if ($period === null) {
            return [null, null, []];
        }

        if ($period === 'currentyear') {
            return [$year.'-01-01', $year.'-12-31', []];
        }

        if ($period !== 'custom') {
            return [null, null, ['period' => ['The selected period is invalid.']]];
        }

        $errors = [];

        foreach (['date_min', 'date_max'] as $field) {
            if (! self::isDate($query[$field] ?? null)) {
                $errors[$field] = ['The '.$field.' does not match the format Y-m-d.'];
            }
        }

        return [(string) ($query['date_min'] ?? ''), (string) ($query['date_max'] ?? ''), $errors];
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function createDelivery(array $body): Answer
    {
        $errors = [];
        $client = $this->state['clients'][(int) ($body['clientid'] ?? 0)] ?? null;
        $date = $body['documentdate'] ?? null;
        $term = is_array($body['term'] ?? null) ? $body['term'] : [];
        $external = isset($body['external_document_number']) ? trim((string) $body['external_document_number']) : null;

        if ($client === null) {
            $errors['clientid'] = ['The selected clientid is invalid.'];
        }

        if (! self::isDate($date)) {
            $errors['documentdate'] = ['The documentdate does not match the format Y-m-d.'];
        } elseif ($date > (new DateTimeImmutable((string) $this->state['today']))->modify('+30 days')->format('Y-m-d')) {
            $errors['documentdate'] = ['The document date cannot be later than the current date + 30 days'];
        }

        $payterm = $this->byId('payterms', 'paytermid', $term['paytermid'] ?? null);
        $paytype = $this->byId('paytypes', 'paytypeid', $term['paytypeid'] ?? null);

        if ($payterm === null) {
            $errors['term.paytermid'] = ['The term.paytermid field is required.'];
        }

        if (isset($term['paytypeid']) && $paytype === null) {
            $errors['term.paytypeid'] = ['The selected term.paytypeid is invalid.'];
        }

        if (! is_array($body['items'] ?? null) || $body['items'] === []) {
            $errors['items'] = ['The items field is required.'];
        }

        if ($client !== null && $external !== null && $external !== '') {
            foreach ($this->state['deliveries'] as $delivery) {
                if ($delivery['external_document_number'] === $external && $delivery['client']['clientid'] === $client['clientid']) {
                    $errors['external_document_number'] = ['The external document number must be unique.'];
                }
            }
        }

        $items = [];

        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $index => $item) {
            $article = $this->state['articles'][(int) ($item['articleid'] ?? 0)] ?? null;

            if ($article === null) {
                $errors['items.'.$index.'.articleid'] = ['The selected articleid is invalid.'];

                continue;
            }

            if (! is_numeric($item['quantity'] ?? null)) {
                $errors['items.'.$index.'.quantity'] = ['The quantity field is required.'];

                continue;
            }

            $items[] = Rows::item($this->next('item'), $article, (float) $item['quantity'], is_numeric($item['unit_price'] ?? null) ? (float) $item['unit_price'] : null, isset($item['designation']) ? (string) $item['designation'] : null);
        }

        if ($errors !== [] || $client === null || $payterm === null) {
            return self::invalid($errors);
        }

        foreach ($items as $item) {
            if ($this->state['articles'][$item['articleid']]['stock_management'] === true) {
                $this->state['articles'][$item['articleid']]['stocked_quantity'] -= $item['quantity'];
            }
        }

        // Registered at once, with a definitive number: there is no draft, and no way back through this API.
        $id = $this->next('delivery');
        $this->state['deliveries'][$id] = Rows::delivery(
            $id,
            sprintf('BL-MOCK-%05d', $this->next('number')),
            $client,
            (string) $date,
            $items,
            $payterm,
            $paytype,
            ($term['recovery_indemnity'] ?? false) === true,
            $external,
            isset($body['comment']) ? (string) $body['comment'] : null,
        );

        return $this->state['switches']['delivery_post_500'] === true
            ? new Answer(500, ['message' => 'Server Error'])
            : new Answer(201, $this->state['deliveries'][$id]);
    }

    private function delivery(int $id): Answer
    {
        return isset($this->state['deliveries'][$id])
            ? new Answer(200, $this->state['deliveries'][$id])
            : self::error(403, 'Forbidden', 'This action is unauthorized.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function byId(string $kind, string $key, mixed $id): ?array
    {
        foreach ($this->state[$kind] as $row) {
            if ($row[$key] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<array-key, mixed>  $query
     */
    private function page(array $rows, array $query, string $route, string $base): Answer
    {
        $perPage = filter_var($query['per_page'] ?? 15, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        if ($perPage === false) {
            return self::invalid(['per_page' => ['The per page must be between 1 and 100.']]);
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $offset = ($page - 1) * $perPage;
        $path = $base.'/api/v1/companies/'.self::COMPANY.$route;
        $link = function (int $target) use ($path, $query, $perPage): string {
            return $path.'?'.http_build_query(array_merge($query, ['per_page' => $perPage, 'page' => $target]));
        };

        return new Answer(200, [
            'data' => $slice,
            'links' => [
                'first' => $link(1),
                'last' => $link($last),
                'prev' => $page > 1 ? $link($page - 1) : null,
                'next' => $page < $last ? $link($page + 1) : null,
            ],
            'meta' => [
                'current_page' => $page,
                'from' => $slice === [] ? null : $offset + 1,
                'last_page' => $last,
                'path' => $path,
                'per_page' => $perPage,
                'to' => $slice === [] ? null : $offset + count($slice),
                'total' => $total,
            ],
        ]);
    }

    private function next(string $sequence): int
    {
        return $this->state['next'][$sequence]++;
    }

    private static function contains(mixed $value, mixed $wanted): bool
    {
        return $wanted === null || $wanted === '' || stripos((string) $value, (string) $wanted) !== false;
    }

    private static function encoded(string $text): string
    {
        return htmlspecialchars(trim($text), ENT_NOQUOTES, 'UTF-8', double_encode: false);
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function emailErrors(mixed $email): array
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false
            ? ['email' => ['The email address must be a valid email address.']]
            : [];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    private static function required(array $values, array $fields, string $prefix = ''): array
    {
        $errors = [];

        foreach ($fields as $field) {
            if (trim((string) ($values[$field] ?? '')) === '') {
                $errors[$prefix.$field] = ['The '.$prefix.$field.' field is required.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function invalid(array $errors): Answer
    {
        return new Answer(400, ['error' => 'Bad Request Error', 'message' => $errors]);
    }

    private static function error(int $status, string $error, string $message): Answer
    {
        return new Answer($status, ['error' => $error, 'message' => $message]);
    }

    private static function notAllowed(string $method, string $route, string $supported): Answer
    {
        return self::error(405, 'Method Not Allowed', 'The '.$method.' method is not supported for route '.$route.'. Supported methods: '.$supported.'.');
    }

    private static function scoped(string $route): string
    {
        return 'api/v1/companies/'.self::COMPANY.$route;
    }
}
