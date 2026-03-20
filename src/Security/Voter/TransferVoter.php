<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Domain\Transfer\Transfer;
use App\Domain\User\UserId;
use App\Security\SecurityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Transfer> */
final class TransferVoter extends Voter
{
    public const VIEW   = 'transfer.view';
    public const CANCEL = 'transfer.cancel';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CANCEL], true)
            && $subject instanceof Transfer;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $securityUser = $token->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return false;
        }
        return $subject->initiatedByUserId->equals(new UserId((string) $securityUser->user->id));
    }
}
