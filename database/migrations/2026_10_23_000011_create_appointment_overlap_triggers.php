<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('
            CREATE TRIGGER trg_appt_no_overlap_ins
            BEFORE INSERT ON appointments
            FOR EACH ROW
            BEGIN
                IF NEW.status IN (\'ACCEPTED\', \'CONFIRMED\') AND EXISTS (
                    SELECT 1 FROM appointments a
                    WHERE a.provider_id = NEW.provider_id
                      AND a.status IN (\'ACCEPTED\', \'CONFIRMED\')
                      AND a.start_at < NEW.end_at
                      AND a.end_at > NEW.start_at
                ) THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'Overlapping appointment for this provider\';
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_appt_no_overlap_upd
            BEFORE UPDATE ON appointments
            FOR EACH ROW
            BEGIN
                IF NEW.status IN (\'ACCEPTED\', \'CONFIRMED\') AND EXISTS (
                    SELECT 1 FROM appointments a
                    WHERE a.provider_id = NEW.provider_id
                      AND a.id <> NEW.id
                      AND a.status IN (\'ACCEPTED\', \'CONFIRMED\')
                      AND a.start_at < NEW.end_at
                      AND a.end_at > NEW.start_at
                ) THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'Overlapping appointment for this provider\';
                END IF;
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_appt_no_overlap_upd');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_appt_no_overlap_ins');
    }
};
