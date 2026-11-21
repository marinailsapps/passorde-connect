<?php

namespace Passorde\Sync;

enum SyncKind: string
{
    case Paytypes = 'paytypes';
    case Clients = 'clients';
    case Articles = 'articles';
    case Users = 'users';
    case Deliveries = 'deliveries';
}
