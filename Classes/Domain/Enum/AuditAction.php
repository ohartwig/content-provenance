<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Domain\Enum;

enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case PUBLISH = 'publish';
    case UNPUBLISH = 'unpublish';
    case SIGN = 'sign';
    case REVOKE = 'revoke';
    case AI_GENERATE = 'ai_generate';
    case AI_REVIEW = 'ai_review';
}
