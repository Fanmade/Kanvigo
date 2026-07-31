<?php

use App\Models\Project;
use App\Models\Variable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
});

it('stores names lower-cased and trimmed', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => '  Main_Protagonist ']);

    expect($variable->fresh()->name)->toBe('main_protagonist');
});

it('treats a blank value and description as unset', function () {
    $variable = Variable::factory()->for($this->project)->create(['value' => '   ', 'description' => '']);

    expect($variable->fresh()->value)->toBeNull()
        ->and($variable->fresh()->description)->toBeNull()
        ->and($variable->isUnset())->toBeTrue();
});

it('trims a value it keeps', function () {
    $variable = Variable::factory()->for($this->project)->create(['value' => '  Robin Hood  ']);

    expect($variable->fresh()->value)->toBe('Robin Hood')
        ->and($variable->isUnset())->toBeFalse();
});

it('accepts names starting with a letter and made of word characters', function (string $name) {
    expect(Variable::isValidName($name))->toBeTrue();
})->with(['hero', 'main_protagonist', 'ship-name', 'weapon2', 'Hero']);

it('rejects names that could collide with ordinary bracketed prose', function (string $name) {
    expect(Variable::isValidName($name))->toBeFalse();
})->with(['h', '1', '12', 'i', '2026-07-31 15:00', '_hero', 'main protagonist', 'héro', 'hero!']);

it('validates a name against the pattern and the project namespace', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    $rules = ['name' => Variable::nameRules($this->project)];

    expect(Validator::make(['name' => 'villain'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['name' => 'hero'], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['name' => '1'], $rules)->passes())->toBeFalse();
});

it('exempts the variable being renamed from the uniqueness check', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero']);

    $rules = ['name' => Variable::nameRules($this->project, $variable)];

    expect(Validator::make(['name' => 'hero'], $rules)->passes())->toBeTrue();
});

it('scopes names to a project', function () {
    $other = Project::factory()->create();

    Variable::factory()->for($this->project)->create(['name' => 'hero']);
    Variable::factory()->for($other)->create(['name' => 'hero']);

    expect(Validator::make(['name' => 'hero'], ['name' => Variable::nameRules($other)])->passes())->toBeFalse()
        ->and(Variable::query()->count())->toBe(2);
});

it('refuses a duplicate name within a project at the database level', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    Variable::factory()->for($this->project)->create(['name' => 'HERO']);
})->throws(QueryException::class);

it('exposes a project\'s variables in name order', function () {
    Variable::factory()->for($this->project)->create(['name' => 'zeppelin']);
    Variable::factory()->for($this->project)->create(['name' => 'airship']);

    expect($this->project->variables()->pluck('name')->all())->toBe(['airship', 'zeppelin']);
});

it('deletes a project\'s variables with the project', function () {
    Variable::factory()->for($this->project)->create();

    $this->project->delete();

    expect(Variable::query()->count())->toBe(0);
});
