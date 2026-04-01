<?php

namespace App\Enums;

enum WriteIntent: string
{
    case Create = 'CREATE';
    case Reinforce = 'REINFORCE';
    case Update = 'UPDATE';
    case Evolve = 'EVOLVE';
    case Contradict = 'CONTRADICT';
    case Resolve = 'RESOLVE';
}
