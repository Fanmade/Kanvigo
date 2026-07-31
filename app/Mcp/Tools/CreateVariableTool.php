<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesVariables;
use App\Models\Variable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a variable in a project: a named stand-in for a fact that recurs or is not decided yet, written in prose as "[name]". Leave the value out to record the name while the fact is still open — an unset variable renders as its own name. Writing "[name]" in a description, doc body or comment never creates a variable; call this tool when the name should become part of the project\'s vocabulary. Requires a write-access token and the manage-variables permission in the project.')]
class CreateVariableTool extends Tool
{
    use RequiresWriteAccess;
    use ResolvesVariables;

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'name' => ['required', 'string'],
            'value' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ], [
            'reference.required' => 'You must provide the project short_name to add the variable to (e.g. "PROJ").',
            'name.required' => 'You must provide the variable name, e.g. "main_protagonist".',
        ]);

        $project = $this->resolveVariableProject($request, $validated['reference']);

        if ($project instanceof Response) {
            return $project;
        }

        $name = Variable::normalizeName($validated['name']);

        $check = Validator::make(
            ['name' => $name, 'value' => $validated['value'] ?? null, 'description' => $validated['description'] ?? null],
            [
                'name' => Variable::nameRules($project),
                'value' => Variable::valueRules(),
                'description' => Variable::descriptionRules(),
            ],
            [
                'name.regex' => 'A variable name must start with a letter and use only lowercase letters, digits, underscores and hyphens, e.g. "main_protagonist".',
                'name.unique' => 'The project already has a variable named "'.$name.'". Use update-variable to change what it stands for.',
            ],
        );

        if ($check->fails()) {
            return Response::error($check->errors()->first());
        }

        $variable = $project->variables()->create([
            'name' => $name,
            'value' => $validated['value'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        return Response::structured([
            'name' => $variable->name,
            'value' => $variable->value,
            'description' => $variable->description,
            'project' => $project->short_name,
            'usage' => '['.$variable->name.']',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The short_name of the project to add the variable to (e.g. "PROJ").')
                ->required(),

            'name' => $schema->string()
                ->description('The variable name: at least two characters, starting with a letter, then lowercase letters, digits, underscores or hyphens (e.g. "main_protagonist"). Lower-cased automatically.')
                ->required(),

            'value' => $schema->string()
                ->description('What the variable stands for, as plain single-line text. Omit it while the fact is still undecided.'),

            'description' => $schema->string()
                ->description('An optional note on what the variable is for.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The stored variable name.')->required(),
            'value' => $schema->string()->nullable()->description('What the variable stands for, or null while it is undecided.'),
            'description' => $schema->string()->nullable()->description('The note on what the variable is for, or null.'),
            'project' => $schema->string()->description('The short name of the project the variable belongs to.')->required(),
            'usage' => $schema->string()->description('How to write the variable in prose, e.g. "[main_protagonist]".')->required(),
        ];
    }
}
