<?php

namespace App\Mcp\Tools;

use App\Actions\RewriteVariableUsages;
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

#[Description('Updates a project variable: change what it stands for ("value"), its note ("description"), and/or rename it ("new_name"). Changing a value rewrites nothing — every "[name]" in the project simply shows the new value from now on. Renaming does rewrite content: each usage is changed to the new name, and the response reports how many items were updated, so you can say what happened. Pass an empty value to return the variable to undecided. Requires a write-access token and the manage-variables permission in the project.')]
class UpdateVariableTool extends Tool
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
            'new_name' => ['nullable', 'string'],
            'value' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ], [
            'reference.required' => 'You must provide the project short_name the variable belongs to (e.g. "PROJ").',
            'name.required' => 'You must provide the name of the variable to update.',
        ]);

        $project = $this->resolveVariableProject($request, $validated['reference']);

        if ($project instanceof Response) {
            return $project;
        }

        $variable = $this->resolveVariable($project, $validated['name']);

        if ($variable instanceof Response) {
            return $variable;
        }

        $from = $variable->name;
        $to = $request->has('new_name') ? Variable::normalizeName((string) ($validated['new_name'] ?? '')) : $from;

        $check = Validator::make(
            ['new_name' => $to, 'value' => $validated['value'] ?? null, 'description' => $validated['description'] ?? null],
            [
                'new_name' => Variable::nameRules($project, $variable),
                'value' => Variable::valueRules(),
                'description' => Variable::descriptionRules(),
            ],
            [
                'new_name.regex' => 'A variable name must start with a letter and use only lowercase letters, digits, underscores and hyphens, e.g. "main_protagonist".',
                'new_name.unique' => 'The project already has a variable named "'.$to.'".',
            ],
        );

        if ($check->fails()) {
            return Response::error($check->errors()->first());
        }

        $changes = ['name' => $to];

        if ($request->has('value')) {
            $changes['value'] = $validated['value'] ?? null;
        }

        if ($request->has('description')) {
            $changes['description'] = $validated['description'] ?? null;
        }

        $variable->update($changes);

        // Renaming runs inline rather than on the queue: there is nobody to
        // confirm with, and the caller has to be told what it changed.
        $rewritten = $to === $from
            ? 0
            : app(RewriteVariableUsages::class)->handle($project->getKey(), $from, $to);

        return Response::structured([
            'name' => $variable->name,
            'value' => $variable->value,
            'description' => $variable->description,
            'project' => $project->short_name,
            'usage' => '['.$variable->name.']',
            'renamed_from' => $to === $from ? null : $from,
            'usages_rewritten' => $rewritten,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The short_name of the project the variable belongs to (e.g. "PROJ").')
                ->required(),

            'name' => $schema->string()
                ->description('The current name of the variable to update.')
                ->required(),

            'new_name' => $schema->string()
                ->description('A new name for the variable. Every usage in the project\'s content is rewritten to it, which counts as an ordinary edit of those items. Omit to leave the name unchanged.'),

            'value' => $schema->string()
                ->description('What the variable stands for from now on. Pass an empty value to return it to undecided; omit to leave unchanged.'),

            'description' => $schema->string()
                ->description('A new note on what the variable is for. Pass an empty value to clear it; omit to leave unchanged.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The stored variable name after the update.')->required(),
            'value' => $schema->string()->nullable()->description('What the variable stands for, or null when it is undecided.'),
            'description' => $schema->string()->nullable()->description('The note on what the variable is for, or null.'),
            'project' => $schema->string()->description('The short name of the project the variable belongs to.')->required(),
            'usage' => $schema->string()->description('How to write the variable in prose, e.g. "[main_protagonist]".')->required(),
            'renamed_from' => $schema->string()->nullable()->description('The previous name when this call renamed the variable, or null when it did not.'),
            'usages_rewritten' => $schema->integer()->description('How many tasks, docs, comments or project descriptions had their content rewritten by a rename.')->required(),
        ];
    }
}
