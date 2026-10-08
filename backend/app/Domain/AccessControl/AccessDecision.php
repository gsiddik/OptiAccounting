<?php

namespace App\Domain\AccessControl;

/** Outcome of an access evaluation; `code` is a stable machine-readable reason. */
final class AccessDecision
{
    public const OK = 'OK';

    public const USER_INACTIVE = 'USER_INACTIVE';

    public const TENANT_NOT_FOUND = 'TENANT_NOT_FOUND';

    public const TENANT_INACTIVE = 'TENANT_INACTIVE';

    public const MEMBERSHIP_INACTIVE = 'MEMBERSHIP_INACTIVE';

    public const SUBSCRIPTION_INACTIVE = 'SUBSCRIPTION_INACTIVE';

    public const SUBSCRIPTION_READ_ONLY = 'SUBSCRIPTION_READ_ONLY';

    public const MODULE_NOT_ENTITLED = 'MODULE_NOT_ENTITLED';

    public const MODULE_READ_ONLY = 'MODULE_READ_ONLY';

    public const FEATURE_NOT_ENTITLED = 'FEATURE_NOT_ENTITLED';

    public const PERMISSION_DENIED = 'PERMISSION_DENIED';

    public const DATA_SCOPE_DENIED = 'DATA_SCOPE_DENIED';

    public const TENANT_MISMATCH = 'TENANT_MISMATCH';

    public const TOKEN_SCOPE = 'TOKEN_SCOPE';

    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly bool $readOnly = false,
    ) {}

    public static function allow(bool $readOnly = false): self
    {
        return new self(true, self::OK, $readOnly);
    }

    public static function deny(string $code): self
    {
        return new self(false, $code);
    }

    public function message(): string
    {
        return match ($this->code) {
            self::OK => 'Allowed.',
            self::USER_INACTIVE => 'This user account is not active.',
            self::TENANT_NOT_FOUND, self::TENANT_MISMATCH => 'Resource not found.',
            self::TENANT_INACTIVE => 'This organization is not active.',
            self::MEMBERSHIP_INACTIVE => 'Your access to this organization is not active.',
            self::SUBSCRIPTION_INACTIVE => 'This organization has no active subscription.',
            self::SUBSCRIPTION_READ_ONLY => 'The subscription is past due: the organization is read-only.',
            self::MODULE_NOT_ENTITLED => 'This module is not available to the organization.',
            self::MODULE_READ_ONLY => 'This module is read-only for the organization.',
            self::FEATURE_NOT_ENTITLED => 'This feature is not available to the organization.',
            self::PERMISSION_DENIED => 'You do not have permission to perform this action.',
            self::DATA_SCOPE_DENIED => 'This record is outside your data scope.',
            self::TOKEN_SCOPE => 'This credential cannot be used here.',
            default => 'Access denied.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this->code) {
            self::TENANT_MISMATCH, self::TENANT_NOT_FOUND => 404,
            default => 403,
        };
    }
}
