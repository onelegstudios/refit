<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RewriteToastCalls;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * A throwaway project holding one file, run through the sweep.
 *
 * The action reads the tree itself rather than transforming a string, because
 * where a call lives is half of what it decides — so the test has to give it a
 * tree rather than a source.
 *
 * @return array{0: string, 1: Report}
 */
function retoast(string $source, string $path = 'resources/views/pages/settings/⚡profile.blade.php'): array
{
    $root = sys_get_temp_dir().'/refit-toast-'.bin2hex(random_bytes(8));

    @mkdir($root.'/'.dirname($path), 0755, true);
    file_put_contents($root.'/'.$path, $source);

    $project = new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $report = new Report;

    (new RewriteToastCalls)->apply($project, $report);

    $rewritten = $project->get($path);

    deleteDirectory($root);

    return [$rewritten, $report];
}

it('raises the kit\'s toast through the event Sheaf listens for', function (): void {
    [$rewritten] = retoast("<?php Flux::toast(variant: 'success', text: __('Profile updated.')); ?>");

    expect($rewritten)->toContain(
        "\$this->dispatch('notify', type: 'success', content: __('Profile updated.'))",
    );
});

it('leaves a toast without a variant on Sheaf\'s default type', function (): void {
    // Flux has no `info`, and neither does this call. Sheaf's toast falls back to
    // it on its own, so nothing has to be invented here.
    [$rewritten] = retoast("<?php Flux::toast(text: __('Link sent.')); ?>");

    expect($rewritten)->toContain("\$this->dispatch('notify', content: __('Link sent.'))")
        ->not->toContain('type:');
});

it('reads a call whose message nests parentheses, brackets and quotes', function (): void {
    // The teams kit's hardest line: a translation with a quoted placeholder and a
    // replacements array. A regex ending at the first `)` gets this wrong.
    $source = <<<'PHP'
    <?php Flux::toast(variant: 'success', text: __('You left the team ":name"', ['name' => $team->name])); ?>
    PHP;

    [$rewritten] = retoast($source);

    expect($rewritten)->toContain(
        "\$this->dispatch('notify', type: 'success', content: __('You left the team \":name\"', ['name' => \$team->name]))",
    );
});

it('translates the one variant Flux and Sheaf disagree about', function (): void {
    [$rewritten] = retoast("<?php Flux::toast(variant: 'danger', text: 'Nope.'); ?>");

    expect($rewritten)->toContain("type: 'error'")
        ->not->toContain('danger');
});

it('carries a duration across, since both count in milliseconds', function (): void {
    [$rewritten] = retoast("<?php Flux::toast(text: 'Saved.', duration: 3000); ?>");

    expect($rewritten)->toContain("\$this->dispatch('notify', content: 'Saved.', duration: 3000)");
});

it('takes a lone positional argument as the message', function (): void {
    // `$text` is Flux's first parameter, whatever the order its documentation
    // lists the six in.
    [$rewritten] = retoast("<?php Flux::toast('Your changes have been saved.'); ?>");

    expect($rewritten)->toContain("\$this->dispatch('notify', content: 'Your changes have been saved.')");
});

it('drops what Sheaf\'s toast has no room for, and says so', function (): void {
    [$rewritten, $report] = retoast(
        "<?php Flux::toast(heading: 'Changes saved.', text: 'Update this in settings.', variant: 'danger'); ?>",
    );

    expect($rewritten)->toContain("\$this->dispatch('notify', content: 'Update this in settings.', type: 'error')")
        ->not->toContain('Changes saved.');

    expect(implode("\n", $report->notes()))->toContain('heading');
});

it('refuses a variant it cannot read, rather than guessing at one', function (): void {
    // `$variant` could hold `danger`, and the name it would have to become lives
    // wherever that variable was set.
    $source = "<?php Flux::toast(variant: \$variant, text: 'x'); ?>";

    [$rewritten, $report] = retoast($source);

    expect($rewritten)->toBe($source);
    expect(implode("\n", $report->warnings()))->toContain('could not read its arguments');
});

