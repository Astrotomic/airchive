<?php

namespace Astrotomic\PHPStan\Rules;

use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class JsonResourceRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isExtending($node, JsonResource::class)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Http\\Resources\\')) {
            return [
                $this->error(
                    message: 'JsonResources have to be put in `App\\Http\\Resources\\` namespace.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasClassnameSuffix($node, 'Resource')) {
            return [
                $this->error(
                    message: 'JsonResources classnames have to end with `Resource`.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasMethod($node, 'toArray', 'array')) {
            return [
                $this->error(
                    message: 'JsonResources have to define a `toArray()` method with return-type of `array`.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasResourceTypeDefinition($node)) {
            return [
                $this->error(
                    message: 'JsonResources must define the `$resource` type using either a class-level `@property` doc-block or a `@var` tag on the `$resource` property.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        return [];
    }

    /**
     * Check if the resource type is defined via either:
     * 1. A class-level @property $resource doc-block
     * 2. A @var tag on the $resource property
     */
    private function hasResourceTypeDefinition(Class_ $node): bool
    {
        // Check for class-level @property $resource
        $classDoc = $this->getDocComment($node);
        if ($classDoc !== null) {
            $propertyTags = $classDoc->getPropertyTagValues();
            foreach ($propertyTags as $propertyTag) {
                if ($propertyTag->propertyName === '$resource') {
                    return true;
                }
            }
        }

        // Check for @var on the $resource property
        $resourceProperty = $node->getProperty('resource');
        if ($resourceProperty !== null) {
            $resourceDoc = $this->getDocComment($resourceProperty);
            if ($resourceDoc !== null && ! empty($resourceDoc->getVarTagValues())) {
                return true;
            }
        }

        return false;
    }
}
