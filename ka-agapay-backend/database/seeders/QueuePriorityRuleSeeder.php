<?php
//DATABASE/MIGRATIONS/SyncHeatmapCoordinatesSeeder
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QueuePriorityRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'rule_key'     => 'is_emergency',
                'label'        => 'Emergency Case',
                'score_weight' => 100,
                'description'  => 'Patient is in emergency condition',
                'is_active'    => true,
            ],
            [
                'rule_key'     => 'is_pregnant',
                'label'        => 'Pregnant Woman',
                'score_weight' => 40,
                'description'  => 'Patient is pregnant',
                'is_active'    => true,
            ],
            [
                'rule_key'     => 'is_senior',
                'label'        => 'Senior Citizen (60+)',
                'score_weight' => 35,
                'description'  => 'Patient is 60 years old or above',
                'is_active'    => true,
            ],
            [
                'rule_key'     => 'is_pwd',
                'label'        => 'Person with Disability',
                'score_weight' => 30,
                'description'  => 'Patient is a PWD',
                'is_active'    => true,
            ],
            [
                'rule_key'     => 'is_pediatric',
                'label'        => 'Pediatric (Under 5)',
                'score_weight' => 25,
                'description'  => 'Patient is a child under 5 years old',
                'is_active'    => true,
            ],
            [
                'rule_key'     => 'is_bhw_endorsed',
                'label'        => 'BHW Endorsed',
                'score_weight' => 10,
                'description'  => 'Patient was endorsed and verified by a BHW',
                'is_active'    => true,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('queue_priority_rules')->insertOrIgnore(array_merge($rule, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
