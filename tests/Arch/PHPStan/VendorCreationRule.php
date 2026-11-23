<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Application\CreateVendor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Rule L8 (dependency-rules.md §1; Story #1, #29 D5): only CreateVendor
 * creates a Vendor. It reports, anywhere else:
 *
 * - `new Vendor(…)`, including an anonymous subclass or a class-string of Vendor;
 * - a creating method (CREATING_METHODS) called on a Vendor, on `Vendor::`,
 *   on an Eloquent builder of Vendor, or on a query builder whose chain starts
 *   from the `vendors` table or a Vendor builder (`toBase()`);
 * - `Vendor::factory()`, and replicate() or newInstance() on a Vendor.
 *
 * Methods that may insert as well as update (upsert(), updateOrCreate(), …)
 * count as creating. It sees only what is statically known: save() or push()
 * on a Vendor (it cannot tell a new model from a stored one; the ways of
 * getting an unsaved Vendor are reported instead), container resolution
 * (`app(Vendor::class)`), unserialize() and reflection, a table name held in a
 * variable or aliased (`vendors as v`), raw SQL (`DB::insert`,
 * `DB::statement`) and code outside PHPStan's paths (tests, so a factory used
 * by tests) are not reported. A PHPStan ignore comment that names no
 * identifier also hides a report, as for L4.
 *
 * @implements Rule<Expr>
 */
final class VendorCreationRule implements Rule
{
    public const IDENTIFIER = 'nexo.vendorCreation';

    /** Builder and model methods that insert a row or build an unsaved Vendor. */
    private const CREATING_METHODS = [
        'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'createOrFirst',
        'createMany', 'createManyQuietly', 'make', 'newModelInstance',
        'firstOrCreate', 'firstOrNew', 'updateOrCreate',
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing',
        'insertReturning', 'insertOrIgnoreReturning',
        'fillAndInsert', 'fillAndInsertGetId', 'fillAndInsertOrIgnore',
        'upsert', 'updateOrInsert',
    ];

    /** Methods on a Vendor instance that return a new, unsaved Vendor. */
    private const COPYING_METHODS = ['replicate', 'replicateQuietly', 'newInstance'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->getClassReflection()?->getName() === CreateVendor::class) {
            return [];
        }

        if (! $this->createsVendor($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Only %s may create a Vendor (dependency-rules.md L8).',
                CreateVendor::class,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function createsVendor(Expr $node, Scope $scope): bool
    {
        if ($node instanceof New_) {
            return VendorTargets::isVendor($scope->getType($node));
        }

        // A nullsafe call is analysed as a MethodCall too, so it is matched once, here.
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();

            if (in_array($method, self::COPYING_METHODS, true)) {
                return VendorTargets::isVendor($scope->getType($node->var));
            }

            return in_array($method, self::CREATING_METHODS, true)
                && VendorTargets::receivesVendorWrites($node->var, $scope);
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier && $node->class instanceof Name) {
            $method = $node->name->toString();

            return ($method === 'factory' || in_array($method, self::CREATING_METHODS, true))
                && VendorTargets::isVendor(new ObjectType($scope->resolveName($node->class)));
        }

        return false;
    }
}
