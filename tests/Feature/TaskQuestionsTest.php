<?php

declare(strict_types=1);

use Onelegstudios\Refit\Tests\Doubles\AsksHowToGreet;

/**
 * Nothing refit ships asks a follow-up question yet, so these run against
 * {@see AsksHowToGreet} — the task registry is a public API, and pointing the
 * command at a task of our own is exactly what a third party does.
 */
beforeEach(function (): void {
    config()->set('refit.tasks', [AsksHowToGreet::class]);
});

it('asks a chosen task its own questions, and plans with the answer', function (): void {
    $root = useKit('livewire');

    $this->artisan('refit', ['--force' => true])
        ->expectsQuestion('Which component library should this project end up on?', 'flux')
        ->expectsQuestion('The kit ships Heroicons with a few Lucide icons vendored in. What would you like?', 'keep')
        ->expectsQuestion('Which tasks would you like to run?', ['greeting'])
        ->expectsQuestion('How should the greeting read?', 'casual')
        ->expectsOutputToContain('write  '.AsksHowToGreet::WHERE)
        ->expectsQuestion('Apply these changes?', true)
        ->expectsQuestion('Remove refit from this project now?', false)
        ->assertSuccessful();

    expect(file_get_contents($root.'/'.AsksHowToGreet::WHERE))->toBe("Hey\n");
});

it('asks nothing about a task the user did not pick', function (): void {
    useKit('livewire');

    $this->artisan('refit', ['--force' => true])
        ->expectsQuestion('Which component library should this project end up on?', 'flux')
        ->expectsQuestion('The kit ships Heroicons with a few Lucide icons vendored in. What would you like?', 'keep')
        ->expectsQuestion('Which tasks would you like to run?', [])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

it('takes a question with a single option as answered', function (): void {
    $root = useKit('livewire');

    // The prompt queue below holds no answer for "Where should the greeting go?",
    // so the run only gets this far if that question was never put.
    $this->artisan('refit', ['--force' => true])
        ->expectsQuestion('Which component library should this project end up on?', 'flux')
        ->expectsQuestion('The kit ships Heroicons with a few Lucide icons vendored in. What would you like?', 'keep')
        ->expectsQuestion('Which tasks would you like to run?', ['greeting'])
        ->expectsQuestion('How should the greeting read?', 'formal')
        ->expectsQuestion('Apply these changes?', true)
        ->expectsQuestion('Remove refit from this project now?', false)
        ->assertSuccessful();

    expect(file_get_contents($root.'/'.AsksHowToGreet::WHERE))->toBe("Good day\n");
});

it('reads a task\'s answers out of --answers under its own key', function (): void {
    $root = useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'icons' => 'keep',
            'tasks' => ['greeting'],
            'greeting' => ['tone' => 'casual'],
        ]),
    ])->assertSuccessful();

    expect(file_get_contents($root.'/'.AsksHowToGreet::WHERE))->toBe("Hey\n");
});

it('falls back to the default for a question --answers says nothing about', function (): void {
    $root = useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['icons' => 'keep', 'tasks' => ['greeting']]),
    ])->assertSuccessful();

    expect(file_get_contents($root.'/'.AsksHowToGreet::WHERE))->toBe("Good day\n");
});

it('falls back to the default for an answer the question never offered', function (): void {
    $root = useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'icons' => 'keep',
            'tasks' => ['greeting'],
            'greeting' => ['tone' => 'shouty', 'where' => '../outside.blade.php'],
        ]),
    ])->assertSuccessful();

    expect(file_get_contents($root.'/'.AsksHowToGreet::WHERE))->toBe("Good day\n")
        ->and(is_file(dirname($root).'/outside.blade.php'))->toBeFalse();
});
