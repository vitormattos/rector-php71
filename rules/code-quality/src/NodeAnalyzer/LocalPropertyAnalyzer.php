<?php

declare(strict_types=1);

namespace Rector\CodeQuality\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\CodeQuality\TypeResolver\ArrayDimFetchTypeResolver;
use Rector\Core\NodeAnalyzer\ClassNodeAnalyzer;
use Rector\Core\NodeAnalyzer\PropertyFetchAnalyzer;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Symplify\Astral\NodeTraverser\SimpleCallableNodeTraverser;

final class LocalPropertyAnalyzer
{
    /**
     * @var string
     */
    private const LARAVEL_COLLECTION_CLASS = 'Illuminate\Support\Collection';

    /**
     * @var SimpleCallableNodeTraverser
     */
    private $simpleCallableNodeTraverser;

    /**
     * @var ClassNodeAnalyzer
     */
    private $classNodeAnalyzer;

    /**
     * @var NodeNameResolver
     */
    private $nodeNameResolver;

    /**
     * @var BetterNodeFinder
     */
    private $betterNodeFinder;

    /**
     * @var ArrayDimFetchTypeResolver
     */
    private $arrayDimFetchTypeResolver;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var PropertyFetchAnalyzer
     */
    private $propertyFetchAnalyzer;

    /**
     * @var TypeFactory
     */
    private $typeFactory;

    public function __construct(SimpleCallableNodeTraverser $simpleCallableNodeTraverser, ClassNodeAnalyzer $classNodeAnalyzer, NodeNameResolver $nodeNameResolver, BetterNodeFinder $betterNodeFinder, ArrayDimFetchTypeResolver $arrayDimFetchTypeResolver, NodeTypeResolver $nodeTypeResolver, PropertyFetchAnalyzer $propertyFetchAnalyzer, TypeFactory $typeFactory)
    {
        $this->simpleCallableNodeTraverser = $simpleCallableNodeTraverser;
        $this->classNodeAnalyzer = $classNodeAnalyzer;
        $this->nodeNameResolver = $nodeNameResolver;
        $this->betterNodeFinder = $betterNodeFinder;
        $this->arrayDimFetchTypeResolver = $arrayDimFetchTypeResolver;
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->propertyFetchAnalyzer = $propertyFetchAnalyzer;
        $this->typeFactory = $typeFactory;
    }

    /**
     * @return array<string, Type>
     */
    public function resolveFetchedPropertiesToTypesFromClass(Class_ $class): array
    {
        $fetchedLocalPropertyNameToTypes = [];
        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($class->stmts, function (Node $node) use (&$fetchedLocalPropertyNameToTypes): ?int {
            // skip anonymous class scope
            if ($this->classNodeAnalyzer->isAnonymousClass($node)) {
                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }
            if (! $node instanceof PropertyFetch) {
                return null;
            }
            if (! $this->propertyFetchAnalyzer->isLocalPropertyFetch($node)) {
                return null;
            }
            if ($this->shouldSkipPropertyFetch($node)) {
                return null;
            }
            $propertyName = $this->nodeNameResolver->getName($node->name);
            if ($propertyName === null) {
                return null;
            }
            $propertyFetchType = $this->resolvePropertyFetchType($node);
            $fetchedLocalPropertyNameToTypes[$propertyName][] = $propertyFetchType;
            return null;
        });
        return $this->normalizeToSingleType($fetchedLocalPropertyNameToTypes);
    }

    private function shouldSkipPropertyFetch(PropertyFetch $propertyFetch): bool
    {
        // special Laravel collection scope
        if ($this->shouldSkipForLaravelCollection($propertyFetch)) {
            return true;
        }
        if ($this->isPartOfClosureBind($propertyFetch)) {
            return true;
        }
        return $this->isPartOfClosureBindTo($propertyFetch);
    }

    private function resolvePropertyFetchType(PropertyFetch $propertyFetch): Type
    {
        $parentNode = $propertyFetch->getAttribute(AttributeKey::PARENT_NODE);
        // possible get type
        if ($parentNode instanceof Assign) {
            return $this->nodeTypeResolver->getStaticType($parentNode->expr);
        }
        if ($parentNode instanceof ArrayDimFetch) {
            return $this->arrayDimFetchTypeResolver->resolve($parentNode);
        }
        return new MixedType();
    }

    /**
     * @param array<string, Type[]> $propertyNameToTypes
     * @return array<string, Type>
     */
    private function normalizeToSingleType(array $propertyNameToTypes): array
    {
        // normalize types to union
        $propertyNameToType = [];
        foreach ($propertyNameToTypes as $name => $types) {
            $propertyNameToType[$name] = $this->typeFactory->createMixedPassedOrUnionType($types);
        }
        return $propertyNameToType;
    }

    private function shouldSkipForLaravelCollection(PropertyFetch $propertyFetch): bool
    {
        $staticCallOrClassMethod = $this->betterNodeFinder->findFirstAncestorInstancesOf($propertyFetch, [ClassMethod::class, StaticCall::class]);
        if (! $staticCallOrClassMethod instanceof StaticCall) {
            return false;
        }
        return $this->nodeNameResolver->isName($staticCallOrClassMethod->class, self::LARAVEL_COLLECTION_CLASS);
    }

    /**
     * Local property is actually not local one, but belongs to passed object
     * See https://ocramius.github.io/blog/accessing-private-php-class-members-without-reflection/
     */
    private function isPartOfClosureBind(PropertyFetch $propertyFetch): bool
    {
        $parentStaticCall = $this->betterNodeFinder->findParentType($propertyFetch, StaticCall::class);
        if (! $parentStaticCall instanceof StaticCall) {
            return false;
        }
        if (! $this->nodeNameResolver->isName($parentStaticCall->class, 'Closure')) {
            return true;
        }
        return $this->nodeNameResolver->isName($parentStaticCall->name, 'bind');
    }

    private function isPartOfClosureBindTo(PropertyFetch $propertyFetch): bool
    {
        $parentMethodCall = $this->betterNodeFinder->findParentType($propertyFetch, MethodCall::class);
        if (! $parentMethodCall instanceof MethodCall) {
            return false;
        }
        if (! $parentMethodCall->var instanceof Closure) {
            return false;
        }
        return $this->nodeNameResolver->isName($parentMethodCall->name, 'bindTo');
    }
}