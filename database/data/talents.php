<?php

return array (
  0 => 
  array (
    'key' => 'moonsight',
    'name' => 'Moonsight',
    'category' => 'talent',
    'flavor_text' => 'You have learned to trust the faintest light.',
    'rules_text' => '**Requirements:** None.

Gain Nightvision. If you already have it, choose a different eligible talent.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  1 => 
  array (
    'key' => 'foretold',
    'name' => 'Foretold',
    'category' => 'talent',
    'flavor_text' => 'A promised future seems reluctant to release you.',
    'rules_text' => '**Requirements:** None.

Increase maximum Luck by 2.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  2 => 
  array (
    'key' => 'sorcerous-potency',
    'name' => 'Sorcerous Potency',
    'category' => 'talent',
    'flavor_text' => 'You gather more power before your strength is spent.',
    'rules_text' => '**Requirements:** None.

Increase maximum Sorcerous Power by 1.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  3 => 
  array (
    'key' => 'otherworldly-spirit',
    'name' => 'Otherworldly Spirit',
    'category' => 'talent',
    'flavor_text' => 'Your spirit can hold another fragment of magical knowledge.',
    'rules_text' => '**Requirements:** None.

Increase Sorcerous Soul by 1. This grants capacity, not an automatically learned spell.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  4 => 
  array (
    'key' => 'combat-training',
    'name' => 'Combat Training',
    'category' => 'talent',
    'flavor_text' => 'Practice turns a familiar weapon into a dependable tool.',
    'rules_text' => '**Requirements:** None.

Gain +1 rank in Melee or Ranged.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  5 => 
  array (
    'key' => 'veteran',
    'name' => 'Veteran',
    'category' => 'talent',
    'flavor_text' => 'You have learned to endure the demands of battle.',
    'rules_text' => '**Requirements:** Combat Training.

Increase maximum Wounds and Stamina by 1 each.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Combat Training',
    ),
    'effects' => NULL,
  ),
  6 => 
  array (
    'key' => 'scarred',
    'name' => 'Scarred',
    'category' => 'talent',
    'flavor_text' => 'Old injuries have left you harder to break.',
    'rules_text' => '**Requirements:** Veteran.

Increase maximum Wounds by 1 and gain +1 rank in Willpower.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Veteran',
    ),
    'effects' => NULL,
  ),
  7 => 
  array (
    'key' => 'vigor',
    'name' => 'Vigor',
    'category' => 'talent',
    'flavor_text' => 'A deep reserve of effort carries you through hardship.',
    'rules_text' => '**Requirements:** None.

Increase maximum Stamina by 2.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  8 => 
  array (
    'key' => 'relentless',
    'name' => 'Relentless',
    'category' => 'talent',
    'flavor_text' => 'You keep your purpose when exhaustion threatens it.',
    'rules_text' => '**Requirements:** Vigor.

Increase maximum Stamina by 1 and gain +1 rank in Willpower.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Vigor',
    ),
    'effects' => NULL,
  ),
  9 => 
  array (
    'key' => 'stoneskull',
    'name' => 'Stoneskull',
    'category' => 'talent',
    'flavor_text' => 'A rattling blow rarely scatters your thoughts for long.',
    'rules_text' => '**Requirements:** None.

You are immune to Dazed. Staggered still affects you, but ends when a successful save would reduce it to Dazed.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  10 => 
  array (
    'key' => 'deadly',
    'name' => 'Deadly',
    'category' => 'talent',
    'flavor_text' => 'You recognize the difference between a hit and a telling hit.',
    'rules_text' => '**Requirements:** None.

Increase your direct-attack critical range by 3 percentage points.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  11 => 
  array (
    'key' => 'flanker',
    'name' => 'Flanker',
    'category' => 'talent',
    'flavor_text' => 'You exploit an enemy’s divided attention.',
    'rules_text' => '**Requirements:** Prowler.

Your Melee attacks are Enhanced when you and a conscious ally threaten the target from opposite sides. In play without a grid, the GM must confirm the target cannot keep both attackers in front.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Prowler',
    ),
    'effects' => NULL,
  ),
  12 => 
  array (
    'key' => 'prowler',
    'name' => 'Prowler',
    'category' => 'talent',
    'flavor_text' => 'You move carefully and react before danger closes.',
    'rules_text' => '**Requirements:** None.

Gain +1 rank in Reflexes and Stealth.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  13 => 
  array (
    'key' => 'arms-mastery',
    'name' => 'Arms Mastery',
    'category' => 'talent',
    'flavor_text' => 'You study the subtleties of a chosen weapon.',
    'rules_text' => '**Requirements:** Combat Training.

Learn one Weapon Technique for one weapon type.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Combat Training',
    ),
    'effects' => NULL,
  ),
  14 => 
  array (
    'key' => 'point-blank-attack',
    'name' => 'Point Blank Attack',
    'category' => 'talent',
    'flavor_text' => 'Close pressure does not spoil your shot.',
    'rules_text' => '**Requirements:** Arms Mastery for that weapon.

Ignore Diminishment for ranged attacks at melee range with your mastered weapon.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Arms Mastery for that weapon',
    ),
    'effects' => NULL,
  ),
  15 => 
  array (
    'key' => 'accuracy',
    'name' => 'Accuracy',
    'category' => 'talent',
    'flavor_text' => 'You wait for a clean line through a crowded fight.',
    'rules_text' => '**Requirements:** Arms Mastery for that weapon.

With your mastered weapon, ignore Diminishment for firing into melee and the chance of hitting an unintended target.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Arms Mastery for that weapon',
    ),
    'effects' => NULL,
  ),
  16 => 
  array (
    'key' => 'careful-aim',
    'name' => 'Careful Aim',
    'category' => 'talent',
    'flavor_text' => 'You find the opening in a target’s protection.',
    'rules_text' => '**Requirements:** Arms Mastery for that weapon.

With your mastered weapon, ignore normal cover; treat heavy cover as normal cover. Total cover still prevents targeting.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Arms Mastery for that weapon',
    ),
    'effects' => NULL,
  ),
  17 => 
  array (
    'key' => 'secondary-attack',
    'name' => 'Secondary Attack',
    'category' => 'talent',
    'flavor_text' => 'Repeated practice lets you recover your weapon for another strike.',
    'rules_text' => '**Requirements:** Combat Training, Arms Mastery for that weapon, character level 3.

You may make one additional attack with your mastered weapon on your turn, paying its normal minor action and multiple-attack penalty.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Combat Training',
      1 => 'Arms Mastery for that weapon',
      2 => 'character level 3',
    ),
    'effects' => NULL,
  ),
  18 => 
  array (
    'key' => 'ternary-attack',
    'name' => 'Ternary Attack',
    'category' => 'talent',
    'flavor_text' => 'Your attack sequence extends into a relentless combination.',
    'rules_text' => '**Requirements:** Combat Training, Arms Mastery for that weapon, character level 7.

You may make one additional attack with your mastered weapon on your turn, paying its normal minor action and multiple-attack penalty. This can combine with Secondary Attack, but grants no extra actions.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Combat Training',
      1 => 'Arms Mastery for that weapon',
      2 => 'character level 7',
    ),
    'effects' => NULL,
  ),
  19 => 
  array (
    'key' => 'spell-mastery',
    'name' => 'Spell Mastery',
    'category' => 'talent',
    'flavor_text' => 'You shape a familiar working with greater control.',
    'rules_text' => '**Requirements:** Sorcerous Potency.

Learn one Spell Technique.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Sorcerous Potency',
    ),
    'effects' => NULL,
  ),
  20 => 
  array (
    'key' => 'sorcerous-conversion',
    'name' => 'Sorcerous Conversion',
    'category' => 'talent',
    'flavor_text' => 'You turn physical effort into magical force.',
    'rules_text' => '**Requirements:** None.

When paying Sorcerous Power for a spell or feature, you may spend Stamina instead, one for one. This does not increase your Power maximum or count as restoring Power.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  21 => 
  array (
    'key' => 'elemental-conversion',
    'name' => 'Elemental Conversion',
    'category' => 'talent',
    'flavor_text' => 'You understand the kinship between flame, frost, and storm.',
    'rules_text' => '**Requirements:** None.

When casting a spell that deals Cold, Fire, or Storm damage, you may change that damage to either of the other two types. This changes damage only, not secondary conditions such as Burning.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  22 => 
  array (
    'key' => 'spellblade',
    'name' => 'Spellblade',
    'category' => 'talent',
    'flavor_text' => 'Magic flashes through your weapon at the instant of commitment.',
    'rules_text' => '**Requirements:** None.

Before a melee attack, spend 1 Sorcerous Power without an extra action. The attack deals +1 wound and its entire damage becomes Enchanted. This is spent even if you miss and can be used once per attack.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  23 => 
  array (
    'key' => 'brawler',
    'name' => 'Brawler',
    'category' => 'talent',
    'flavor_text' => 'You know how to make an unarmed blow count.',
    'rules_text' => '**Requirements:** None.

Your unarmed attacks deal normal damage rather than Lesser Attack damage. The penalty for attacking an armed target remains.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  24 => 
  array (
    'key' => 'martial-arts',
    'name' => 'Martial Arts',
    'category' => 'talent',
    'flavor_text' => 'Your strikes flow into a practiced combination.',
    'rules_text' => '**Requirements:** Brawler.

You may make one additional unarmed attack on your turn, paying the normal minor action and multiple-attack penalty.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Brawler',
    ),
    'effects' => NULL,
  ),
  25 => 
  array (
    'key' => 'elemental-fist',
    'name' => 'Elemental Fist',
    'category' => 'talent',
    'flavor_text' => 'Your blows carry the bite of winter, fire, or thunder.',
    'rules_text' => '**Requirements:** Martial Arts and Elemental Conversion.

For each unarmed attack, choose Physical, Cold, Fire, or Storm damage.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Martial Arts and Elemental Conversion',
    ),
    'effects' => NULL,
  ),
  26 => 
  array (
    'key' => 'gore',
    'name' => 'Gore',
    'category' => 'talent',
    'flavor_text' => 'You know the lethal angles of your natural weapons.',
    'rules_text' => '**Requirements:** Bite/Claws or Horns.

Increase your critical range by 5 percentage points when attacking with Bite/Claws or Horns.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
      0 => 'Bite/Claws or Horns',
    ),
    'effects' => NULL,
  ),
  27 => 
  array (
    'key' => 'elder-knowledge',
    'name' => 'Elder Knowledge',
    'category' => 'talent',
    'flavor_text' => 'Study or revelation opens a new magical tradition to you.',
    'rules_text' => '**Requirements:** None.

Gain access to one order of magic. This does not grant spells, Soul capacity, or additional Potential.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  28 => 
  array (
    'key' => 'wild-vigor',
    'name' => 'Wild Vigor',
    'category' => 'talent',
    'flavor_text' => 'You can summon a fierce reserve when the fighting begins.',
    'rules_text' => '**Requirements:** None.

Increase maximum Stamina by 1. While you have at least one level of Suffering, your Endurance checks are Enhanced. This talent qualifies you for Frenzy and War Howl.',
    'activation_type' => 'passive',
    'prerequisites' => 
    array (
    ),
    'effects' => NULL,
  ),
  29 => 
  array (
    'key' => 'armor-breaker',
    'name' => 'Armor Breaker',
    'category' => 'tactic',
    'flavor_text' => 'You prepare a blow aimed at a defensive weakness.',
    'rules_text' => '**Requirements:** Deadly.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** Before a weapon attack on your turn.

Before your next weapon attack this turn, activate this tactic. That attack gains Armor Piercing.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Deadly',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'Before a weapon attack on your turn.',
    ),
  ),
  30 => 
  array (
    'key' => 'brace',
    'name' => 'Brace',
    'category' => 'tactic',
    'flavor_text' => 'You set your shield to receive the worst of an incoming blow.',
    'rules_text' => '**Requirements:** Veteran and a wielded shield.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

While wielding a shield, gain a separate 10% Brace Save against damaging hits until the start of your next turn. Roll after Armor; success reduces damage by 2 wounds.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Veteran and a wielded shield',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  31 => 
  array (
    'key' => 'charge',
    'name' => 'Charge',
    'category' => 'tactic',
    'flavor_text' => 'You close the distance in a sudden rush.',
    'rules_text' => '**Requirements:** None.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

Move up to 25 feet. Apply Slowed and difficult terrain normally. This movement does not include an attack.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  32 => 
  array (
    'key' => 'cleave',
    'name' => 'Cleave',
    'category' => 'tactic',
    'flavor_text' => 'Your finishing strike carries into the enemy beside your fallen foe.',
    'rules_text' => '**Requirements:** Character level 3.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** When your melee attack reduces a foe from positive Wounds to 0.

When your melee attack reduces a foe from positive Wounds to 0, choose a different foe within your reach and adjacent to that foe. It makes a Reflexes save or takes damage equal to the original attack’s damage before the first foe’s defenses, of the same type. Resolve its defenses normally. This secondary damage cannot trigger another Cleave.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Character level 3',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'When your melee attack reduces a foe from positive Wounds to 0.',
    ),
  ),
  33 => 
  array (
    'key' => 'counter-attack',
    'name' => 'Counter Attack',
    'category' => 'tactic',
    'flavor_text' => 'You answer an enemy’s commitment with a strike of your own.',
    'rules_text' => '**Requirements:** Combat Training.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** Response; after an enemy within melee reach resolves an attack against you.

After an enemy within your melee reach resolves an attack against you, make one melee attack against it. You must remain able to act.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Combat Training',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'Response; after an enemy within melee reach resolves an attack against you.',
    ),
  ),
  34 => 
  array (
    'key' => 'leadership',
    'name' => 'Leadership',
    'category' => 'tactic',
    'flavor_text' => 'A firm command gives courage a place to return.',
    'rules_text' => '**Requirements:** Veteran, character level 5.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

End Fear on yourself or one ally within 30 feet who can hear you. This does not end Horror.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Veteran',
      1 => 'character level 5',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  35 => 
  array (
    'key' => 'disrupting-blow',
    'name' => 'Disrupting Blow',
    'category' => 'tactic',
    'flavor_text' => 'You strike as an enemy gathers the breath and will for magic.',
    'rules_text' => '**Requirements:** Veteran.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** Response; when an enemy within melee reach begins casting a spell.

When an enemy within melee reach begins casting, attack before the spell resolves. If you inflict at least 1 wound, the spell fails; its action and declared resources are spent. Otherwise it resolves normally.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Veteran',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'Response; when an enemy within melee reach begins casting a spell.',
    ),
  ),
  36 => 
  array (
    'key' => 'execute',
    'name' => 'Execute',
    'category' => 'tactic',
    'flavor_text' => 'You turn a telling strike into a decisive one.',
    'rules_text' => '**Requirements:** Deadly.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a critical hit, before defenses.

When you critically hit, add 1 wound to the attack before defenses.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Deadly',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a critical hit, before defenses.',
    ),
  ),
  37 => 
  array (
    'key' => 'frenzy',
    'name' => 'Frenzy',
    'category' => 'tactic',
    'flavor_text' => 'You throw yourself into a sustained flurry of violence.',
    'rules_text' => '**Requirements:** Wild Vigor.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

For 3 rounds, you may make one additional melee attack on each of your turns, paying the normal minor action and multiple-attack penalty.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Wild Vigor',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  38 => 
  array (
    'key' => 'guarding-blow',
    'name' => 'Guarding Blow',
    'category' => 'tactic',
    'flavor_text' => 'Your strike forces an enemy to recover before pressing the attack.',
    'rules_text' => '**Requirements:** Veteran.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a hit.

On a hit, force a Reflexes save. On failure, the target’s next attack before the end of its next turn is Diminished.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Veteran',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a hit.',
    ),
  ),
  39 => 
  array (
    'key' => 'knock-down',
    'name' => 'Knock Down',
    'category' => 'tactic',
    'flavor_text' => 'You turn force and timing against your opponent’s footing.',
    'rules_text' => '**Requirements:** None.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a melee hit.

On a melee hit, force an Athletics save. On failure, the target falls Prone.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a melee hit.',
    ),
  ),
  40 => 
  array (
    'key' => 'order',
    'name' => 'Order',
    'category' => 'tactic',
    'flavor_text' => 'Your command calls for immediate, decisive action.',
    'rules_text' => '**Requirements:** Leadership, character level 7.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

Choose an ally within 30 feet who can hear you. Immediately after your current action resolves, it takes one major action, with no minor or free actions granted. This is not a new turn: it does not reset attack counts, saves, durations, or resources. A creature can benefit from Order only once per round.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Leadership',
      1 => 'character level 7',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  41 => 
  array (
    'key' => 'pin-down',
    'name' => 'Pin Down',
    'category' => 'tactic',
    'flavor_text' => 'You punish the first step of an enemy’s retreat.',
    'rules_text' => '**Requirements:** Veteran.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** Response; when an enemy within melee reach begins voluntary movement.

When an enemy within melee reach begins voluntary movement, attack before it leaves its space. If you inflict at least 1 wound, its movement ends and it cannot move voluntarily again this turn.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Veteran',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'Response; when an enemy within melee reach begins voluntary movement.',
    ),
  ),
  42 => 
  array (
    'key' => 'push-back',
    'name' => 'Push Back',
    'category' => 'tactic',
    'flavor_text' => 'You drive an opponent away with a forceful impact.',
    'rules_text' => '**Requirements:** None.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a melee hit.

On a melee hit, force an Athletics save. On failure, push the target up to 10 feet directly away. A solid obstacle ends the push. This is forced movement and does not trigger Pin Down.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a melee hit.',
    ),
  ),
  43 => 
  array (
    'key' => 'resurgence',
    'name' => 'Resurgence',
    'category' => 'tactic',
    'flavor_text' => 'You draw on hard-earned endurance to stay in the fight.',
    'rules_text' => '**Requirements:** Scarred, character level 3.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

Restore 1 Wound to yourself, up to your maximum.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Scarred',
      1 => 'character level 3',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  44 => 
  array (
    'key' => 'shadow-kick',
    'name' => 'Shadow Kick',
    'category' => 'tactic',
    'flavor_text' => 'You answer an opening with a swift unarmed counter.',
    'rules_text' => '**Requirements:** Martial Arts.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** Response; after an enemy within unarmed reach resolves an attack against you.

After an enemy within unarmed reach resolves an attack against you, make one unarmed attack against it. You must remain able to act.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Martial Arts',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'Response; after an enemy within unarmed reach resolves an attack against you.',
    ),
  ),
  45 => 
  array (
    'key' => 'shield-bash',
    'name' => 'Shield Bash',
    'category' => 'tactic',
    'flavor_text' => 'Your protection becomes a blunt weapon for a moment.',
    'rules_text' => '**Requirements:** A wielded shield.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

Make one additional melee attack using your wielded shield and Melee skill. It is a Lesser Attack and uses the normal multiple-attack penalty. The tactic’s minor action pays for this attack.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'A wielded shield',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  46 => 
  array (
    'key' => 'storm-fist',
    'name' => 'Storm Fist',
    'category' => 'tactic',
    'flavor_text' => 'Your hands move with the repeated force of a gathering storm.',
    'rules_text' => '**Requirements:** Martial Arts.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

For 3 rounds, you may make one additional unarmed attack on each of your turns, paying the normal minor action and multiple-attack penalty. This does not change its damage type.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Martial Arts',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  47 => 
  array (
    'key' => 'sunder',
    'name' => 'Sunder',
    'category' => 'tactic',
    'flavor_text' => 'You damage the protection rather than merely the wearer.',
    'rules_text' => '**Requirements:** Arms Mastery for the weapon used; the triggering attack must have Armor Piercing.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a hit with an Armor Piercing weapon.

On a hit with an Armor Piercing weapon, reduce the target’s worn armor base save to 10% if higher. This happens after the triggering hit; other armor bonuses still apply. Repeated uses do not reduce the base further. Repair takes 1 hour, appropriate tools, materials worth one-quarter of the armor’s listed price, and a successful Craft check. A failed check can be retried with another hour, without consuming additional materials. Natural Armor is unaffected.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Arms Mastery for the weapon used; the triggering attack must have Armor Piercing',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a hit with an Armor Piercing weapon.',
    ),
  ),
  48 => 
  array (
    'key' => 'sweep',
    'name' => 'Sweep',
    'category' => 'tactic',
    'flavor_text' => 'You use a long weapon to take an enemy’s legs from under it.',
    'rules_text' => '**Requirements:** A two-handed melee weapon.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a hit with a two-handed melee weapon.

On a hit with a two-handed melee weapon, force a Reflexes save. On failure, the target falls Prone.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'A two-handed melee weapon',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a hit with a two-handed melee weapon.',
    ),
  ),
  49 => 
  array (
    'key' => 'war-howl',
    'name' => 'War Howl',
    'category' => 'tactic',
    'flavor_text' => 'Your voice carries the promise of violence.',
    'rules_text' => '**Requirements:** Wild Vigor.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On your turn.

Choose one enemy within 30 feet that can hear you. It makes a Willpower save or suffers Fear of you for 3 rounds, with normal start-of-turn saves.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
      0 => 'Wild Vigor',
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On your turn.',
    ),
  ),
  50 => 
  array (
    'key' => 'wind',
    'name' => 'Wind',
    'category' => 'tactic',
    'flavor_text' => 'A punishing strike steals the breath needed for the next effort.',
    'rules_text' => '**Requirements:** None.

**Cost:** 1 Stamina and 1 minor action.

**Timing:** On a melee hit.

On a melee hit, force an additional Endurance save. On failure, the target loses 1 Stamina, to a minimum of 0. This is separate from its Suffering check.',
    'activation_type' => 'active',
    'prerequisites' => 
    array (
    ),
    'effects' => 
    array (
      'cost' => '1 Stamina and 1 minor action',
      'timing' => 'On a melee hit.',
    ),
  ),
);
