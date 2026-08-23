<?php

namespace Astrotomic\PHPStan\Rules;

use Illuminate\Contracts\Queue\ShouldQueue;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class ListenerRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Listeners\\')) {
            return [];
        }

        if ($this->hasClassnameSuffix($node, 'Listener')) {
            return [
                $this->error(
                    message: 'Listeners should not have a `Listener` classname suffix.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasMethod($node, 'handle', 'void')) {
            return [
                $this->error(
                    message: 'Listeners have to define a `handle()` method with return-type of `void`.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if ($this->implementsInterface($node, ShouldQueue::class)) {
            return [
                $this->error(
                    message: sprintf('Listeners should not implement `%s` interface.', ShouldQueue::class),
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        $publicMethods = collect($node->getMethods())
            ->filter(fn (ClassMethod $method): bool => $method->isPublic())
            ->reject(fn (ClassMethod $method): bool => $method->name->name === '__construct')
            ->reject(fn (ClassMethod $method): bool => $method->name->name === 'handle');

        if ($publicMethods->isNotEmpty()) {
            return $publicMethods
                ->map(fn (ClassMethod $method) => $this->error(
                    message: 'Listeners are not allowed to define other public methods than `handle()`.',
                    node: $method,
                    scope: $scope,
                ))
                ->all();
        }

        return [];
    }
}
