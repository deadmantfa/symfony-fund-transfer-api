<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Domain\Account\Account;
use App\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Account> */
final class AccountVoter extends Voter
{
    public const VIEW   = 'account.view';
    public const EDIT   = 'account.edit';
    public const DELETE = 'account.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Account;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $securityUser = $token->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return false;
        }

        return $subject->isOwnedBy($securityUser->user->id);
    }
}
