<?php

namespace Passorde\Evoliz;

/**
 * How the Evoliz connector names itself to the core: its id is the one a licence lists in connectors.
 */
final class EvolizConnector
{
    public const ID = 'evoliz';

    /** The product name, the only form in which the connector names Evoliz besides the notice. */
    public const NAME = 'Passorde Connect — Evoliz';

    public const NOTICE = 'Passorde Connect — Evoliz : compatible avec Evoliz, non affilié à Evoliz SAS / Visma.';
}
