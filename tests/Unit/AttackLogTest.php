<?php

namespace Tests\Unit;

use App\Domain\Combat\AttackLog;
use PHPUnit\Framework\TestCase;

class AttackLogTest extends TestCase
{
    public function test_a_hit_reads_as_an_attack_sentence(): void
    {
        $line = AttackLog::sentence([
            'attacker' => 'Skeleton',
            'target' => 'Zugo',
            'attack' => 'Rusted Sword',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 1,
        ]);

        $this->assertSame(
            'Skeleton attacked Zugo with Rusted Sword. (47% chance to hit.) Rolled 18. Hit! Zugo took 1 damage.',
            $line,
        );
    }

    public function test_suffering_is_noted_when_it_changes_the_attack(): void
    {
        $line = AttackLog::sentence([
            'attacker' => 'Zugo',
            'target' => 'Skeleton',
            'attack' => 'Sword',
            'percent' => 41,
            'roll' => 20,
            'success' => true,
            'damage' => 1,
            'attacker_suffering' => 1,
        ]);

        $this->assertSame(
            'Zugo attacked Skeleton with Sword. (41% chance to hit. Suffering 1.) Rolled 20. Hit! Skeleton took 1 damage.',
            $line,
        );
    }

    public function test_a_failed_endurance_check_is_written_after_the_damage(): void
    {
        $line = AttackLog::sentence([
            'attacker' => 'Skeleton',
            'target' => 'Zugo',
            'attack' => 'Rusted Sword',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 1,
            'endurance' => ['percent' => 41, 'roll' => 55, 'success' => false],
            'suffering' => 1,
        ]);

        $this->assertSame(
            'Skeleton attacked Zugo with Rusted Sword. (47% chance to hit.) Rolled 18. Hit! Zugo took 1 damage. Zugo makes an Endurance check. (41% chance.) Rolled 55. Failure. Suffering 1.',
            $line,
        );
    }

    public function test_an_attack_names_the_actions_it_spends(): void
    {
        $minor = AttackLog::sentence([
            'attacker' => 'Skeleton',
            'target' => 'Zugo',
            'attack' => 'Rusted Sword',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 1,
            'actions' => ['minor'],
        ]);
        $heavy = AttackLog::sentence([
            'attacker' => 'Bandit Mauler',
            'target' => 'Zugo',
            'attack' => 'Heavy Blow',
            'percent' => 65,
            'roll' => 12,
            'success' => true,
            'damage' => 2,
            'actions' => ['major', 'minor'],
        ]);

        $this->assertSame(
            'Skeleton attacked Zugo with Rusted Sword. (47% chance to hit.) Rolled 18. Hit! Zugo took 1 damage.',
            $minor,
        );
        $this->assertSame(
            'Bandit Mauler attacked Zugo with Heavy Blow. (65% chance to hit.) Rolled 12. Hit! Zugo took 2 damage.',
            $heavy,
        );
    }

    public function test_defeat_is_written_when_the_target_drops_to_zero_wounds(): void
    {
        $line = AttackLog::sentence([
            'attacker' => 'Skeleton',
            'target' => 'Zugo',
            'attack' => 'Rusted Sword',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 1,
            'actions' => ['major'],
            'defeated' => 'Zugo',
        ]);

        $this->assertSame(
            'Skeleton attacked Zugo with Rusted Sword. (47% chance to hit.) Rolled 18. Hit! Zugo took 1 damage. Zugo has been defeated.',
            $line,
        );
    }

    public function test_an_armor_save_is_written_between_the_hit_and_the_wounds(): void
    {
        $held = AttackLog::sentence([
            'attacker' => 'Zugo',
            'target' => 'Brute',
            'attack' => 'Axe',
            'percent' => 53,
            'roll' => 12,
            'success' => true,
            'damage' => 0,
            'armor_save' => 10,
            'armor_roll' => 4,
            'armor_saved' => true,
            'armor_capped' => true,
        ]);
        $failed = AttackLog::sentence([
            'attacker' => 'Zugo',
            'target' => 'Brute',
            'attack' => 'Sword',
            'percent' => 53,
            'roll' => 12,
            'success' => true,
            'damage' => 1,
            'armor_save' => 30,
            'armor_roll' => 40,
            'armor_saved' => false,
            'rune_armor' => true,
        ]);

        $this->assertSame(
            'Zugo attacked Brute with Axe. (53% chance to hit.) Rolled 12. Hit! Armor Piercing caps the Armor Save at 10%. Brute makes an Armor Save. (10% chance.) Rolled 4. The armor holds and prevents 2 wounds. Brute took 0 damage.',
            $held,
        );
        $this->assertSame(
            'Zugo attacked Brute with Sword. (53% chance to hit.) Rolled 12. Hit! Rune Armor prevents Armor Piercing. Brute makes an Armor Save. (30% chance.) Rolled 40. The armor fails. Brute took 1 damage.',
            $failed,
        );
    }

    public function test_an_unarmed_lesser_attack_shows_the_chance_to_wound(): void
    {
        $wounds = AttackLog::sentence([
            'attacker' => 'Sera',
            'target' => 'Brute',
            'attack' => 'Unarmed',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 1,
            'lesser' => true,
            'lesser_roll' => 22,
        ]);
        $none = AttackLog::sentence([
            'attacker' => 'Sera',
            'target' => 'Brute',
            'attack' => 'Unarmed',
            'percent' => 47,
            'roll' => 18,
            'success' => true,
            'damage' => 0,
            'lesser' => true,
            'lesser_roll' => 40,
        ]);

        $this->assertSame(
            'Sera attacked Brute with Unarmed. (47% chance to hit.) Rolled 18. Hit! Lesser Attack. (33% chance to wound. Rolls 01–33.) Rolled 22. The attack wounds. Brute took 1 damage.',
            $wounds,
        );
        $this->assertSame(
            'Sera attacked Brute with Unarmed. (47% chance to hit.) Rolled 18. Hit! Lesser Attack. (33% chance to wound. Rolls 01–33.) Rolled 40. The attack deals no wounds. Brute took 0 damage.',
            $none,
        );
    }

    public function test_a_miss_stops_before_damage(): void
    {
        $line = AttackLog::sentence([
            'attacker' => 'Skeleton',
            'target' => 'Zugo',
            'attack' => 'Rusted Sword',
            'percent' => 47,
            'roll' => 82,
            'success' => false,
            'damage' => null,
        ]);

        $this->assertSame(
            'Skeleton attacked Zugo with Rusted Sword. (47% chance to hit.) Rolled 82. Miss!',
            $line,
        );
    }
}
