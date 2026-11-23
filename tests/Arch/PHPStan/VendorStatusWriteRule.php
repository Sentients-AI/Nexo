<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
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
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Rule L4 for the Vendor (dependency-rules.md §1, INV-14): only
 * VendorLifecycle writes a Vendor's status. It reports, anywhere else:
 *
 * - assigning the property or offset: `$vendor->status = …`, `$vendor['status'] = …`, `??=`;
 * - `$vendor->setAttribute('status', …)`;
 * - a status key in the values given to a write method (WRITE_METHODS) called
 *   on a Vendor, on `Vendor::`, on an Eloquent builder of Vendor, or on a
 *   query builder whose chain starts from the `vendors` table or a Vendor
 *   builder (`toBase()`).
 *
 * It sees only what is statically known: a status key in a non-constant
 * array, a column or table name held in a variable, raw SQL
 * (`DB::statement`, `DB::update`), reflection and code outside PHPStan's
 * paths (tests) are not reported.
 *
 * @implements Rule<Expr>
 */
final class VendorStatusWriteRule implements Rule
{
    public const IDENTIFIER = 'nexo.vendorStatusWrite';

    private const COLUMN = 'status';

    private const TABLE = 'vendors';

    /** Methods whose array arguments are written to the model or table. */
    private const WRITE_METHODS = [
        'fill', 'forceFill', 'setRawAttributes', 'update', 'updateQuietly', 'updateOrFail',
        'insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'updateOrInsert',
        'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'make',
        'firstOrCreate', 'firstOrNew', 'updateOrCreate',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->getClassReflection()?->getName() === VendorLifecycle::class) {
            return [];
        }

        if (! $this->writesStatus($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Only %s may write a Vendor\'s status (dependency-rules.md L4).',
                VendorLifecycle::class,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function writesStatus(Expr $node, Scope $scope): bool
    {
        if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
            return $this->isStatusTarget($node->var, $scope);
        }

        // A nullsafe call is analysed as a MethodCall too, so it is matched once, here.
        if ($node instanceof MethodCall) {
            $method = $node->name instanceof Identifier ? $node->name->toString() : null;

            if ($method === null) {
                return false;
            }

            if ($method === 'setAttribute') {
                return $this->isVendor($scope->getType($node->var)) && $this->isStatusName($node->getArgs()[0] ?? null, $scope);
            }

            return in_array($method, self::WRITE_METHODS, true)
                && $this->targetsVendors($node->var, $scope)
                && $this->argumentsWriteStatus($node->getArgs(), $scope);
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier && $node->class instanceof Name) {
            return in_array($node->name->toString(), self::WRITE_METHODS, true)
                && $this->isVendor(new ObjectType($scope->resolveName($node->class)))
                && $this->argumentsWriteStatus($node->getArgs(), $scope);
        }

        return false;
    }

    /** `$vendor->status`, `$vendor?->status` or `$vendor['status']`. */
    private function isStatusTarget(Expr $target, Scope $scope): bool
    {
        if ($target instanceof PropertyFetch || $target instanceof NullsafePropertyFetch) {
            return $target->name instanceof Identifier
                && $target->name->toString() === self::COLUMN
                && $this->isVendor($scope->getType($target->var));
        }

        if ($target instanceof ArrayDimFetch && $target->dim !== null) {
            return $this->isVendor($scope->getType($target->var))
                && $this->isConstantString($scope->getType($target->dim), self::COLUMN);
        }

        return false;
    }

    /** The receiver writes to vendors: a Vendor, its Eloquent builder, or a query on the table. */
    private function targetsVendors(Expr $receiver, Scope $scope): bool
    {
        $expr = $receiver;

        while (true) {
            $type = $scope->getType($expr);

            if ($this->isVendor($type) || $this->isVendorBuilder($type)) {
                return true;
            }

            if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
                && $expr->name instanceof Identifier
                && in_array($expr->name->toString(), ['table', 'from'], true)
            ) {
                $table = $expr->getArgs()[0] ?? null;

                return $table !== null && $this->isConstantString($scope->getType($table->value), self::TABLE);
            }

            if ($expr instanceof StaticCall) {
                return $expr->class instanceof Name && $this->isVendor(new ObjectType($scope->resolveName($expr->class)));
            }

            if (! $expr instanceof MethodCall && ! $expr instanceof NullsafeMethodCall) {
                return false;
            }

            $expr = $expr->var;
        }
    }

    /**
     * @param  array<Arg>  $args
     */
    private function argumentsWriteStatus(array $args, Scope $scope): bool
    {
        foreach ($args as $arg) {
            foreach ($scope->getType($arg->value)->getConstantArrays() as $array) {
                if ($this->hasStatusKey($array)) {
                    return true;
                }

                // upsert() and insert() take a list of rows.
                foreach ($array->getValueTypes() as $row) {
                    foreach ($row->getConstantArrays() as $rowArray) {
                        if ($this->hasStatusKey($rowArray)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function hasStatusKey(Type $array): bool
    {
        return ! $array->hasOffsetValueType(new ConstantStringType(self::COLUMN))->no();
    }

    private function isStatusName(?Arg $arg, Scope $scope): bool
    {
        return $arg !== null && $this->isConstantString($scope->getType($arg->value), self::COLUMN);
    }

    private function isConstantString(Type $type, string $value): bool
    {
        foreach ($type->getConstantStrings() as $string) {
            if ($string->getValue() === $value) {
                return true;
            }
        }

        return false;
    }

    private function isVendor(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        return ! $type->isNull()->yes() && (new ObjectType(Vendor::class))->isSuperTypeOf($type)->yes();
    }

    private function isVendorBuilder(Type $type): bool
    {
        if (! (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        return $this->isVendor($type->getTemplateType(EloquentBuilder::class, 'TModel'));
    }
}
