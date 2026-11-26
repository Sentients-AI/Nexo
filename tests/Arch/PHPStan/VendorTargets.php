<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Domain\Vendor;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * What the Vendor PHPStan rules (L4, L8) treat as the Vendor: the model, its
 * Eloquent builder, and query-builder chains over the `vendors` table. Only
 * statically known types and constant table names are recognised.
 */
final class VendorTargets
{
    private const TABLE = 'vendors';

    /** The receiver writes to vendors: a Vendor, its Eloquent builder, or a query on the table. */
    public static function receivesVendorWrites(Expr $receiver, Scope $scope, string $model = Vendor::class, string $tableName = self::TABLE): bool
    {
        $expr = $receiver;

        while (true) {
            $type = $scope->getType($expr);

            if (self::isModel($type, $model) || self::isVendorBuilder($type, $model)) {
                return true;
            }

            if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
                && $expr->name instanceof Identifier
                && in_array($expr->name->toString(), ['table', 'from'], true)
            ) {
                $table = $expr->getArgs()[0] ?? null;

                return $table !== null && self::isConstantString($scope->getType($table->value), $tableName);
            }

            if ($expr instanceof StaticCall) {
                return $expr->class instanceof Name && self::isModel(new ObjectType($scope->resolveName($expr->class)), $model);
            }

            if (! $expr instanceof MethodCall && ! $expr instanceof NullsafeMethodCall) {
                return false;
            }

            $expr = $expr->var;
        }
    }

    public static function isVendor(Type $type): bool
    {
        return self::isModel($type, Vendor::class);
    }

    private static function isModel(Type $type, string $model): bool
    {
        $type = TypeCombinator::removeNull($type);

        return ! $type->isNull()->yes() && (new ObjectType($model))->isSuperTypeOf($type)->yes();
    }

    public static function isVendorBuilder(Type $type, string $model = Vendor::class): bool
    {
        if (! (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        return self::isModel($type->getTemplateType(EloquentBuilder::class, 'TModel'), $model);
    }

    public static function isConstantString(Type $type, string $value): bool
    {
        foreach ($type->getConstantStrings() as $string) {
            if ($string->getValue() === $value) {
                return true;
            }
        }

        return false;
    }
}
