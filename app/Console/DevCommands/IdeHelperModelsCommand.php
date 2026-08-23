<?php

namespace App\Console\DevCommands;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;

class IdeHelperModelsCommand extends ModelsCommand
{
    protected function customizePhpDoc(string $class, string $docComment): string
    {
        // remove leading FQCN comment
        $docComment = str_replace("* {$class}", '*', $docComment);

        // remove default Eloquent mixin
        $docComment = str_replace('@mixin \Eloquent', '', $docComment);
        $docComment = str_replace('|\Eloquent ', ' ', $docComment);

        // remove wrongfully added query methods
        $docComment = preg_replace('/@method static .+ newQuery\(\)/', '', $docComment);
        $docComment = preg_replace('/@method static .+ newModelQuery\(\)/', '', $docComment);

        // remove model from query type
        $docComment = preg_replace('/@method static (.+)\|.+ query\(\)/', '@method static $1 query()', $docComment);
        $docComment = preg_replace('/@method static (\w+Builder)<.+> query\(\)/', '@method static $1 query()', $docComment);

        // remove blank lines
        $docComment = preg_replace("/\s\*\s*\n/", '', $docComment);

        return '/**'.PHP_EOL.Str::of($docComment)
            ->explode(PHP_EOL)
            ->reject(fn (string $line): bool => str_starts_with($line, '/') || str_ends_with($line, '/'))
            ->groupBy(fn (string $line): string => Str::of($line)->after('@')->before(' '))
            ->map(fn (Collection $lines) => $lines->sortBy(fn (string $line) => Str::afterLast($line, ' '))->values())
            ->flatten()
            ->implode(PHP_EOL).PHP_EOL.'*/';
    }

    /**
     * @param  string  $class
     * @return string
     */
    protected function createPhpDocs($class)
    {
        $reflection = new ReflectionClass($class);
        $namespace = $reflection->getNamespaceName();
        $classname = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $keyword = $this->getClassKeyword($reflection);
        $interfaceNames = array_diff_key(
            $reflection->getInterfaceNames(),
            $reflection->getParentClass()->getInterfaceNames()
        );

        $phpdoc = new DocBlock($reflection, new Context($namespace));
        if ($this->reset) {
            $phpdoc->setText(
                (new DocBlock($reflection, new Context($namespace)))->getText()
            );
            foreach ($phpdoc->getTags() as $tag) {
                if (
                    in_array($tag->getName(), ['property', 'property-read', 'property-write', 'method', 'mixin'])
                    || ($tag->getName() === 'noinspection' && in_array($tag->getContent(), ['PhpUnnecessaryFullyQualifiedNameInspection', 'PhpFullyQualifiedNameUsageInspection']))
                ) {
                    $phpdoc->deleteTag($tag);
                }
            }
        }

        $properties = [];
        $methods = [];
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == 'property' || $name == 'property-read' || $name == 'property-write') {
                $properties[] = $tag->getVariableName();
            } elseif ($name == 'method') {
                $methods[] = $tag->getMethodName();
            }
        }

        foreach ($this->properties as $name => $property) {
            $name = "\$$name";

            if ($this->hasCamelCaseModelProperties()) {
                $name = Str::camel($name);
            }

            if (in_array($name, $properties)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }

            $tagLine = trim("@{$attr} {$property['type']} {$name} {$property['comment']}");
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        ksort($this->methods);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tagLine = "@method static {$method['type']} {$name}({$arguments})";
            if ($method['comment'] !== '') {
                $tagLine .= " {$method['comment']}";
            }
            $tag = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        if ($this->write) {
            $eloquentClassNameInModel = $this->getClassNameInDestinationFile($reflection, 'Eloquent');

            // remove the already existing tag to prevent duplicates
            foreach ($phpdoc->getTagsByName('mixin') as $tag) {
                if ($tag->getContent() === $eloquentClassNameInModel) {
                    $phpdoc->deleteTag($tag);
                }
            }

            $phpdoc->appendTag(Tag::createInstance('@mixin '.$eloquentClassNameInModel, $phpdoc));
        }

        if ($this->phpstorm_noinspections) {
            /**
             * Facades, Eloquent API
             *
             * @see https://www.jetbrains.com/help/phpstorm/php-fully-qualified-name-usage.html
             */
            $phpdoc->appendTag(Tag::createInstance('@noinspection PhpFullyQualifiedNameUsageInspection', $phpdoc));
            /**
             * Relations, other models in the same namespace
             *
             * @see https://www.jetbrains.com/help/phpstorm/php-unnecessary-fully-qualified-name.html
             */
            $phpdoc->appendTag(
                Tag::createInstance('@noinspection PhpUnnecessaryFullyQualifiedNameInspection', $phpdoc)
            );
        }

        $serializer = new DocBlockSerializer;
        $docComment = $serializer->getDocComment($phpdoc);

        if ($this->write_mixin) {
            $phpdocMixin = new DocBlock($reflection, new Context($namespace));
            // remove all mixin tags prefixed with IdeHelper
            foreach ($phpdocMixin->getTagsByName('mixin') as $tag) {
                if (Str::startsWith($tag->getContent(), 'IdeHelper')) {
                    $phpdocMixin->deleteTag($tag);
                }
            }

            $mixinClassName = "IdeHelper{$classname}";
            $phpdocMixin->appendTag(Tag::createInstance("@mixin {$mixinClassName}", $phpdocMixin));
            $mixinDocComment = $serializer->getDocComment($phpdocMixin);
            // remove blank lines if there's no text
            if (! $phpdocMixin->getText()) {
                $mixinDocComment = preg_replace("/\s\*\s*\n/", '', $mixinDocComment);
            }

            foreach ($phpdoc->getTagsByName('mixin') as $tag) {
                if (Str::startsWith($tag->getContent(), 'IdeHelper')) {
                    $phpdoc->deleteTag($tag);
                }
            }
            $docComment = $serializer->getDocComment($phpdoc);
        }

        // START customization
        $docComment = $this->customizePhpDoc($class, $docComment);
        // END customization

        if ($this->write) {
            $modelDocComment = $this->write_mixin ? $mixinDocComment : $docComment;
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $modelDocComment, $contents);
            } else {
                $replace = "{$modelDocComment}\n";
                $pos = strpos($contents, "final class {$classname}") ?: strpos($contents, "class {$classname}");
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, 0);
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to '.$filename);
            }
        }

        $classname = $this->write_mixin ? $mixinClassName : $classname;

        $allowDynamicAttributes = $this->write_mixin ? "#[\AllowDynamicProperties]\n\t" : '';
        $output = "namespace {$namespace}{\n{$docComment}\n\t{$allowDynamicAttributes}{$keyword}class {$classname} ";

        if (! $this->write_mixin) {
            $output .= "extends \Eloquent ";

            if ($interfaceNames) {
                $interfaces = implode(', \\', $interfaceNames);
                $output .= "implements \\{$interfaces} ";
            }
        }

        return $output."{}\n}\n\n";
    }
}
