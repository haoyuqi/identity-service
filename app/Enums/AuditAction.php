<?php

namespace App\Enums;

enum AuditAction: string
{
    case BOOTSTRAP = 'admin.bootstrap';
    case PASSWORD_RESET = 'admin.password_reset';
    case ACCOUNT_CREATED = 'admin.account_created';
    case ACCOUNT_DISABLED = 'admin.account_disabled';
}
