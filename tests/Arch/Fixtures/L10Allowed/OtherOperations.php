<?php

namespace Tests\Arch\Fixtures\L10Allowed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Arch\Fixtures\L10\AuditEntry;

/**
 * Not reported by L10. The first method reads audit_log or writes other
 * tables; the recorder's own insert is proven allowed on the real file. The
 * second holds the rule's known blind spots, which it cannot see statically
 * (documented in AuditLogMutationRule). Analysed only, never executed.
 */
final class OtherOperations
{
    public function allowed(AuditEntry $entry, OtherModel $other): void
    {
        DB::table('audit_log')->where('subject_id', 1)->get();
        DB::table('audit_log')->where('id', 1)->lockForUpdate()->first();
        DB::table('audit_log')->count();
        AuditEntry::query()->where('id', 1)->first();
        $entry->refresh();

        DB::table('vendors')->where('id', 1)->update(['name' => 'Acme']);
        DB::table('vendors')->delete(1);
        DB::table('audit_logs')->delete();
        DB::table('users')->truncate();
        DB::table('vendors')->insert(['slug' => 'acme', 'name' => 'Acme']);
        DB::table('audit_logs')->insertGetId(['action' => 'vendor.created']);
        OtherModel::create(['name' => 'Acme']);
        $other->save();
        $other->delete();
        OtherModel::destroy(1);

        DB::statement("SELECT id FROM audit_log WHERE action = 'vendor.deleted' OR action = 'delete'");
        DB::insert("INSERT INTO vendors (slug, name) VALUES ('audit-log', 'audit_log')");
        DB::insert('INSERT INTO audit_log_archive (action) VALUES (?)', ['vendor.created']);
        DB::statement('SELECT id FROM audit_log WHERE id = 1 FOR UPDATE');
        DB::statement('DELETE FROM audit_log_archive');
        DB::update('UPDATE vendors SET name = ? WHERE id = ?', ['Acme', 1]);
        DB::select('SELECT * FROM audit_log WHERE id = ?', [1]);
    }

    public function blindSpots(string $table, string $sql, Builder $query, string $method): void
    {
        DB::table($table)->delete();
        DB::statement($sql);
        DB::statement('DELETE FROM '.$table);
        $query->delete();

        $builder = DB::table('audit_log');
        $builder->delete();
        $builder->insert(['action' => 'vendor.created']);
        DB::insert($sql);

        DB::table('vendors')->join('audit_log', 'audit_log.subject_id', '=', 'vendors.id')->delete();
        DB::table('nexo_testing.audit_log')->delete();
        DB::table('audit_log')->{$method}();

        $model = new OtherModel;
        $model->setTable('audit_log');
        $model->delete();

        app('audit.entry')->delete();

        Schema::drop('audit_log');
    }
}

final class OtherModel extends Model {}
