<?php

namespace App\Enums;

enum NavigationGroup: string
{
    case Operations = 'Operations';
    case SEO = 'SEO';
    case Settings = 'Settings';
    case Monitoring = 'Monitoring';
    case Backup = 'Backup';
    case Notifications = 'Notifications';
    case API = 'API';
    case Server = 'Server';
    case BackupManager = 'Backup Manager';
}
