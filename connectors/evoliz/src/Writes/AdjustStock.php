<?php

namespace Passorde\Writes;

use Illuminate\Support\Facades\Log;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Drafts\Input;
use Passorde\Drafts\InvalidDraftException;
use Passorde\Drafts\StockExpectation;
use Passorde\Evoliz\EvolizConnector;
use Passorde\Licence\LicenceGuard;
use Passorde\Records\ArticleRecord;
use Passorde\Records\Money;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\StockAdjusted;
use Passorde\Writes\Support\Mirror;
use Passorde\Writes\Support\UncertainWrite;
use Passorde\Writes\Support\WriteCalls;
use Passorde\Writes\Support\WriteLock;

/**
 * Evoliz has no stock movement: its PATCH overwrites the quantity. So this reads the stock, computes, writes and
 * reads again; a delivery that moves the stock between the read and the write is lost, and the last read reports it.
 */
final class AdjustStock
{
    private const SAME = 0.005;

    public function __construct(
        private readonly WriteCalls $calls,
        private readonly Mirror $mirror,
        private readonly WriteLock $lock,
        private readonly LicenceGuard $licence,
    ) {}

    /**
     * One adjustment per call. Unlike the other verbs this one has no key to recognise itself by: after a Failed
     * marked uncertain, call it again with the same delta and new StockExpectation($failed->before, $failed->written).
     * When the stock it reads then equals that written value, the earlier attempt went through: nothing is sent and
     * the result says so. Otherwise the adjustment starts again from the stock it reads.
     *
     * @param  int|float|string  $delta  What to add to the stock; negative to remove.
     * @param  StockExpectation|null  $previous  What the attempt that ended in doubt read and tried to write.
     *
     * @throws InvalidDraftException
     */
    public function run(int $articleId, int|float|string $delta, ?StockExpectation $previous = null): StockAdjusted|Refused|Failed
    {
        Input::id('articleId', $articleId);
        $change = (float) Input::number('delta', $delta);

        if ($change === 0.0) {
            throw InvalidDraftException::field('delta', 'ajustement nul');
        }

        $expected = $previous === null ? null : [
            (float) Input::number('previous.before', $previous->before),
            (float) Input::number('previous.written', $previous->written),
        ];

        // Second line of defence: the client checks again, but a host may bind its own EvolizApi.
        $this->licence->check(EvolizConnector::ID);

        return $this->lock->around('stock:'.$articleId, fn (): StockAdjusted|Refused|Failed => $this->adjust($articleId, $change, $expected));
    }

    /**
     * @param  array{float, float}|null  $expected  The before and written values of the attempt that ended in doubt.
     */
    private function adjust(int $articleId, float $change, ?array $expected): StockAdjusted|Refused|Failed
    {
        $path = '/articles/'.$articleId;
        $row = $this->calls->read('stock.read', $path);
        $initial = ArticleRecord::fromRow($row);

        if ($initial === null) {
            return new Failed(null, 'Evoliz a répondu sans identifiant d\'article.');
        }

        if (! $initial->stockManaged) {
            return new Refused(null, 'Cet article n\'a pas de gestion de stock dans Evoliz.', ['stock_management' => ['Cet article n\'a pas de gestion de stock dans Evoliz.']]);
        }

        $before = self::quantity($row);

        if ($expected !== null && self::same($before, $expected[1])) {
            return $this->alreadyApplied($path, $initial, $expected[0], $expected[1], $before);
        }
        $target = round($before + $change, 2);

        $sent = $this->calls->send('stock.adjust', 'PATCH', $path, ['stocked_quantity' => Input::number('stocked_quantity', $target)], function () use ($path, $before, $target): ?array {
            $current = $this->calls->readOnce('stock.verify', $path);
            $quantity = self::quantity($current);

            if (self::same($quantity, $target)) {
                return $current;
            }

            if (self::same($quantity, $before)) {
                return null;
            }

            // Writing again would overwrite whatever moved it. Whether this write went through can no longer be told.
            throw new UncertainWrite('Le stock a bougé pendant l\'ajustement : '.Money::orZero($quantity).' au lieu de '.Money::orZero($before).' ou '.Money::orZero($target).'.');
        });

        if ($sent->problem instanceof Failed) {
            // What was attempted, for the host to keep and give back on the retry.
            return new Failed($sent->problem->status, $sent->problem->message, $sent->problem->uncertain, Money::orZero($before), Money::orZero($target));
        }

        if ($sent->problem !== null) {
            return $sent->problem;
        }

        return $this->verified($path, $initial, $sent->row, $before, $target, $sent->recovered);
    }

    /**
     * The stock already shows what the attempt in doubt wrote: that attempt went through, and writing again would
     * count it twice.
     */
    private function alreadyApplied(string $path, ArticleRecord $fresh, float $before, float $written, float $current): StockAdjusted
    {
        Log::info('Passorde: ajustement de stock déjà appliqué par la tentative précédente ; rien n\'est renvoyé.', ['path' => $path, 'written' => $written]);

        $this->mirror->article($fresh);

        return new StockAdjusted(
            record: $fresh,
            before: Money::orZero($before),
            written: Money::orZero($written),
            after: Money::orZero($current),
            confirmed: true,
            afterUncertainResponse: true,
        );
    }

    /**
     * @param  array<array-key, mixed>  $written  What the write answered.
     */
    private function verified(string $path, ArticleRecord $initial, array $written, float $before, float $target, bool $recovered): StockAdjusted
    {
        try {
            $reread = $this->calls->read('stock.verify', $path);
        } catch (ApiException $exception) {
            Log::warning('Passorde: stock non relu après un ajustement.', ['path' => $path, 'error' => $exception->getMessage()]);
            $reread = null;
        }

        $after = $reread === null ? null : self::quantity($reread);
        $confirmed = $after !== null && self::same($after, $target);

        if ($after !== null && ! $confirmed) {
            Log::warning('Passorde: le stock diffère après un ajustement ; quelque chose d\'autre l\'a modifié.', ['path' => $path, 'written' => $target, 'after' => $after]);
        }

        $fresh = ArticleRecord::fromRow($reread ?? $written);

        if ($fresh !== null) {
            $this->mirror->article($fresh);
        }

        return new StockAdjusted(
            record: $fresh ?? $initial,
            before: Money::orZero($before),
            written: Money::orZero($target),
            after: $after === null ? null : Money::orZero($after),
            confirmed: $confirmed,
            afterUncertainResponse: $recovered,
        );
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function quantity(array $row): float
    {
        return is_numeric($row['stocked_quantity'] ?? null) ? (float) $row['stocked_quantity'] : 0.0;
    }

    private static function same(float $a, float $b): bool
    {
        return abs($a - $b) < self::SAME;
    }
}
