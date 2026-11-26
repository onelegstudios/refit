<?php

declare(strict_types=1);

use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;

function toneQuestion(?string $default = null): Question
{
    return new Question(
        key: 'tone',
        label: 'How should the greeting read?',
        options: ['formal' => 'Formally', 'casual' => 'Casually'],
        default: $default,
    );
}

it('falls back to its first option when no default is named', function (): void {
    expect(toneQuestion()->default())->toBe('formal');
});

it('keeps the default it was given', function (): void {
    expect(toneQuestion('casual')->default())->toBe('casual');
});

it('resolves an answer it offered', function (): void {
    expect(toneQuestion()->resolve('casual'))->toBe('casual');
});

it('resolves anything it did not offer to the default', function (mixed $given): void {
    expect(toneQuestion('casual')->resolve($given))->toBe('casual');
})->with([
    'an unknown option' => 'shouty',
    'nothing' => null,
    'a number' => 1,
    'a list' => [['casual']],
    'the option label rather than its value' => 'Casually',
]);

it('reads the answers it was built with', function (): void {
    $answers = new Answers(['tone' => 'casual']);

    expect($answers->get('tone'))->toBe('casual')
        ->and($answers->is('tone', 'casual'))->toBeTrue()
        ->and($answers->is('tone', 'formal'))->toBeFalse()
        ->and($answers->all())->toBe(['tone' => 'casual']);
});

it('reads an empty string for a question nobody answered', function (): void {
    $answers = new Answers;

    expect($answers->get('tone'))->toBe('')
        ->and($answers->is('tone', 'formal'))->toBeFalse()
        ->and($answers->all())->toBe([]);
});

/**
 * @param  string|list<string>|null  $default
 */
function accountsQuestion(string|array|null $default = null): Question
{
    return new Question(
        key: 'accounts',
        label: 'Which developer logins should the login page offer?',
        options: ['test' => 'The test user', 'admin' => 'An admin', 'new' => 'A brand-new user'],
        default: $default,
        multiple: true,
    );
}

it('says whether it takes one answer or several', function (): void {
    expect(toneQuestion()->multiple)->toBeFalse()
        ->and(accountsQuestion()->multiple)->toBeTrue();
});

it('falls back to its first option when a question of several names no default', function (): void {
    expect(accountsQuestion()->defaults())->toBe(['test']);
});

it('keeps the defaults it was given, in the order it was given them', function (): void {
    expect(accountsQuestion(['new', 'test'])->defaults())->toBe(['new', 'test']);
});

it('drops a default it never offered rather than defaulting to it', function (): void {
    expect(accountsQuestion(['shouty', 'admin'])->defaults())->toBe(['admin'])
        ->and(accountsQuestion(['shouty'])->defaults())->toBe(['test'])
        ->and(accountsQuestion('shouty')->default())->toBe('test');
});

it('reads a single default as the list of one it is', function (): void {
    expect(accountsQuestion('admin')->defaults())->toBe(['admin'])
        ->and(accountsQuestion(['admin', 'new'])->default())->toBe('admin');
});

it('resolves the answers it offered, and only those', function (): void {
    expect(accountsQuestion()->resolveMany(['admin', 'shouty', 'new']))->toBe(['admin', 'new']);
});

it('resolves each answer once, however often it was given', function (): void {
    expect(accountsQuestion()->resolveMany(['admin', 'admin']))->toBe(['admin']);
});

it('resolves a list with nothing live left in it to the defaults', function (mixed $given): void {
    expect(accountsQuestion(['new'])->resolveMany($given))->toBe(['new']);
})->with([
    'nothing' => null,
    'an empty list' => [[]],
    'only options it never offered' => [['shouty']],
    'a string where a list belongs' => 'admin,new',
    'the option label rather than its value' => [['An admin']],
]);

it('takes a single answer as a list of one', function (): void {
    expect(accountsQuestion()->resolveMany('admin'))->toBe(['admin']);
});

it('reads several answers to one question', function (): void {
    $answers = new Answers(['accounts' => ['admin', 'new']]);

    expect($answers->many('accounts'))->toBe(['admin', 'new'])
        ->and($answers->chose('accounts', 'new'))->toBeTrue()
        ->and($answers->chose('accounts', 'test'))->toBeFalse()
        ->and($answers->get('accounts'))->toBe('admin')
        ->and($answers->all())->toBe(['accounts' => ['admin', 'new']]);
});

it('reads either kind of answer from either side', function (): void {
    $answers = new Answers(['tone' => 'casual', 'accounts' => ['test']]);

    expect($answers->many('tone'))->toBe(['casual'])
        ->and($answers->chose('tone', 'casual'))->toBeTrue()
        ->and($answers->get('accounts'))->toBe('test')
        ->and($answers->is('accounts', 'test'))->toBeTrue();
});

it('reads an empty list for a question of several nobody answered', function (): void {
    $answers = new Answers;

    expect($answers->many('accounts'))->toBe([])
        ->and($answers->chose('accounts', 'test'))->toBeFalse();
});
