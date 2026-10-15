<?php

namespace App\Enums;

/**
 * What a Bite generation produces.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
}
