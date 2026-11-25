<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Shared\Audit\AuditRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Rule L10 (dependency-rules.md §1; ADR-0023 Decision 8, "The recorder's
 * single insert is the only allowed write"): audit_log is append-only, and
 * AuditRecorder::record() is the only code that inserts into it. It reports,
 * anywhere in PHPStan's paths, App\Shared\Audit included:
 *
 * - a writing method called on an audit model, on `AuditModel::`, on an
 *   Eloquent builder or relation of an audit model, or on a query builder
 *   whose chain starts from the `audit_log` table (`table()`/`from()`, an
 *   `as` alias included) or from one of those (`toBase()`, `getQuery()`).
 *   Writing methods change or remove rows (MUTATING_METHODS: update, delete,
 *   truncate, upsert, updateOrInsert, updateOrCreate, increment, decrement,
 *   save, forceDelete, …) or insert them (INSERTING_METHODS: insert,
 *   insertGetId, insertOrIgnore, insertUsing, fillAndInsert, create,
 *   forceCreate, createMany, firstOrCreate, createOrFirst, saveMany, …);
 * - constant SQL passed to a connection's or the DB facade's statement(),
 *   unprepared(), affectingStatement(), update(), delete() or insert() that
 *   names audit_log and contains INSERT, UPDATE, DELETE, TRUNCATE or REPLACE
 *   outside string literals (`FOR UPDATE` excepted).
 *
 * The one exemption is exact: a plain `insert()` on a query builder over
 * audit_log, written directly in the body of AuditRecorder::record(), not in
 * a closure. Every other write there is reported, as is any insert in
 * another AuditRecorder method or in any other class.
 *
 * An audit model is an Eloquent model whose `$table` property defaults to
 * `audit_log`. None exists: the recorder writes through the query builder.
 * Constant writing SQL that names audit_log is reported even when it only
 * reads the table (`UPDATE vendors … (SELECT … FROM audit_log)`).
 *
 * It sees only what is statically known. Not reported: a second plain
 * insert() in the body of record() (the exemption is by place, not count);
 * a table name or SQL
 * held in a variable or built at run time; a query builder held in a
 * variable or property before the writing call, since its type does not
 * carry its table (an audit model's Eloquent builder does, and is reported);
 * a join onto audit_log from another table; a schema-qualified or quoted
 * table name (`nexo.audit_log`) in the builder; a model whose table is set at
 * run time (`setTable()`); container resolution (`app(...)`), reflection and
 * dynamic method names; DDL through the schema builder (`Schema::drop()`);
 * code outside PHPStan's paths (tests); and an ignore comment without an
 * identifier, as for L4.
 *
 * This is an application convention checked statically, not database
 * enforcement (OD-8): the application's database user can still change rows.
 * Revoking those privileges belongs with deployment (ADR-0021).
 *
 * @implements Rule<Expr>
 */
final class AuditLogMutationRule implements Rule
{
    public const IDENTIFIER = 'nexo.auditLogMutation';

    private const TABLE = 'audit_log';

    /** Builder, relation and model methods that change or remove existing rows. */
    private const MUTATING_METHODS = [
        'update', 'updateQuietly', 'updateOrFail', 'updateFrom', 'upsert', 'updateOrInsert', 'updateOrCreate',
        'increment', 'decrement', 'incrementEach', 'decrementEach', 'incrementQuietly', 'decrementQuietly', 'incrementOrCreate',
        'delete', 'deleteQuietly', 'deleteOrFail', 'forceDelete', 'forceDeleteQuietly', 'destroy', 'forceDestroy', 'truncate',
        'save', 'saveQuietly', 'saveOrFail', 'push', 'pushQuietly', 'touch', 'touchQuietly', 'restore', 'restoreQuietly',
    ];

    /**
     * Builder, relation and model methods that insert rows, as Laravel 13
     * names them. save(), saveQuietly(), saveOrFail() and incrementOrCreate(),
     * which may insert too, are in MUTATING_METHODS.
     */
    private const INSERTING_METHODS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing', 'insertOrIgnoreReturning',
        'fillAndInsert', 'fillAndInsertGetId', 'fillAndInsertOrIgnore',
        'create', 'createQuietly', 'createMany', 'createManyQuietly', 'createOrFirst', 'createOrRestore', 'firstOrCreate',
        'forceCreate', 'forceCreateQuietly', 'forceCreateMany', 'forceCreateManyQuietly',
        'saveMany', 'saveManyQuietly', 'saveOrIgnore',
    ];

    /** Connection methods that run the SQL they are given. */
    private const SQL_METHODS = ['statement', 'unprepared', 'affectingStatement', 'update', 'delete', 'insert'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->writesAuditLog($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'audit_log is append-only: only App\Shared\Audit\AuditRecorder::record() inserts into it, and nothing updates, deletes, truncates, upserts or replaces its rows (dependency-rules.md L10).',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function writesAuditLog(Expr $node, Scope $scope): bool
    {
        // A nullsafe call is analysed as a MethodCall too, so it is matched once, here.
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();

            if ($this->isWritingMethod($method) && $this->receivesAuditLogWrites($node->var, $scope)) {
                return ! $this->isRecorderInsert($node, $method, $scope);
            }

            return in_array($method, self::SQL_METHODS, true)
                && (new ObjectType(ConnectionInterface::class))->isSuperTypeOf($scope->getType($node->var))->yes()
                && $this->mutatingSql($node->getArgs()[0] ?? null, $scope);
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier && $node->class instanceof Name) {
            $method = $node->name->toString();
            $class = $scope->resolveName($node->class);

            if ($this->isWritingMethod($method) && $this->isAuditModel(new ObjectType($class))) {
                return true;
            }

            return in_array($method, self::SQL_METHODS, true)
                && in_array($class, [DB::class, 'DB'], true)
                && $this->mutatingSql($node->getArgs()[0] ?? null, $scope);
        }

        return false;
    }

    private function isWritingMethod(string $method): bool
    {
        return in_array($method, self::MUTATING_METHODS, true) || in_array($method, self::INSERTING_METHODS, true);
    }

    /**
     * The exemption: a plain insert() on a query builder, written directly in
     * the body of AuditRecorder::record(), not in a closure inside it.
     */
    private function isRecorderInsert(MethodCall $node, string $method, Scope $scope): bool
    {
        return $method === 'insert'
            && $scope->getClassReflection()?->getName() === AuditRecorder::class
            && $scope->getFunctionName() === 'record'
            && ! $scope->isInAnonymousFunction()
            && (new ObjectType(QueryBuilder::class))->isSuperTypeOf($scope->getType($node->var))->yes();
    }

    /** The receiver writes to audit_log: an audit model, its builder or relation, or a query on the table. */
    private function receivesAuditLogWrites(Expr $receiver, Scope $scope): bool
    {
        $expr = $receiver;

        while (true) {
            $type = $scope->getType($expr);

            if ($this->isAuditModel($type) || $this->isOfAuditModel($type, EloquentBuilder::class, 'TModel') || $this->isOfAuditModel($type, Relation::class, 'TRelatedModel')) {
                return true;
            }

            if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall)
                && $expr->name instanceof Identifier
                && in_array($expr->name->toString(), ['table', 'from'], true)
            ) {
                $table = $expr->getArgs()[0] ?? null;

                return $table !== null && $this->namesAuditLogTable($scope->getType($table->value));
            }

            if ($expr instanceof StaticCall) {
                return $expr->class instanceof Name && $this->isAuditModel(new ObjectType($scope->resolveName($expr->class)));
            }

            if (! $expr instanceof MethodCall && ! $expr instanceof NullsafeMethodCall) {
                return false;
            }

            $expr = $expr->var;
        }
    }

    /** An Eloquent model whose `$table` defaults to audit_log. */
    private function isAuditModel(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        if ($type->isNull()->yes() || ! (new ObjectType(Model::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        foreach ($type->getObjectClassReflections() as $class) {
            if (($class->getNativeReflection()->getDefaultProperties()['table'] ?? null) === self::TABLE) {
                return true;
            }
        }

        return false;
    }

    /**
     * An Eloquent builder or relation over an audit model.
     *
     * @param  class-string  $generic
     */
    private function isOfAuditModel(Type $type, string $generic, string $template): bool
    {
        return (new ObjectType($generic))->isSuperTypeOf($type)->yes()
            && $this->isAuditModel($type->getTemplateType($generic, $template));
    }

    /** `audit_log`, or `audit_log as alias`. */
    private function namesAuditLogTable(Type $type): bool
    {
        foreach ($type->getConstantStrings() as $string) {
            if (preg_match('/\A'.self::TABLE.'(?:\s+as\s+\w+)?\z/i', trim($string->getValue())) === 1) {
                return true;
            }
        }

        return false;
    }

    private function mutatingSql(?Arg $sql, Scope $scope): bool
    {
        if ($sql === null) {
            return false;
        }

        foreach ($scope->getType($sql->value)->getConstantStrings() as $string) {
            // Ignore keywords inside string literals, and a locking read's FOR UPDATE.
            $statement = preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '/\bfor\s+update\b/i'], ["''", ''], $string->getValue()) ?? '';

            if (preg_match('/(?<![\w$])`?'.self::TABLE.'`?(?![\w$])/i', $statement) === 1
                && preg_match('/\b(?:insert|update|delete|truncate|replace)\b/i', $statement) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
