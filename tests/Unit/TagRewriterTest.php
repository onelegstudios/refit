<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagRewriter;

beforeEach(function (): void {
    $this->rewriter = new TagRewriter;
});

it('renames both ends of a paired tag', function (): void {
    $result = $this->rewriter->rename(
        '<x-app-logo :sidebar="true">slot</x-app-logo>',
        'x-app-logo',
        'x-ui.app-logo',
    );

    expect($result)->toBe('<x-ui.app-logo :sidebar="true">slot</x-ui.app-logo>');
});

it('leaves a longer tag sharing the same prefix alone', function (): void {
    $result = $this->rewriter->rename(
        '<x-app-logo /> <x-app-logo-icon />',
        'x-app-logo',
        'x-ui.app-logo',
    );

    expect($result)->toBe('<x-ui.app-logo /> <x-app-logo-icon />');
});

it('rewrites only the named attributes', function (): void {
    $result = $this->rewriter->rewriteAttributeValues(
        '<flux:sidebar.item icon="home" class="home" />',
        'flux:',
        ['icon'],
        fn (Tag $tag, Attribute $attribute, string $value): ?string => $value === 'home' ? 'house' : null,
    );

    expect($result)->toBe('<flux:sidebar.item icon="house" class="home" />');
});

it('skips bound attributes, whose value is an expression', function (): void {
    $source = '<flux:button :icon="$icon" />';

    $result = $this->rewriter->rewriteAttributeValues(
        $source,
        'flux:',
        ['icon', ':icon'],
        fn (Tag $tag, Attribute $attribute, string $value): string => 'rewritten',
    );

    expect($result)->toBe($source);
});

it('lets the callback discriminate on the tag it is rewriting', function (): void {
    $source = '<flux:input name="email" /><flux:icon name="users" />';

    $result = $this->rewriter->rewriteAttributeValues(
        $source,
        'flux:',
        ['name'],
        fn (Tag $tag, Attribute $attribute, string $value): ?string => $tag->name === 'flux:icon' ? 'people' : null,
    );

    expect($result)->toBe('<flux:input name="email" /><flux:icon name="people" />');
});

it('rewrites a dotted tag suffix', function (): void {
    $result = $this->rewriter->rewriteNameSuffix(
        '<flux:icon.home class="size-4" /><flux:icon.loading />',
        'flux:icon.',
        fn (string $suffix): ?string => $suffix === 'home' ? 'house' : null,
    );

    expect($result)->toBe('<flux:icon.house class="size-4" /><flux:icon.loading />');
});

it('adds a missing attribute straight after the tag name', function (): void {
    $result = $this->rewriter->addAttribute(
        '<flux:toast.group>'.PHP_EOL.'    <flux:toast />'.PHP_EOL.'</flux:toast.group>',
        'flux:toast.group',
        'position',
        'top end',
    );

    expect($result)->toBe(
        '<flux:toast.group position="top end">'.PHP_EOL.'    <flux:toast />'.PHP_EOL.'</flux:toast.group>',
    );
});

it('leaves a tag that already carries the attribute alone', function (): void {
    $source = '<flux:toast.group position="bottom center"><flux:toast.group :position="$position">';

    $result = $this->rewriter->addAttribute($source, 'flux:toast.group', 'position', 'top end');

    expect($result)->toBe($source);
});

it('adds the attribute only to the tag it was given', function (): void {
    $result = $this->rewriter->addAttribute(
        '<flux:toast.group><flux:toast.group.item /></flux:toast.group>',
        'flux:toast.group',
        'position',
        'top end',
    );

    expect($result)->toBe('<flux:toast.group position="top end"><flux:toast.group.item /></flux:toast.group>');
});

it('removes an attribute together with the space in front of it', function (): void {
    $result = $this->rewriter->removeAttributes(
        '<flux:icon.check variant="solid" class="text-green-500" />',
        'flux:',
        fn (Tag $tag, Attribute $attribute): bool => $attribute->name === 'variant',
    );

    expect($result)->toBe('<flux:icon.check class="text-green-500" />');
});

it('closes up the line an attribute was alone on', function (): void {
    $result = $this->rewriter->removeAttributes(
        implode(PHP_EOL, [
            '<flux:icon.check',
            '    x-show="copied"',
            '    variant="solid"',
            '    class="text-green-500"',
            '></flux:icon>',
        ]),
        'flux:',
        fn (Tag $tag, Attribute $attribute): bool => $attribute->name === 'variant',
    );

    expect($result)->toBe(implode(PHP_EOL, [
        '<flux:icon.check',
        '    x-show="copied"',
        '    class="text-green-500"',
        '></flux:icon>',
    ]));
});

it('applies several edits to one tag without corrupting offsets', function (): void {
    $result = $this->rewriter->rewriteAttributeValues(
        '<flux:button icon="plus" icon-trailing="chevron-down" />',
        'flux:',
        ['icon', 'icon-trailing'],
        fn (Tag $tag, Attribute $attribute, string $value): string => strtoupper($value),
    );

    expect($result)->toBe('<flux:button icon="PLUS" icon-trailing="CHEVRON-DOWN" />');
});
