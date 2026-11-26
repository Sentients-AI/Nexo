<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Application\AssignFirstOwner;
use App\Modules\Vendors\Application\ChangeOwnership;
use App\Modules\Vendors\Application\DeactivateMember;
use App\Modules\Vendors\Domain\VendorMember;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;

/** @implements Rule<Expr> */
final class MembershipWriteRule implements Rule
{
    public const MESSAGE = 'Only the audited membership use cases may write membership state, and membership identity is immutable (L11).';

    private const COLUMNS = ['status', 'role', 'vendor_id', 'user_id'];

    private const INSERT = ['insert', 'insertgetid', 'insertorignore', 'create', 'createquietly', 'forcecreate', 'forcecreatequietly'];

    private const UPDATE = [
        'fill', 'forcefill', 'setrawattributes', 'update', 'updatequietly', 'updateorfail',
        'upsert', 'updateorinsert', 'updateorcreate', 'firstorcreate', 'firstornew', 'make',
    ];

    private const COLUMN_METHODS = ['setattribute', 'offsetset', 'increment', 'decrement', 'incrementquietly', 'decrementquietly'];

    private const ROW_METHODS = ['save', 'savequietly', 'saveorfail', 'push', 'pushquietly', 'delete', 'deleteorfail', 'deletequietly', 'forcedelete', 'truncate', 'destroy', 'forcedestroy'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        [$method, $columns] = $this->write($node, $scope);
        if ($method === null) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName();
        if (($class === AssignFirstOwner::class && in_array($method, self::INSERT, true))
            || (in_array($class, [ChangeOwnership::class, DeactivateMember::class], true)
                && $method === 'update' && array_intersect($columns, ['vendor_id', 'user_id']) === [])) {
            return [];
        }

        return [RuleErrorBuilder::message(self::MESSAGE)->identifier('nexo.membershipWrite')->build()];
    }

    /** @return array{string|null, list<string>} */
    private function write(Expr $node, Scope $scope): array
    {
        if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
            $target = $node->var;
            if (($target instanceof PropertyFetch || $target instanceof ArrayDimFetch)
                && (new ObjectType(VendorMember::class))->isSuperTypeOf($scope->getType($target->var))->yes()) {
                $columns = $target instanceof PropertyFetch
                    ? ($target->name instanceof Identifier ? [$target->name->toString()] : [])
                    : ($target->dim === null ? [] : $this->strings($target->dim, $scope));

                return array_intersect($columns, self::COLUMNS) === [] ? [null, []] : ['assign', $columns];
            }
        }

        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall) {
            return [null, []];
        }

        if (! $node->name instanceof Identifier) {
            return [null, []];
        }

        $targetsMember = $node instanceof StaticCall
            ? ($node->class instanceof Name && $scope->resolveName($node->class) === VendorMember::class)
            : VendorTargets::receivesVendorWrites($node->var, $scope, VendorMember::class, 'vendor_members');
        if (! $targetsMember) {
            return [null, []];
        }

        $method = strtolower($node->name->toString());
        if (in_array($method, self::ROW_METHODS, true) || in_array($method, self::INSERT, true)) {
            return [$method, []];
        }

        if (in_array($method, self::COLUMN_METHODS, true)) {
            $argument = $node->getArgs()[0] ?? null;
            $columns = $argument === null ? [] : $this->strings($argument->value, $scope);

            return array_intersect($columns, self::COLUMNS) === [] ? [null, []] : [$method, $columns];
        }

        if (in_array($method, self::UPDATE, true)) {
            $columns = [];
            foreach ($node->getArgs() as $argument) {
                foreach ($scope->getType($argument->value)->getConstantArrays() as $array) {
                    foreach ($array->getValueTypes() as $row) {
                        foreach ($row->getConstantArrays() as $nested) {
                            foreach (self::COLUMNS as $column) {
                                if (! $nested->hasOffsetValueType(new ConstantStringType($column))->no()) {
                                    $columns[] = $column;
                                }
                            }
                        }
                    }
                    foreach (self::COLUMNS as $column) {
                        if (! $array->hasOffsetValueType(new ConstantStringType($column))->no()
                            || ($method === 'upsert' && VendorTargets::isConstantString($array->getIterableValueType(), $column))) {
                            $columns[] = $column;
                        }
                    }
                }
            }

            return $columns === [] ? [null, []] : [$method, $columns];
        }

        return [null, []];
    }

    /** @return list<string> */
    private function strings(Expr $expression, Scope $scope): array
    {
        return array_map(static fn (ConstantStringType $type): string => $type->getValue(), $scope->getType($expression)->getConstantStrings());
    }
}
