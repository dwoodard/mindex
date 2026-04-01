<?php

namespace App\Enums;

enum RelationType: string
{
    case Originated = 'ORIGINATED';
    case Suggested = 'SUGGESTED';
    case Rejected = 'REJECTED';
    case EvolvedInto = 'EVOLVED_INTO';
    case ContradictedBy = 'CONTRADICTED_BY';
    case Reinforces = 'REINFORCES';
    case RelatesTo = 'RELATES_TO';
    case Blocks = 'BLOCKS';
    case Enables = 'ENABLES';
    case HasQuestion = 'HAS_QUESTION';
    case Prefers = 'PREFERS';
    case Dislikes = 'DISLIKES';
    case WorksWith = 'WORKS_WITH';
    case BuiltOn = 'BUILT_ON';
    case Mentions = 'MENTIONS';
}
