<?php

namespace Astrotomic\PHPStan\Rules;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class ModelRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isExtending($node, Model::class)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Models\\')) {
            return [
                $this->error(
                    message: 'Models have to be put in `App\\Models\\` namespace.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        $accessorErrors = collect($node->getMethods())
            ->filter(fn (ClassMethod $method): bool => $method->isPublic())
            ->filter(fn (ClassMethod $method): bool => str_starts_with($method->name->name, 'get'))
            ->filter(fn (ClassMethod $method): bool => str_ends_with($method->name->name, 'Attribute'))
            ->map(fn (ClassMethod $method): array => array_filter([
                $method->getReturnType() === null
                    ? $this->error(
                        message: 'Model accessors have to define a return type.',
                        node: $method,
                        scope: $scope
                    )
                    : null,
                ! empty($method->getParams())
                    ? $this->error(
                        message: 'Model accessors are not allowed to receive parameters - as this indicates overriding a real attribute.',
                        node: $method,
                        scope: $scope,
                        reason: 'In case you have to manipulate a database value use a model cast.',
                        link: 'https://laravel.com/docs/10.x/eloquent-mutators#attribute-casting'
                    )
                    : null,
            ]))
            ->collapse();

        if ($accessorErrors->isNotEmpty()) {
            return $accessorErrors->all();
        }

        $scopeErrors = collect($node->getMethods())
            ->filter(fn (ClassMethod $method): bool => $method->isPublic())
            ->filter(fn (ClassMethod $method): bool => str_starts_with($method->name->name, 'scope'))
            ->filter(fn (ClassMethod $method): bool => strlen($method->name->name) > 5 && ctype_upper($method->name->name[5]))
            ->map(fn (ClassMethod $method): array => [
                $this->error(
                    message: 'Model scopes are forbidden - as they are too much magic.',
                    node: $method,
                    scope: $scope,
                    reason: 'In case you have a complex query part you want to abstract use a custom query builder class.',
                    link: 'https://timacdonald.me/dedicated-eloquent-model-query-builders/'
                ),
            ])
            ->collapse();

        if ($scopeErrors->isNotEmpty()) {
            return $scopeErrors->all();
        }

        return [];
    }
}
