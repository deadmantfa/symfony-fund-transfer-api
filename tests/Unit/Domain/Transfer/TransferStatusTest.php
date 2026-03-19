<?php

declare(strict_types=1);

use App\Domain\Transfer\TransferStatus;

test('pending can transition to processing', fn () =>
    expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Processing))->toBeTrue()
);
test('pending can transition to failed', fn () =>
    expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Failed))->toBeTrue()
);
test('pending cannot transition to completed', fn () =>
    expect(TransferStatus::Pending->canTransitionTo(TransferStatus::Completed))->toBeFalse()
);
test('completed is final', fn () =>
    expect(TransferStatus::Completed->isFinal())->toBeTrue()
);
test('pending is not final', fn () =>
    expect(TransferStatus::Pending->isFinal())->toBeFalse()
);