it('reads further positional arguments against Flux\'s real signature', function (): void {
    // toast($text, $heading, $duration, ...) — so this is a message, a heading
    // Sheaf has no room for, and a duration that carries across.
    [$rewritten, $report] = retoast("<?php Flux::toast('Saved.', 'Changes saved.', 2000); ?>");

    expect($rewritten)->toContain("\$this->dispatch('notify', content: 'Saved.', duration: 2000)")
        ->not->toContain('Changes saved.');

    expect(implode("\n", $report->notes()))->toContain('heading');
});

it('refuses a call with more arguments than Flux takes', function (): void {
    $source = "<?php Flux::toast('a', 'b', 1, 'success', 'top end', '/x', 'seventh'); ?>";

    [$rewritten] = retoast($source);

    expect($rewritten)->toBe($source);
});

it('refuses a call that names the same argument twice over', function (): void {
    // Not valid PHP, but a rewrite should not be the thing that decides that.
    $source = "<?php Flux::toast('Saved.', text: 'Also saved.'); ?>";

    [$rewritten] = retoast($source);

    expect($rewritten)->toBe($source);
});

it('refuses a toast with nothing to say', function (): void {
    $source = '<?php Flux::toast(); ?>';

    [$rewritten] = retoast($source);

    expect($rewritten)->toBe($source);
});

it('drops the import once the file has stopped naming Flux', function (): void {
    $source = <<<'PHP'
    <?php

    use Flux\Flux;
    use Livewire\Component;

    new class extends Component {
        public function save(): void
        {
            Flux::toast(variant: 'success', text: __('Profile updated.'));
        }
    };
    PHP;

    [$rewritten] = retoast($source);

    expect($rewritten)->not->toContain('use Flux\Flux;')
        ->toContain('use Livewire\Component;');
});

it('keeps the import while anything else in the file still needs it', function (): void {
    $source = <<<'PHP'
    <?php

    use Flux\Flux;

    $classes = Flux::classes('shrink-0');

    Flux::toast(text: 'Saved.');
    PHP;

    [$rewritten] = retoast($source);

    expect($rewritten)->toContain('use Flux\Flux;')
        ->toContain("Flux::classes('shrink-0')")
        ->toContain("\$this->dispatch('notify', content: 'Saved.')");
});

it('rewrites a class component the same way it rewrites a Volt view', function (): void {
    [$rewritten] = retoast(
        "<?php Flux::toast(variant: 'success', text: __('Password updated.')); ?>",
        'app/Livewire/Settings/Security.php',
    );

    expect($rewritten)->toContain("\$this->dispatch('notify', type: 'success', content: __('Password updated.'))");
});

it('reports a toast raised where there is no component to dispatch from', function (): void {
    $source = "<?php Flux::toast(text: 'Logged out.'); ?>";

    [$rewritten, $report] = retoast($source, 'app/Actions/Auth/Logout.php');

    expect($rewritten)->toBe($source);
    expect(implode("\n", $report->warnings()))
        ->toContain('outside a Livewire component')
        ->toContain('session()->flash');
});

it('rewrites every call in a file, not only the first', function (): void {
    $source = <<<'PHP'
    <?php

    Flux::toast(variant: 'success', text: __('One.'));
    Flux::toast(variant: 'warning', text: __('Two.'));
    PHP;

    [$rewritten] = retoast($source);

    expect($rewritten)->toContain("type: 'success', content: __('One.')")
        ->toContain("type: 'warning', content: __('Two.')")
        ->not->toContain('Flux::toast');
});

it('leaves a project that never toasts out of the plan', function (): void {
    $root = sys_get_temp_dir().'/refit-toast-'.bin2hex(random_bytes(8));

    @mkdir($root.'/resources/views', 0755, true);
    file_put_contents($root.'/resources/views/welcome.blade.php', '<div>Nothing here.</div>');

    $project = new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    expect(RewriteToastCalls::used($project))->toBeFalse();

    deleteDirectory($root);
});
