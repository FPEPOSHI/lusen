<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lusen\Ir\ApiSpec;
use Lusen\Ir\Endpoint;
use Lusen\Ir\Group;
use Lusen\SpecBuilder;
use Lusen\Tests\Fixtures\OnboardingController;
use Lusen\Tests\Fixtures\OrderController;
use Lusen\Tests\Fixtures\UserController;

function orderedSpec(): ApiSpec
{
    return app(SpecBuilder::class)->build();
}

/**
 * @return list<string>
 */
function groupNames(ApiSpec $spec): array
{
    return array_map(static fn (Group $group): string => $group->name, $spec->groups);
}

/**
 * @return list<string>
 */
function summaries(ApiSpec $spec, string $group): array
{
    foreach ($spec->groups as $candidate) {
        if ($candidate->name === $group) {
            return array_map(static fn (Endpoint $e): string => (string) $e->summary, $candidate->endpoints);
        }
    }

    return [];
}

beforeEach(function (): void {
    // Registered out of order on purpose: nothing here may depend on it.
    Route::get('api/users', [UserController::class, 'index'])->name('users.index');
    Route::post('api/onboarding/bank-account', [OnboardingController::class, 'bankAccount'])->name('onboarding.bank-account');
    Route::get('api/onboarding/ping', [OnboardingController::class, 'ping'])->name('onboarding.ping');
    Route::post('api/onboarding/register', [OnboardingController::class, 'register'])->name('onboarding.register');
    Route::post('api/onboarding/certificate', [OnboardingController::class, 'certificate'])->name('onboarding.certificate');
    Route::get('api/orders', [OrderController::class, 'index'])->name('orders.index');
});

it('puts an ordered group ahead of the alphabet', function (): void {
    // Onboarding sorts last of the three by name and first by order.
    expect(groupNames(orderedSpec()))->toBe(['Onboarding', 'Orders', 'Users']);
});

it('keeps unordered groups alphabetical behind the ordered ones', function (): void {
    $names = groupNames(orderedSpec());

    expect(array_slice($names, 1))->toBe(['Orders', 'Users']);
});

it('reads a group order off the controller attribute', function (): void {
    $group = orderedSpec()->groups[0];

    expect($group->name)->toBe('Onboarding')
        ->and($group->order)->toBe(1);
});

it('orders a group\'s operations by the sequence they are performed in', function (): void {
    expect(summaries(orderedSpec(), 'Onboarding'))->toBe([
        'Register the company',
        'Upload the certificate',
        'Add a bank account',
        'Check the service is up',
    ]);
});

it('leaves a group nobody ordered exactly as it was', function (): void {
    Route::get('api/users/{user}', [UserController::class, 'show'])->name('users.show');

    // Path order, which is what collection produced, not attribute order.
    expect(summaries(orderedSpec(), 'Users'))->toBe(['List users', 'Show a user']);
});

it('serializes both orders through the IR', function (): void {
    $spec = ApiSpec::fromArray(orderedSpec()->toArray());

    expect($spec->groups[0]->order)->toBe(1)
        ->and($spec->endpoint('onboarding.register')?->order)->toBe(10)
        ->and($spec->endpoint('onboarding.ping')?->order)->toBeNull()
        ->and(summaries($spec, 'Onboarding')[0])->toBe('Register the company');
});
