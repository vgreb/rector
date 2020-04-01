<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver\PHPStan\Scope;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PHPStan\AnalysedCodeException;
use PHPStan\Analyser\MutatingScope;
use PHPStan\Analyser\NodeScopeResolver as PHPStanNodeScopeResolver;
use PHPStan\Node\UnreachableStatementNode;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Caching\ChangedFilesDetector;
use Rector\Caching\FileSystem\DependencyResolver;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Collector\TraitNodeScopeCollector;
use Rector\NodeTypeResolver\PHPStan\Scope\NodeVisitor\RemoveDeepChainMethodCallNodeVisitor;
use Symplify\SmartFileSystem\SmartFileInfo;

/**
 * @inspired by https://github.com/silverstripe/silverstripe-upgrader/blob/532182b23e854d02e0b27e68ebc394f436de0682/src/UpgradeRule/PHP/Visitor/PHPStanScopeVisitor.php
 * - https://github.com/silverstripe/silverstripe-upgrader/pull/57/commits/e5c7cfa166ad940d9d4ff69537d9f7608e992359#diff-5e0807bb3dc03d6a8d8b6ad049abd774
 */
final class NodeScopeResolver
{
    /**
     * @var PHPStanNodeScopeResolver
     */
    private $phpStanNodeScopeResolver;

    /**
     * @var ScopeFactory
     */
    private $scopeFactory;

    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    /**
     * @var RemoveDeepChainMethodCallNodeVisitor
     */
    private $removeDeepChainMethodCallNodeVisitor;

    /**
     * @var TraitNodeScopeCollector
     */
    private $traitNodeScopeCollector;

    /**
     * @var DependencyResolver
     */
    private $dependencyResolver;

    /**
     * @var ChangedFilesDetector
     */
    private $changedFilesDetector;

    public function __construct(
        ChangedFilesDetector $changedFilesDetector,
        ScopeFactory $scopeFactory,
        PHPStanNodeScopeResolver $phpStanNodeScopeResolver,
        ReflectionProvider $reflectionProvider,
        RemoveDeepChainMethodCallNodeVisitor $removeDeepChainMethodCallNodeVisitor,
        TraitNodeScopeCollector $traitNodeScopeCollector,
        DependencyResolver $dependencyResolver
    ) {
        $this->scopeFactory = $scopeFactory;
        $this->phpStanNodeScopeResolver = $phpStanNodeScopeResolver;
        $this->reflectionProvider = $reflectionProvider;
        $this->removeDeepChainMethodCallNodeVisitor = $removeDeepChainMethodCallNodeVisitor;
        $this->traitNodeScopeCollector = $traitNodeScopeCollector;
        $this->dependencyResolver = $dependencyResolver;
        $this->changedFilesDetector = $changedFilesDetector;
    }

    /**
     * @param Node[] $nodes
     * @return Node[]
     */
    public function processNodes(array $nodes, SmartFileInfo $smartFileInfo): array
    {
        $this->removeDeepChainMethodCallNodes($nodes);

        $scope = $this->scopeFactory->createFromFile($smartFileInfo->getRealPath());

        $dependentFiles = [];

        // skip chain method calls, performance issue: https://github.com/phpstan/phpstan/issues/254
        $nodeCallback = function (Node $node, MutatingScope $scope) use (&$dependentFiles): void {
            // the class reflection is resolved AFTER entering to class node
            // so we need to get it from the first after this one
            if ($node instanceof Class_ || $node instanceof Interface_) {
                $scope = $this->resolveClassOrInterfaceScope($node, $scope);
            }

            // traversing trait inside class that is using it scope (from referenced) - the trait traversed by Rector is different (directly from parsed file)
            if ($scope->isInTrait()) {
                $traitName = $scope->getTraitReflection()->getName();
                $this->traitNodeScopeCollector->addForTraitAndNode($traitName, $node, $scope);

                return;
            }

            // special case for unreachable nodes
            if ($node instanceof UnreachableStatementNode) {
                $originalNode = $node->getOriginalStatement();
                $originalNode->setAttribute(AttributeKey::IS_UNREACHABLE, true);
                $originalNode->setAttribute(AttributeKey::SCOPE, $scope);
            } else {
                $node->setAttribute(AttributeKey::SCOPE, $scope);
            }

            try {
                foreach ($this->dependencyResolver->resolveDependencies($node, $scope) as $dependentFile) {
                    $dependentFiles[] = $dependentFile;
                }
            } catch (AnalysedCodeException $analysedCodeException) {
                // @ignoreException
            }
        };

        /** @var MutatingScope $scope */
        $this->phpStanNodeScopeResolver->processNodes($nodes, $scope, $nodeCallback);

        // save for cache
        $this->changedFilesDetector->addFileWithDependencies($smartFileInfo, $dependentFiles);

        return $nodes;
    }

    /**
     * @param Node[] $nodes
     */
    private function removeDeepChainMethodCallNodes(array $nodes): void
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor($this->removeDeepChainMethodCallNodeVisitor);
        $nodeTraverser->traverse($nodes);
    }

    /**
     * @param Class_|Interface_ $classOrInterfaceNode
     */
    private function resolveClassOrInterfaceScope(
        Node $classOrInterfaceNode,
        MutatingScope $mutatingScope
    ): MutatingScope {
        $className = $this->resolveClassName($classOrInterfaceNode);
        $classReflection = $this->reflectionProvider->getClass($className);

        return $mutatingScope->enterClass($classReflection);
    }

    /**
     * @param Class_|Interface_|Trait_ $classLike
     */
    private function resolveClassName(ClassLike $classLike): string
    {
        if (isset($classLike->namespacedName)) {
            return (string) $classLike->namespacedName;
        }

        if ($classLike->name === null) {
            throw new ShouldNotHappenException();
        }

        return $classLike->name->toString();
    }
}
