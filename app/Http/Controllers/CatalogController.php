<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\Background;
use App\Models\Feature;
use App\Models\HeroClass;
use App\Models\Item;
use App\Models\MagicOrder;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\Species;
use App\Models\Spell;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CatalogController extends Controller
{
    public function index(string $type)
    {
        [$model, $title, $with] = $this->spec($type);
        $query = $model::query()->with($with)->visibleTo(request()->user());
        if ($type === 'talents') {
            $query->whereIn('category', ['talent', 'tactic'])
                ->orderByRaw("case when category = 'tactic' then 1 else 0 end")
                ->orderBy('name');
        } elseif ($type === 'features') {
            $query->whereNotIn('category', ['talent', 'tactic'])->orderBy('name');
        } else {
            $query->orderBy('name');
        }
        $rows = $query->get()->map(fn ($row) => $this->summarize($type, $row));

        return Inertia::render('Catalog/Index', [
            'type' => $type,
            'title' => $title,
            'rows' => $rows,
            'form' => $this->formOptions($type),
        ]);
    }

    public function store(Request $request, string $type)
    {
        $this->persist($request, $type, null);

        return back()->with('success', 'Saved.');
    }

    public function update(Request $request, string $type, int $id)
    {
        $this->persist($request, $type, $id);

        return back()->with('success', 'Saved.');
    }

    public function destroy(string $type, int $id)
    {
        [$model] = $this->spec($type);
        $row = $model::query()->findOrFail($id);
        $this->authorizeOwned($row);
        $row->delete();

        return back()->with('success', 'Removed.');
    }

    private function persist(Request $request, string $type, ?int $id): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'flavor_text' => ['nullable', 'string'],
            'rules_text' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'activation_type' => ['nullable', 'string'],
            'cost_silver' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'stackable' => ['nullable', 'boolean'],
            'tier' => ['nullable', 'integer', 'min:0'],
            'order' => ['nullable', 'string'],
            'casting_time' => ['nullable', 'string'],
            'range_text' => ['nullable', 'string'],
            'duration_text' => ['nullable', 'string'],
            'requires_focus' => ['nullable', 'boolean'],
            'primary' => ['nullable', 'string'],
            'secondary' => ['nullable', 'string'],
            'attributes' => ['nullable', 'string'],
            'skills' => ['nullable', 'string'],
            'feature' => ['nullable', 'string'],
            'equipment' => ['nullable', 'string'],
            'role' => ['nullable', 'string'],
            'primary_skills' => ['nullable', 'string'],
            'progression' => ['nullable', 'string'],
        ];
        foreach ($this->requiredFields($type) as $field) {
            if (in_array($field, ['cost_silver', 'tier'], true)) {
                $rules[$field] = ['required', 'integer', 'min:0'];
            } elseif ($field === 'weight') {
                $rules[$field] = ['required', 'numeric', 'min:0'];
            } elseif ($field !== 'name') {
                $rules[$field] = ['required', 'string'];
            }
        }
        $data = $request->validate($rules);

        $userId = $request->user()->id;

        match ($type) {
            'species' => $this->row(Species::class, $id, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'traits_json' => [
                    'primary' => $this->list($data['primary'] ?? ''),
                    'secondary' => $this->list($data['secondary'] ?? ''),
                ],
            ], $userId),
            'backgrounds' => $this->row(Background::class, $id, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'grants_json' => [
                    'attributes' => $this->list($data['attributes'] ?? ''),
                    'skills' => $this->list($data['skills'] ?? ''),
                    'feature' => $data['feature'] ?? null,
                    'equipment' => $this->list($data['equipment'] ?? ''),
                ],
            ], $userId),
            'classes' => $this->saveClass($data, $id, $userId),
            'specializations' => $this->row(Specialization::class, $id, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'progression_json' => $this->progression($data['progression'] ?? ''),
            ], $userId),
            'items' => $this->row(Item::class, $id, [
                'name' => $data['name'],
                'category' => $data['category'] ?: 'gear',
                'description' => $data['description'] ?? null,
                'cost_silver' => (int) ($data['cost_silver'] ?? 0),
                'weight' => $data['weight'] ?? 0,
                'stackable' => $request->boolean('stackable'),
            ], $userId),
            'talents', 'features' => $this->row(Feature::class, $id, [
                'name' => $data['name'],
                'key' => Str::slug($data['name']),
                'category' => $type === 'talents' ? 'talent' : ($data['category'] ?: 'class'),
                'flavor_text' => $data['flavor_text'] ?? null,
                'rules_text' => $data['rules_text'] ?? $data['description'] ?? null,
                'activation_type' => $data['activation_type'] ?: 'passive',
            ], $userId),
            'spells' => $this->saveSpell($data, $id, $userId),
            default => abort(404),
        };
    }

    private function saveClass(array $data, ?int $id, int $userId): void
    {
        $role = Role::query()->where('name', $data['role'] ?? '')->first();
        $class = $this->row(HeroClass::class, $id, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'starting_role_id' => $role?->id,
            'progression_json' => $this->progression($data['progression'] ?? ''),
        ], $userId);
        $ids = Skill::query()->whereIn('name', $this->list($data['primary_skills'] ?? ''))->pluck('id');
        $class->primarySkills()->sync($ids);
    }

    private function saveSpell(array $data, ?int $id, int $userId): void
    {
        $order = null;
        if (! empty($data['order'])) {
            $order = MagicOrder::query()->firstOrCreate(['name' => $data['order']]);
        }
        $this->row(Spell::class, $id, [
            'name' => $data['name'],
            'order_id' => $order?->id,
            'tier' => (int) ($data['tier'] ?? 0),
            'flavor_text' => $data['flavor_text'] ?? null,
            'rules_text' => $data['rules_text'] ?? null,
            'casting_time' => $data['casting_time'] ?? null,
            'targeting_json' => ['text' => $data['range_text'] ?? null],
            'duration_json' => ['text' => $data['duration_text'] ?? null],
            'requires_focus' => (bool) ($data['requires_focus'] ?? false),
        ], $userId);
    }

    private function summarize(string $type, $row): array
    {
        $editable = $row->created_by !== null && (int) $row->created_by === (int) auth()->id();
        $base = ['id' => $row->id, 'name' => $row->name, 'description' => $row->description ?? $row->rules_text ?? $row->flavor_text, 'editable' => $editable];

        return match ($type) {
            'species' => $base + ['portrait_url' => $row->portrait_url, 'features' => collect(array_merge($row->traits_json['primary'] ?? [], $row->traits_json['secondary'] ?? []))->map(fn ($name) => [
                'name' => $name,
                'rules' => $this->featureRules($name, ['creation_primary', 'creation_secondary', 'background', 'talent']),
            ])->values(), 'detail' => implode(', ', array_merge($row->traits_json['primary'] ?? [], $row->traits_json['secondary'] ?? [])), 'edit' => [
                'primary' => implode(', ', $row->traits_json['primary'] ?? []),
                'secondary' => implode(', ', $row->traits_json['secondary'] ?? []),
            ]],
            'backgrounds' => $base + ['detail' => ($row->grants_json['feature'] ?? ''), 'bonuses' => [
                ...collect($row->grants_json['attributes'] ?? [])->map(fn ($name) => $this->attributeBonus($name))->all(),
                ...collect($row->grants_json['skills'] ?? [])->map(fn ($name) => $this->skillBonus($name))->all(),
            ], 'features' => collect(array_filter([$row->grants_json['feature'] ?? null]))->map(fn ($name) => [
                'name' => $name,
                'rules' => $this->featureRules($name, ['background']),
            ])->values(), 'edit' => [
                'attributes' => implode(', ', $row->grants_json['attributes'] ?? []),
                'skills' => implode(', ', $row->grants_json['skills'] ?? []),
                'feature' => $row->grants_json['feature'] ?? '',
                'equipment' => implode(', ', $row->grants_json['equipment'] ?? []),
            ]],
            'classes' => $base + ['detail' => $this->roleLabel($row->role?->name), 'detail_rules' => $this->roleTooltip($row->role), 'bonuses' => $row->primarySkills->map(fn (Skill $skill) => $this->primarySkillBonus($skill))->values(), 'features' => collect($row->progression_json ?? [])->map(function ($level) {
                $gain = $this->roleIncrease($level['feature'] ?? '');

                return [
                    'level' => $level['level'] ?? null,
                    'name' => $gain['name'] ?? ($level['feature'] ?? ''),
                    'rules' => $gain['rules'] ?? $this->featureRules($level['feature'] ?? '', ['class']),
                ];
            })->values(), 'edit' => [
                'role' => $row->role?->name,
                'primary_skills' => $row->primarySkills->pluck('name')->implode(', '),
                'progression' => collect($row->progression_json ?? [])->map(fn ($level) => $level['level'].': '.$level['feature'])->implode("\n"),
            ]],
            'specializations' => $base + ['detail' => null, 'features' => collect($row->progression_json ?? [])->map(function ($level) {
                $gain = $this->roleIncrease($level['feature'] ?? '');

                return [
                    'level' => $level['level'] ?? null,
                    'name' => $gain['name'] ?? ($level['feature'] ?? ''),
                    'rules' => $gain['rules'] ?? $this->featureRules($level['feature'] ?? ''),
                ];
            })->values(), 'edit' => [
                'progression' => collect($row->progression_json ?? [])->map(fn ($level) => $level['level'].': '.$level['feature'])->implode("\n"),
            ]],
            'items' => $base + ['detail' => $this->itemDetail($row), 'edit' => [
                'category' => $row->category,
                'cost_silver' => $row->cost_silver,
                'weight' => $row->weight,
                'stackable' => $row->stackable,
            ]],
            'talents', 'features' => ['id' => $row->id, 'name' => $row->name, 'description' => $this->withoutLeadFacts($row->rules_text), 'requirements' => $this->requirementLine($row), 'cost' => $row->category === 'tactic' ? ($row->effects_json['cost'] ?? null) : null, 'timing' => $row->category === 'tactic' ? ($row->effects_json['timing'] ?? null) : null, 'flavor' => $row->flavor_text, 'editable' => $editable, 'detail' => $this->featureCategoryLabel($row->category), 'edit' => [
                'flavor_text' => $row->flavor_text,
                'rules_text' => $row->rules_text,
                'category' => $row->category,
                'activation_type' => $row->activation_type,
            ]],
            'spells' => ['id' => $row->id, 'name' => $row->name, 'description' => $row->rules_text, 'flavor' => $row->flavor_text, 'casting_time' => $row->casting_time, 'range' => $row->targeting_json['text'] ?? null, 'duration' => $row->duration_json['text'] ?? null, 'empowered' => $row->empowered_effects_json['text'] ?? null, 'editable' => $editable, 'detail' => trim(($row->order?->name).' · Level '.$row->tier), 'edit' => [
                'order' => $row->order?->name,
                'tier' => $row->tier,
                'flavor_text' => $row->flavor_text,
                'rules_text' => $row->rules_text,
                'casting_time' => $row->casting_time,
                'range_text' => $row->targeting_json['text'] ?? '',
                'duration_text' => $row->duration_json['text'] ?? '',
                'requires_focus' => $row->requires_focus,
            ]],
            default => $base,
        };
    }

    private function formOptions(string $type): array
    {
        return [
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'attributes' => Attribute::query()->orderBy('name')->pluck('name'),
            'orders' => MagicOrder::query()->orderBy('name')->pluck('name'),
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredFields(string $type): array
    {
        return match ($type) {
            'species', 'backgrounds' => ['name', 'description'],
            'classes' => ['name', 'description', 'role', 'primary_skills', 'progression'],
            'specializations' => ['name', 'description', 'progression'],
            'items' => ['name', 'description', 'category', 'cost_silver', 'weight'],
            'talents' => ['name', 'rules_text', 'activation_type'],
            'features' => ['name', 'category', 'rules_text', 'activation_type'],
            'spells' => ['name', 'order', 'tier', 'rules_text', 'casting_time', 'range_text', 'duration_text'],
            default => ['name'],
        };
    }

    private function spec(string $type): array
    {
        return match ($type) {
            'species' => [Species::class, 'Species', []],
            'backgrounds' => [Background::class, 'Backgrounds', []],
            'classes' => [HeroClass::class, 'Classes', ['role', 'primarySkills.attribute']],
            'specializations' => [Specialization::class, 'Specializations', []],
            'items' => [Item::class, 'Items', []],
            'talents' => [Feature::class, 'Talents and Tactics', []],
            'features' => [Feature::class, 'Features', []],
            'spells' => [Spell::class, 'Spells', ['order']],
            default => abort(404),
        };
    }

    private function row(string $class, ?int $id, array $attributes, int $userId)
    {
        if ($id) {
            $model = $class::query()->findOrFail($id);
            $this->authorizeOwned($model);
            $model->update($attributes);

            return $model;
        }

        $attributes['created_by'] = $userId;

        return $class::query()->create($attributes);
    }

    private function authorizeOwned(object $row): void
    {
        abort_unless($row->created_by !== null && (int) $row->created_by === (int) auth()->id(), 403);
    }

    /**
     * @return array{name: string, rules: string}
     */
    private function attributeBonus(string $name): array
    {
        $clean = trim((string) preg_replace('/\+\d+.*/', '', $name));
        $attribute = $this->attributesByName()[strtolower($clean)] ?? null;
        $rules = "Increase {$clean} by 1 step.";
        if ($attribute?->description) {
            $rules .= ' '.$attribute->description;
        }

        return ['name' => $clean.' +1', 'rules' => $rules];
    }

    /**
     * @return array{name: string, rules: string}
     */
    private function skillBonus(string $name): array
    {
        $clean = trim($name);
        $skill = $this->skillsByName()[strtolower($clean)] ?? null;

        return ['name' => $clean.' +1', 'rules' => $this->skillBonusText($skill, $clean, 1, false)];
    }

    /**
     * @return array{name: string, rules: string}
     */
    private function primarySkillBonus(Skill $skill): array
    {
        return ['name' => $skill->name.' +2', 'rules' => $this->skillBonusText($skill, $skill->name, 2, true)];
    }

    private function skillBonusText(?Skill $skill, string $name, int $ranks, bool $chosen): string
    {
        $gain = $chosen
            ? "Every listed primary skill increases by {$ranks} ranks."
            : 'Gain +'.$ranks.' rank'.($ranks === 1 ? '' : 's')." in {$name}.";
        if (! $skill) {
            return $gain;
        }
        $kind = $skill->is_attack_skill ? 'an attack skill' : 'a nonattack skill';
        $attribute = $skill->attribute?->name;
        $association = $attribute ? "{$name} is {$kind} associated with {$attribute}." : "{$name} is {$kind}.";

        return $gain.' '.$association;
    }

    /**
     * @return array<string, Attribute>
     */
    private function attributesByName(): array
    {
        static $attributes;

        $attributes ??= Attribute::query()->get()->keyBy(fn (Attribute $attribute) => strtolower($attribute->name))->all();

        return $attributes;
    }

    /**
     * @return array<string, Skill>
     */
    private function skillsByName(): array
    {
        static $skills;

        $skills ??= Skill::query()->with('attribute')->get()->keyBy(fn (Skill $skill) => strtolower($skill->name))->all();

        return $skills;
    }

    private function itemDetail(Item $row): string
    {
        $gold = intdiv((int) $row->cost_silver, 10);
        $silver = (int) $row->cost_silver % 10;
        $price = trim($gold.'g '.($silver ? $silver.'s' : ''));
        $weight = (float) $row->weight;
        $pounds = rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');

        return $weight > 0 ? $price.' · '.$pounds.' lb' : $price;
    }

    private function roleLabel(?string $name): ?string
    {
        return $name === 'Magus' ? 'Spellcaster' : $name;
    }

    /**
     * @return array{name: string, rules: string}|null
     */
    private function roleIncrease(?string $name): ?array
    {
        return match (strtolower(rtrim(trim((string) $name), '.'))) {
            'magus +1' => [
                'name' => 'Spellcaster Advancement',
                'rules' => 'Spellcaster Advancement: +1 Sorcerous Potential, to a maximum of 5. Learn two spells and gain two Soul slots.',
            ],
            'martial +1' => [
                'name' => 'Martial Advancement',
                'rules' => 'Martial Advancement: +1 maximum Wound, and choose one eligible talent or tactic.',
            ],
            'rogue +1' => [
                'name' => 'Rogue Advancement',
                'rules' => 'Rogue Advancement: +1 Initiative Bonus and +2 percentage points to your critical range.',
            ],
            default => null,
        };
    }

    private function roleTooltip(?Role $role): ?string
    {
        if (! $role) {
            return null;
        }
        $label = $this->roleLabel($role->name);
        $increase = $this->roleIncrease($role->name === 'Magus' ? 'Magus +1' : $role->name.' +1');

        return implode("\n\n", array_filter([
            $role->description ? $label.' starting role: '.$role->description : null,
            $increase['rules'] ?? null,
        ]));
    }

    private function featureRules(string $name, array $preferred = ['class']): string
    {
        static $features;

        $features ??= \App\Models\Feature::query()->visibleTo(auth()->user())->get()->groupBy(fn ($feature) => strtolower($feature->name));
        $matches = $features[strtolower(rtrim(trim($name), '.'))] ?? collect();
        $feature = null;
        foreach ($preferred as $category) {
            $feature = $matches->firstWhere('category', $category);
            if ($feature) {
                break;
            }
        }
        $feature ??= $matches->first();
        if (! $feature) {
            return trim($name);
        }

        return trim(implode("\n\n", array_filter([
            $feature->flavor_text,
            str_replace(['\\.', '\\+'], ['.', '+'], (string) $feature->rules_text),
        ])));
    }

    private function requirementLine(object $row): ?string
    {
        if (! in_array($row->category, ['talent', 'tactic'], true)) {
            return null;
        }
        $named = collect($row->prerequisites_json ?? [])->filter()->implode(', ');

        return $named !== '' ? $named : 'None';
    }

    private function withoutLeadFacts(?string $rules): string
    {
        return trim((string) preg_replace('/^(?:(?:Requirements|Cost|Timing):[^\n]*\R*)+/', '', (string) $rules));
    }

    private function featureCategoryLabel(?string $category): string
    {
        return match ($category) {
            'creation_primary' => 'Primary',
            'creation_secondary' => 'Secondary',
            'talent' => 'Talent',
            'tactic' => 'Tactic',
            default => (string) $category,
        };
    }

    private function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function progression(string $value): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
            if (preg_match('/(\d+)\s*:\s*(.+)/', $line, $match)) {
                $rows[] = ['level' => (int) $match[1], 'feature' => trim($match[2])];
            }
        }

        return $rows;
    }
}
