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
