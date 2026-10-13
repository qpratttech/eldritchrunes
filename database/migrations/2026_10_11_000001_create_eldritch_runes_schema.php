<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_gm')->default(false);
            $table->string('avatar_path')->nullable();
        });

        Schema::create('ruleset_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->string('status')->default('active');
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ruleset_version_id')->nullable()->constrained('ruleset_versions')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('player');
            $table->timestamps();
            $table->unique(['campaign_id', 'user_id']);
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->foreignId('attribute_id')->constrained('attributes');
            $table->boolean('is_attack_skill')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('percentile_tiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('tier')->primary();
            $table->unsignedTinyInteger('success_percent')->nullable();
            $table->string('outcome_type');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->json('starting_json')->nullable();
            $table->json('increase_json')->nullable();
            $table->timestamps();
        });

        Schema::create('species', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('traits_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backgrounds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('grants_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('starting_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('progression_json')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('progression_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name');
            $table->string('category');
            $table->text('flavor_text')->nullable();
            $table->text('rules_text')->nullable();
            $table->string('activation_type')->default('passive');
            $table->json('effects_json')->nullable();
            $table->json('prerequisites_json')->nullable();
            $table->json('choices_schema_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['category', 'key']);
        });

        Schema::create('option_grants', function (Blueprint $table) {
            $table->id();
            $table->string('option_type');
            $table->unsignedBigInteger('option_id');
            $table->string('grant_type');
            $table->string('grant_key');
            $table->integer('amount')->default(1);
            $table->unsignedTinyInteger('level')->nullable();
            $table->json('selection_rules_json')->nullable();
            $table->timestamps();
            $table->index(['option_type', 'option_id']);
        });

        Schema::create('class_primary_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->unique(['class_id', 'skill_id']);
        });

        Schema::create('advancement_thresholds', function (Blueprint $table) {
            $table->unsignedTinyInteger('character_level')->primary();
            $table->unsignedInteger('cumulative_xp');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->default('gear');
            $table->text('description')->nullable();
            $table->integer('cost_silver')->default(0);
            $table->decimal('weight', 8, 2)->default(0);
            $table->boolean('stackable')->default(false);
            $table->json('effects_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('weapon_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedTinyInteger('hands_required')->default(1);
            $table->foreignId('attack_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->string('damage_type')->default('physical');
            $table->unsignedTinyInteger('base_damage')->default(1);
            $table->string('damage_mode')->default('normal');
            $table->unsignedSmallInteger('melee_reach_ft')->nullable();
            $table->unsignedSmallInteger('normal_range_ft')->nullable();
            $table->json('properties_json')->nullable();
        });

        Schema::create('armor_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('armor_category');
            $table->unsignedTinyInteger('base_save_percent')->default(0);
            $table->json('properties_json')->nullable();
        });

        Schema::create('techniques', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->default('weapon');
            $table->text('description')->nullable();
            $table->json('effects_json')->nullable();
            $table->timestamps();
        });

        Schema::create('weapon_type_techniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weapon_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technique_id')->constrained()->cascadeOnDelete();
            $table->unique(['weapon_type_id', 'technique_id']);
        });

        Schema::create('magic_orders', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('power_source')->nullable();
            $table->foreignId('casting_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('spells', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('order_id')->nullable()->constrained('magic_orders')->nullOnDelete();
            $table->unsignedTinyInteger('tier')->default(0);
            $table->text('flavor_text')->nullable();
            $table->text('rules_text')->nullable();
            $table->string('casting_time')->nullable();
            $table->string('range_type')->nullable();
            $table->unsignedSmallInteger('range_ft')->nullable();
            $table->json('targeting_json')->nullable();
            $table->json('duration_json')->nullable();
            $table->boolean('requires_focus')->default(false);
            $table->string('resolution_type')->nullable();
            $table->foreignId('save_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->json('base_effects_json')->nullable();
            $table->json('empowered_effects_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('condition_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('save_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->string('save_timing')->nullable();
            $table->string('stacking_rule')->nullable();
            $table->json('effects_json')->nullable();
            $table->json('successful_save_effects_json')->nullable();
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('gender')->nullable();
            $table->string('pronouns')->nullable();
            $table->string('height')->nullable();
            $table->string('weight')->nullable();
            $table->string('age')->nullable();
            $table->string('eyes')->nullable();
            $table->string('hair')->nullable();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->foreignId('background_id')->nullable()->constrained('backgrounds')->nullOnDelete();
            $table->foreignId('specialization_id')->nullable()->constrained('specializations')->nullOnDelete();
            $table->unsignedInteger('xp')->default(0);
            $table->string('portrait_path')->nullable();
            $table->string('token_path')->nullable();
            $table->text('biography')->nullable();
            $table->text('notes')->nullable();
            $table->json('derivation_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->integer('base_step')->default(6);
            $table->unique(['character_id', 'attribute_id']);
        });

        Schema::create('character_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->integer('base_rank')->default(0);
            $table->unique(['character_id', 'skill_id']);
        });

        Schema::create('character_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('resource_key');
            $table->integer('base_maximum')->default(0);
            $table->integer('current_value')->default(0);
            $table->unique(['character_id', 'resource_key']);
        });

        Schema::create('character_class_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->unsignedTinyInteger('class_level')->default(1);
            $table->unique(['character_id', 'class_id']);
        });

        Schema::create('character_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('choices_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_order_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('magic_orders')->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('restrictions_json')->nullable();
            $table->unique(['character_id', 'order_id']);
        });

        Schema::create('character_spells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_id')->constrained('spells')->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('restrictions_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_techniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weapon_type_id')->nullable()->constrained('weapon_types')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });

        Schema::create('character_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('equipped_slot')->nullable();
            $table->string('custom_name')->nullable();
            $table->json('state_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_wallets', function (Blueprint $table) {
            $table->foreignId('character_id')->primary()->constrained()->cascadeOnDelete();
            $table->integer('balance_silver')->default(0);
        });

        Schema::create('creature_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->default('npc');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('base_wounds')->default(3);
            $table->unsignedTinyInteger('armor_save_percent')->default(0);
            $table->unsignedSmallInteger('major_move_ft')->default(20);
            $table->unsignedSmallInteger('minor_move_ft')->default(10);
            $table->json('stats_json')->nullable();
            $table->json('traits_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('creature_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creature_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('action_cost')->default('major');
            $table->unsignedTinyInteger('attack_tier')->nullable();
            $table->unsignedSmallInteger('range_ft')->nullable();
            $table->string('resolution_type')->nullable();
            $table->json('effects_json')->nullable();
        });

        Schema::create('creature_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creature_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->integer('current_wounds');
            $table->json('overrides_json')->nullable();
            $table->timestamps();
        });

        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->unsignedInteger('round_number')->default(0);
            $table->unsignedBigInteger('active_combatant_id')->nullable();
            $table->string('world_time')->nullable();
            $table->timestamps();
        });

        Schema::create('combatants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creature_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('controller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('initiative_roll')->nullable();
            $table->integer('initiative_total')->nullable();
            $table->integer('initiative_tiebreak')->nullable();
            $table->string('team')->default('neutral');
            $table->json('state_json')->nullable();
            $table->timestamps();
        });

        Schema::create('combat_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combatant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->unsignedTinyInteger('major_remaining')->default(1);
            $table->unsignedTinyInteger('minor_remaining')->default(2);
            $table->unsignedTinyInteger('free_remaining')->default(1);
            $table->unsignedTinyInteger('attack_count')->default(0);
            $table->unsignedSmallInteger('movement_used_ft')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
        });

        Schema::create('reserved_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combatant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->unsignedTinyInteger('reserved_minor_actions')->default(1);
            $table->json('trigger_json')->nullable();
            $table->unsignedBigInteger('expires_on_turn_id')->nullable();
            $table->timestamps();
        });

        Schema::create('combat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('combatants')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('combatants')->nullOnDelete();
            $table->string('event_type');
            $table->json('payload_json')->nullable();
            $table->foreignId('reverses_event_id')->nullable()->constrained('combat_events')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('active_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_combatant_id')->nullable()->constrained('combatants')->nullOnDelete();
            $table->foreignId('target_combatant_id')->nullable()->constrained('combatants')->nullOnDelete();
            $table->foreignId('spell_id')->nullable()->constrained('spells')->nullOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('started_round')->nullable();
            $table->unsignedInteger('expires_round')->nullable();
            $table->unsignedBigInteger('expires_on_turn_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('requires_focus')->default(false);
            $table->json('effects_json')->nullable();
            $table->json('state_json')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('ability_key');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('used_at');
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('round_number')->nullable();
            $table->string('reset_type')->nullable();
        });

        Schema::create('combatant_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combatant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creature_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('condition_id')->constrained('condition_definitions')->cascadeOnDelete();
            $table->foreignId('source_combatant_id')->nullable()->constrained('combatants')->nullOnDelete();
            $table->foreignId('source_effect_id')->nullable()->constrained('active_effects')->nullOnDelete();
            $table->unsignedTinyInteger('severity')->default(1);
            $table->unsignedInteger('applied_round')->nullable();
            $table->unsignedInteger('expires_round')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('state_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_recovery_state', function (Blueprint $table) {
            $table->foreignId('character_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('awakening_due_at')->nullable();
            $table->timestamp('last_full_rest_at')->nullable();
            $table->timestamp('long_recovery_started_at')->nullable();
        });

        Schema::create('rest_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('rest_type');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('interrupted')->default(false);
            $table->json('benefits_json')->nullable();
            $table->timestamps();
        });

        Schema::create('character_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creature_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('summon_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('controller_combatant_id')->constrained('combatants')->cascadeOnDelete();
            $table->foreignId('creature_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_effect_id')->nullable()->constrained('active_effects')->nullOnDelete();
            $table->unsignedBigInteger('commanded_turn_id')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_key');
            $table->string('type')->default('token');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });

        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('map_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('grid_type')->default('hex');
            $table->unsignedSmallInteger('grid_size_px')->default(36);
            $table->unsignedSmallInteger('feet_per_cell')->default(5);
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::table('encounters', function (Blueprint $table) {
            $table->foreign('scene_id')->references('id')->on('scenes')->nullOnDelete();
        });

        Schema::create('scene_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combatant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedTinyInteger('size_cells')->default(1);
            $table->integer('rotation')->default(0);
            $table->string('visibility')->default('visible');
            $table->string('layer')->default('tokens');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('scene_walls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->json('geometry_json');
            $table->string('wall_type')->default('block');
            $table->string('door_state')->nullable();
            $table->timestamps();
        });

        Schema::create('scene_lights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('scene_tokens')->nullOnDelete();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedSmallInteger('bright_radius_ft')->default(20);
            $table->unsignedSmallInteger('dim_radius_ft')->default(20);
            $table->string('color')->default('#e7d7b8');
            $table->boolean('enabled')->default(true);
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_lights');
        Schema::dropIfExists('scene_walls');
        Schema::dropIfExists('scene_tokens');
        Schema::table('encounters', function (Blueprint $table) {
            $table->dropForeign(['scene_id']);
        });
        Schema::dropIfExists('scenes');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('summon_instances');
        Schema::dropIfExists('character_companions');
        Schema::dropIfExists('rest_events');
        Schema::dropIfExists('character_recovery_state');
        Schema::dropIfExists('combatant_conditions');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('active_effects');
        Schema::dropIfExists('combat_events');
        Schema::dropIfExists('reserved_responses');
        Schema::dropIfExists('combat_turns');
        Schema::dropIfExists('combatants');
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('creature_instances');
        Schema::dropIfExists('creature_actions');
        Schema::dropIfExists('creature_templates');
        Schema::dropIfExists('character_wallets');
        Schema::dropIfExists('character_inventory');
        Schema::dropIfExists('character_techniques');
        Schema::dropIfExists('character_spells');
        Schema::dropIfExists('character_order_access');
        Schema::dropIfExists('character_features');
        Schema::dropIfExists('character_class_levels');
        Schema::dropIfExists('character_resources');
        Schema::dropIfExists('character_skills');
        Schema::dropIfExists('character_attributes');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('condition_definitions');
        Schema::dropIfExists('spells');
        Schema::dropIfExists('magic_orders');
        Schema::dropIfExists('weapon_type_techniques');
        Schema::dropIfExists('techniques');
        Schema::dropIfExists('armor_types');
        Schema::dropIfExists('weapon_types');
        Schema::dropIfExists('items');
        Schema::dropIfExists('advancement_thresholds');
        Schema::dropIfExists('class_primary_skills');
        Schema::dropIfExists('option_grants');
        Schema::dropIfExists('features');
        Schema::dropIfExists('specializations');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('backgrounds');
        Schema::dropIfExists('species');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('percentile_tiers');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('campaign_members');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('ruleset_versions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_gm', 'avatar_path']);
        });
    }
};
