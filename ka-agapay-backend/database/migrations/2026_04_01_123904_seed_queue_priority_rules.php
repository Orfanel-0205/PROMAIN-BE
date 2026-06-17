<?php

//database/migrations/2026_04_01_123904_seed_queue_priority_rules.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            ['rule_key' => 'is_emergency',     'label' => 'Emergency Case',      'score_weight' => 100, 'description' => 'Patient is in emergency condition'],
            ['rule_key' => 'is_pregnant',      'label' => 'Pregnant Woman',       'score_weight' => 40,  'description' => 'Patient is pregnant'],
            ['rule_key' => 'is_senior',        'label' => 'Senior Citizen (60+)', 'score_weight' => 35,  'description' => 'Patient is 60 years old or above'],
            ['rule_key' => 'is_pwd',           'label' => 'Person with Disability','score_weight' => 30, 'description' => 'Patient is a PWD'],
            ['rule_key' => 'is_pediatric',     'label' => 'Pediatric (Under 5)',  'score_weight' => 25,  'description' => 'Patient is a child under 5 years old'],
            ['rule_key' => 'is_bhw_endorsed',  'label' => 'BHW Endorsed',         'score_weight' => 10,  'description' => 'Patient was endorsed and verified by a BHW'],
        ];

        foreach ($rules as $rule) {
            DB::table('queue_priority_rules')->insert(array_merge($rule, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('queue_priority_rules')->truncate();
    }
};