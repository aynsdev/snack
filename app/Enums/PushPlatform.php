<?php

namespace App\Enums;

/**
 * Where a push device runs. Both are reached through FCM; the platform only
 * decides which half of the message (`android` or `apns`) carries the sound and
 * priority.
 */
enum PushPlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
}
