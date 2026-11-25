<?php

namespace Tests\Arch\Fixtures\L10;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Violates L10: audit_log rows inserted outside AuditRecorder::record(),
 * changed or removed. Analysed by AuditLogMutationRuleTest only, never
 * executed. Each reported line is marked.
 */
final class Mutations
{
    public function builders(): void
    {
        DB::table('audit_log')->update(['action' => 'vendor.approved']); // violation
        DB::table('audit_log')->where('id', 1)->delete(); // violation
        DB::table('audit_log')->truncate(); // violation
        DB::table('audit_log')->upsert([['id' => 1, 'action' => 'vendor.approved']], ['id'], ['action']); // violation
        DB::table('audit_log')->updateOrInsert(['id' => 1], ['action' => 'vendor.approved']); // violation
        DB::table('audit_log')->where('id', 1)->increment('subject_id'); // violation
        DB::table('audit_log')->where('id', 1)->decrement('subject_id', 2); // violation
        DB::table('audit_log')->incrementEach(['subject_id' => 1]); // violation
        DB::table('audit_log as a')->where('a.id', 1)->delete(); // violation
        DB::connection()->table('audit_log')->delete(1); // violation
        DB::connection('mysql')->table('audit_log')->orderBy('id')->limit(1)->update(['action' => 'vendor.approved']); // violation
        DB::query()->from('audit_log')->where('id', 1)->delete(); // violation
    }

    public function models(AuditEntry $entry, ?AuditEntry $maybe, AuditedSubject $subject): void
    {
        $entry->save(); // violation
        $entry->update(['action' => 'vendor.approved']); // violation
        $entry->delete(); // violation
        $entry->forceDelete(); // violation
        $entry->increment('subject_id'); // violation
        $entry->decrement('subject_id'); // violation
        $entry->push(); // violation
        $entry->touch(); // violation
        $maybe?->delete(); // violation

        AuditEntry::destroy(1); // violation
        AuditEntry::truncate(); // violation
        AuditEntry::upsert([['id' => 1, 'action' => 'vendor.approved']], ['id']); // violation
        AuditEntry::updateOrCreate(['id' => 1], ['action' => 'vendor.approved']); // violation
        AuditEntry::query()->update(['action' => 'vendor.approved']); // violation
        AuditEntry::where('id', 1)->delete(); // violation
        AuditEntry::query()->toBase()->delete(); // violation
        AuditEntry::query()->getQuery()->truncate(); // violation

        // An audit model's Eloquent builder carries its model in its type.
        $builder = AuditEntry::query()->where('id', 1);
        $builder->forceDelete(); // violation

        $subject->auditEntries()->delete(); // violation
        $subject->auditEntries()->update(['action' => 'vendor.approved']); // violation
    }

    public function insertions(AuditEntry $entry, AuditedSubject $subject): void
    {
        DB::table('audit_log')->insert(['action' => 'vendor.created']); // violation
        DB::table('audit_log')->insertGetId(['action' => 'vendor.created']); // violation
        DB::table('audit_log')->insertOrIgnore(['action' => 'vendor.created']); // violation
        DB::table('audit_log')->insertUsing(['action'], DB::table('vendors')->select('name')); // violation
        DB::table('audit_log')->insertOrIgnoreUsing(['action'], DB::table('vendors')->select('name')); // violation
        DB::connection()->table('audit_log as a')->insert(['action' => 'vendor.created']); // violation

        AuditEntry::create(['action' => 'vendor.created']); // violation
        AuditEntry::forceCreate(['action' => 'vendor.created']); // violation
        AuditEntry::insert(['action' => 'vendor.created']); // violation
        AuditEntry::query()->firstOrCreate(['action' => 'vendor.created']); // violation
        AuditEntry::query()->createOrFirst(['action' => 'vendor.created']); // violation
        AuditEntry::query()->createMany([['action' => 'vendor.created']]); // violation
        AuditEntry::query()->fillAndInsert([['action' => 'vendor.created']]); // violation

        $subject->auditEntries()->create(['action' => 'vendor.created']); // violation
        $subject->auditEntries()->save($entry); // violation
        $subject->auditEntries()->saveMany([$entry]); // violation

        DB::insert("INSERT INTO audit_log (action) VALUES ('vendor.created')"); // violation
        DB::statement('INSERT INTO `audit_log` (action) SELECT name FROM vendors'); // violation
        DB::connection()->insert('insert into audit_log (action) values (?)', ['vendor.created']); // violation
    }

    public function sql(Connection $connection): void
    {
        DB::statement('UPDATE audit_log SET action = ? WHERE id = ?', ['vendor.approved', 1]); // violation
        DB::update('update `audit_log` set action = ?', ['vendor.approved']); // violation
        DB::delete('DELETE FROM audit_log WHERE id = 1'); // violation
        DB::unprepared('TRUNCATE TABLE audit_log'); // violation
        DB::statement("REPLACE INTO audit_log (id, action) VALUES (1, 'vendor.approved')"); // violation
        DB::insert("INSERT INTO audit_log (id) VALUES (1) ON DUPLICATE KEY UPDATE action = 'vendor.approved'"); // violation
        DB::affectingStatement('delete a from audit_log a where a.id = 1'); // violation
        DB::connection()->statement('DELETE FROM nexo_testing.audit_log'); // violation
        $connection->unprepared('TRUNCATE audit_log'); // violation
        $connection->update("UPDATE vendors SET name = 'x' WHERE id IN (SELECT subject_id FROM audit_log)"); // violation

        // A constant held in a variable is still a constant.
        $sql = 'DELETE FROM audit_log';
        DB::statement($sql); // violation
    }
}
