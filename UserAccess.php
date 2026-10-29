<?php

namespace App\Services;

class UserAccess
{
    public function __construct(
        protected $user
    ) {}


    public function isApplicant(): bool
    {
        return $this->user->hasRole(APPLICANT_ROLE);
    }
    public function isCa(): bool
    {
        return $this->user->hasRole(CA_ROLE);
    }

    public function isDeo(): bool
    {
        return $this->user->hasRole(DEO_ROLE);
    }

    public function isSa(): bool
    {
        return $this->user->hasRole(SA_ROLE);
    }
    public function isRgp(): bool
    {
        return $this->user->hasRole(RGP_ROLE);
    }
    public function isHbl(): bool
    {
        return $this->user->hasRole(HBL_ROLE);
    }
    public function isNocIncharge(): bool
    {
        return $this->user->hasRole(NOC_INCHARGE_ROLE);
    }
    public function isLegal(): bool
    {
        return $this->user->hasRole(LEGAL_ROLE);
    }

    public function can(string $permission): bool
    {
        return $this->user->can($permission);
    }

    public function id()
    {
        return $this->user->id;
    }
}
