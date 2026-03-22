<?php

declare(strict_types=1);

use App\Domain\Transfer\TransferStatus;

test(
    'pending can transition to processing',
    fn () => expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Processing))->toBeTrue()
);
test(
    'pending can transition to failed',
    fn () => expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Failed))->toBeTrue()
);
test(
    'pending cannot transition to completed',
    fn () => expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Completed))->toBeFalse()
);
test(
    'completed is final',
    fn () => expect(TransferStatus::Completed->isFinal())->toBeTrue()
);
test(
    'pending is not final',
    fn () => expect(TransferStatus::Pending->isFinal())->toBeFalse()
);
test(
    'failed is final',
    fn () => expect(TransferStatus::Failed->isFinal())->toBeTrue()
);
test(
    'processing is not final',
    fn () => expect(TransferStatus::Processing->isFinal())->toBeFalse()
);
test(
    'processing can transition to completed',
    fn () => expect(TransferStatus::Processing->canTransitionTo(TransferStatus::Completed))->toBeTrue()
);
test(
    'processing can transition to failed',
    fn () => expect(TransferStatus::Processing->canTransitionTo(TransferStatus::Failed))->toBeTrue()
);
test(
    'processing cannot transition to pending',
    fn () => expect(TransferStatus::Processing->canTransitionTo(TransferStatus::Pending))->toBeFalse()
);
test(
    'completed cannot transition to failed',
    fn () => expect(TransferStatus::Completed->canTransitionTo(TransferStatus::Failed))->toBeFalse()
);
