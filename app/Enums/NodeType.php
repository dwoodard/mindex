<?php

namespace App\Enums;

enum NodeType: string
{
    case Person = 'Person';
    case Idea = 'Idea';
    case Project = 'Project';
    case Belief = 'Belief';
    case Question = 'Question';
    case Preference = 'Preference';
    case Dislike = 'Dislike';
    case Event = 'Event';
    case Place = 'Place';
    case Resource = 'Resource';
}
