<?php

namespace Astrotomic\PHPStan\Rules;

use Illuminate\Foundation\Http\FormRequest;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Class_>
 */
class FormRequestRule extends AbstractClassRule
{
    /**
     * @param  Class_  $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->shouldBeProcessed($node)) {
            return [];
        }

        if (! $this->isExtending($node, FormRequest::class)) {
            return [];
        }

        if (! $this->isInNamespace($node, 'App\\Http\\Requests\\')) {
            return [
                $this->error(
                    message: 'FormRequests have to be put in `App\\Http\\Requests\\` namespace.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasClassnameSuffix($node, 'Request')) {
            return [
                $this->error(
                    message: 'FormRequest classnames have to end with `Request`.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        if (! $this->hasMethod($node, 'rules', 'array')) {
            return [
                $this->error(
                    message: 'FormRequests have to define a `rules()` method with return-type of `array`.',
                    node: $node,
                    scope: $scope
                ),
            ];
        }

        return [];
    }
}
