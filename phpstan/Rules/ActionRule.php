<?php

namespace Astrotomic\PHPStan\Rules;

use App\Actions\Action;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class ActionRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Actions\\')) {
            return [];
        }

        if ($this->hasClassnameSuffix($node, 'Action')) {
            return [
                $this->error(
                    message: 'Actions should not have a `Action` classname suffix.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->isExtending($node, Action::class)) {
            return [
                $this->error(
                    message: sprintf('Actions must extend `%s` class.', Action::class),
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasMethod($node, 'execute')) {
            return [
                $this->error(
                    message: 'Actions have to define a `execute()` method.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        $publicMethods = collect($node->getMethods())
            ->filter(fn (ClassMethod $method): bool => $method->isPublic())
            ->reject(fn (ClassMethod $method): bool => $method->name->name === '__construct')
            ->reject(fn (ClassMethod $method): bool => $method->name->name === 'execute');

        if ($publicMethods->isNotEmpty()) {
            return $publicMethods
                ->map(fn (ClassMethod $method) => $this->error(
                    message: 'Actions are not allowed to define other public methods than `execute()`.',
                    node: $method,
                    scope: $scope,
                ))
                ->all();
        }

        return [];
    }
}
