<?php

namespace Astrotomic\PHPStan\Rules;

use Illuminate\Foundation\Bus\Dispatchable;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class JobRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Jobs\\')) {
            return [];
        }

        if (! $this->hasClassnameSuffix($node, 'Job')) {
            return [
                $this->error(
                    message: 'Jobs must have a `Job` classname suffix.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if ($this->usesTrait($node, Dispatchable::class)) {
            return [
                $this->error(
                    message: sprintf('Jobs should not use `%s` trait.', Dispatchable::class),
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasMethod($node, 'handle')) {
            return [
                $this->error(
                    message: 'Jobs have to define a `handle()` method.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        $allowedMethods = [
            '__construct',
            '__unserialize',
            'handle',
            'backoff',
            'uniqueId',
            'failed',
            'retryUntil',
            'middleware',
            'tries',
        ];

        $publicMethods = collect($node->getMethods())
            ->filter(fn (ClassMethod $method): bool => $method->isPublic())
            ->reject(fn (ClassMethod $method): bool => in_array($method->name->name, $allowedMethods, true));

        if ($publicMethods->isNotEmpty()) {
            return $publicMethods
                ->map(fn (ClassMethod $method) => $this->error(
                    message: 'Jobs are not allowed to define other public methods than `handle()`.',
                    node: $method,
                    scope: $scope,
                ))
                ->all();
        }

        return [];
    }
}
