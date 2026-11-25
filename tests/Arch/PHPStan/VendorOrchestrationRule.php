<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Application\ReactivateVendor;
use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Application\SuspendVendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Rule L9 (dependency-rules.md §1; ADR-0023 Decision 7): the audited Vendor
 * operations are reached only through their orchestrators. Each protected
 * operation has exactly one allowed caller, in that caller's handle()
 * (closures in it included):
 *
 * - CreateVendor::handle() only from RegisterVendor;
 * - VendorLifecycle::approve() only from ApproveVendor;
 * - VendorLifecycle::suspend() only from SuspendVendor;
 * - VendorLifecycle::reactivate() only from ReactivateVendor.
 *
 * It reports, anywhere else, a call of a protected operation on a receiver
 * typed as CreateVendor or VendorLifecycle, however the instance was obtained
 * (injected, `new`, `app(CreateVendor::class)`, nullsafe), and an array
 * callable naming one with a constant method name (`[$lifecycle, 'approve']`).
 * An orchestrator calling another operation, or calling its own from another
 * method, is reported too.
 *
 * Together with L4 (only VendorLifecycle writes a status) and L8 (only
 * CreateVendor creates a Vendor), every statically visible status write and
 * creation therefore passes through an audited orchestrator.
 *
 * It sees only what is statically known, and shares the blind spots of L4
 * and L8. Not reported: a receiver of unknown type (`mixed`, a string
 * container id such as `app('vendors.lifecycle')`), a first-class callable
 * (`$lifecycle->approve(...)`, which PHPStan analyses as an internal node
 * type rules are not meant to match; L4, L8 and L10 do not see it either), a
 * dynamic method name or `call_user_func()` with one, reflection, raw SQL
 * that writes `vendors`
 * (L4's and L8's blind spot), code outside PHPStan's paths (tests), and an
 * ignore comment without an identifier.
 *
 * @implements Rule<Expr>
 */
final class VendorOrchestrationRule implements Rule
{
    public const IDENTIFIER = 'nexo.vendorOrchestration';

    /**
     * Each protected operation and its one allowed caller.
     *
     * @var array<class-string, array<string, class-string>>
     */
    private const ALLOWED_CALLERS = [
        CreateVendor::class => ['handle' => RegisterVendor::class],
        VendorLifecycle::class => [
            'approve' => ApproveVendor::class,
            'suspend' => SuspendVendor::class,
            'reactivate' => ReactivateVendor::class,
        ],
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
        $called = $this->protectedOperation($node, $scope);

        if ($called === null) {
            return [];
        }

        [$target, $method] = $called;
        $caller = self::ALLOWED_CALLERS[$target][$method];

        if ($scope->getClassReflection()?->getName() === $caller && $scope->getFunctionName() === 'handle') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Only %s::handle() may call %s::%s(): the operation is audited only there (dependency-rules.md L9).',
                $caller,
                $target,
                $method,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    /**
     * The protected operation $node calls or names, if any.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function protectedOperation(Expr $node, Scope $scope): ?array
    {
        // A nullsafe call is analysed as a MethodCall too, so it is matched once, here.
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            return $this->operationOn($scope->getType($node->var), $node->name->toString());
        }

        // [$receiver, 'method'], a callable array.
        if ($node instanceof Array_ && count($node->items) === 2) {
            foreach ($scope->getType($node->items[1]->value)->getConstantStrings() as $method) {
                $operation = $this->operationOn($scope->getType($node->items[0]->value), $method->getValue());

                if ($operation !== null) {
                    return $operation;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: class-string, 1: string}|null
     */
    private function operationOn(Type $receiver, string $method): ?array
    {
        $receiver = TypeCombinator::removeNull($receiver);

        foreach (self::ALLOWED_CALLERS as $target => $operations) {
            if (isset($operations[$method]) && ! $receiver->isNull()->yes() && (new ObjectType($target))->isSuperTypeOf($receiver)->yes()) {
                return [$target, $method];
            }
        }

        return null;
    }
}
