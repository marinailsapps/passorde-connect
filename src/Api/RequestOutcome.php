<?php

namespace Passorde\Api;

enum RequestOutcome: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
