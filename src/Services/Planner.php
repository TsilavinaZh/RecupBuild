<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Core\Config;
use RecupBuild\Errors\VisionError;
use RecupBuild\Models\Projects;

/**
 * The AI planner (the model proposes, the engine decides).
 *
 *   POST /api/plan      the project chosen by the user -> log, cutting plan, steps
 *   POST /api/projects  one call for the cards of the Project screen: projects the
 *                       model invents for this stock, checked by the engine
 *
 * Two questions, asked with no reasoning in between: the sizes and the fabrication
 * steps (only once a plan exists). The cards of the Project screen get one size per
 * project, straight from the model that invented it; /api/plan, when a size stops fitting, gets up to
 * four smaller options (largest first). Every number is clamped inside the parameter
 * bounds and checked piece by piece by `Solver`, so what is shown can always be cut.
 * When the options run out, the engine's own shrink rules take over.
 */
final class Planner
{
    /** How many projects the model invents for the cards: four, never more. */
    private const IDEAS = 4;

    /**
     * Sizes and plans one project, in the language the app asks for.
     *
     * @param list<array<string, mixed>> $pieces validated inventory (wire format)
     * @param ?array<string, mixed> $params sizes coming from the project card, when there is one
     *
     * @return array{status: string, reason: ?string, iterations: list<array<string, mixed>>, plan: ?array<string, mixed>, steps: ?list<string>}
     *
     * @throws VisionError when the model refuses to play
     */
    public static function plan(string|array $project, array $pieces, string $locale, ?array $params, Config $config): array
    {
        $project = Projects::definition($project);
        $stock = Solver::toStock($pieces);
        $head = [];

        if ($params !== null) {
            $current = Projects::clamp($project, $params);
            $pack = self::pack($project, $current, $stock);
            if ($pack['unsatisfied'] === []) {
                return self::planResult($project, $stock, $current, $pack['placements'], $head, $locale, $config, true);
            }

            // The card's sizes stopped fitting (the stock changed): the model offers smaller ones.
            $rungs = self::optionsFor($project, $stock, $current, $pack['unsatisfied'], $config);
            $next = self::nextRung($current, $rungs);
            if ($next === null) {
                return self::done($project, $pieces, $current, $head, $locale, $config, true);
            }

            $total = self::total($project, $current);
            $head[] = Solver::iteration(1, 'infeasible', $current, $total - self::missing($pack['unsatisfied']), $total, $pack['unsatisfied'], self::diff($current, $next));

            return self::walk($project, $pieces, $stock, $next, $rungs, $head, $locale, $config, true);
        }

        // No sizes yet (plain request): the model opens the ladder and picks the first rung.
        $rungs = self::optionsFor($project, $stock, null, null, $config);
        $current = array_shift($rungs) ?? Projects::defaults($project);

        return self::walk($project, $pieces, $stock, $current, $rungs, $head, $locale, $config, true);
    }

    /**
     * The project cards: the model invents the projects this very stock is good for
     * (name, use, sizes and parts), the engine checks each one piece by piece. The
     * Project screen keeps its local estimate until this arrives.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array{projects: list<array{definition: array<string, mixed>, result: array<string, mixed>}>}
     *
     * No steps in here: the cards only need sizes, and writing steps is a call each.
     *
     * The user always gets something to build, however small the stock: a project
     * that does not fit is relaxed (any thickness of the stock, smaller minimum
     * sizes); when none fits, the model is asked once more for smaller projects;
     * when still none fits, the engine makes a simple one from the longest piece.
     * Only buildable projects are shown, unless nothing at all can be built.
     *
     * @throws VisionError
     */
    public static function projects(array $pieces, string $locale, Config $config): array
    {
        $stock = Solver::toStock($pieces);

        $cards = self::invent($pieces, $stock, $locale, [], $config);
        if (self::buildable($cards) === []) {
            $failed = array_map(static fn (array $card): string => (string) $card['definition']['name'], $cards);
            try {
                $cards = [...$cards, ...self::invent($pieces, $stock, $locale, $failed, $config)];
            } catch (VisionError $error) {
                error_log('[recupbuild] second invention failed: ' . $error->getMessage());
            }
        }
        if (self::buildable($cards) === []) {
            $simple = Projects::simplest($stock, $locale);
            if ($simple !== null) {
                $cards[] = ['definition' => $simple, 'result' => self::walk($simple, $pieces, $stock, Projects::defaults($simple), [], [], null, $config, false)];
            }
        }
        if ($cards === []) {
            error_log('[recupbuild] no usable project from the model: built-in projects instead');
            foreach (Projects::IDS as $id) {
                $cards[] = ['definition' => Projects::definition($id), 'result' => self::walk($id, $pieces, $stock, Projects::defaults($id), [], [], null, $config, false)];
            }
        }

        $buildable = self::buildable($cards);

        return ['projects' => array_slice($buildable !== [] ? $buildable : $cards, 0, self::IDEAS)];
    }

    /**
     * One model call: the projects it invents, each checked (and relaxed when needed)
     * by the engine. `$failed` names projects that already proved too big.
     *
     * @param list<string> $failed
     *
     * @return list<array{definition: array<string, mixed>, result: array<string, mixed>}>
     *
     * @throws VisionError
     */
    private static function invent(array $pieces, array $stock, string $locale, array $failed, Config $config): array
    {
        $prompt = self::projectsPrompt($pieces, $locale);
        if ($failed !== []) {
            $prompt .= ' These ideas were too big for this stock: ' . self::json($failed) . '. Propose SMALLER, simpler '
                . 'objects instead (a coaster, a phone stand, a small box, a birdhouse, a key holder, a cutting '
                . 'board, a plant label...), with few parts that each fit inside one of the pieces listed.';
        }

        $answer = (array) Vision::complete($prompt, self::inventionSchema(), 'Projects', $config);
        $offset = $failed === [] ? 0 : self::IDEAS;
        $cards = [];
        foreach (is_array($answer['projects'] ?? null) ? $answer['projects'] : [] as $index => $raw) {
            $definition = Projects::sanitize(self::fromModel($raw, 'ai-' . ($offset + $index + 1)));
            if ($definition === null || count($cards) >= self::IDEAS) {
                continue;
            }
            $proposed = Projects::defaults($definition);
            // The model's size first; when a part finds no stock, the engine shrinks it itself.
            $result = self::walk($definition, $pieces, $stock, Projects::defaults($definition), [], [], null, $config, false);
            if ($result['status'] !== 'feasible') {
                // Reclaimed wood is rarely the thickness asked: accept what there is, and smaller sizes.
                $relaxed = Projects::relax($definition, $stock);
                $retry = self::walk($relaxed, $pieces, $stock, Projects::defaults($relaxed), [], [], null, $config, false);
                if ($retry['status'] === 'feasible') {
                    [$definition, $result] = [$relaxed, $retry];
                }
            }
            if ($result['plan'] !== null) {
                // The card shows the object in 3D, laid out as the model imagined it.
                $result['assembly'] = self::cardLayout($raw, $proposed, $result['plan']);
            }
            $cards[] = ['definition' => $definition, 'result' => $result];
        }

        return $cards;
    }

    /**
     * The 3D layout of a card: the spots the model gave each part at the size it
     * proposed, scaled when the engine had to shrink that size. Approximate, but
     * the card only needs a picture; /api/plan asks for the exact layout.
     */
    private static function cardLayout(mixed $raw, array $proposed, array $plan): ?array
    {
        $spots = [];
        foreach (is_array($raw) && is_array($raw['parts'] ?? null) ? $raw['parts'] : [] as $part) {
            if (is_array($part) && is_string($part['key'] ?? null) && is_array($part['layout'] ?? null)) {
                $spots[$part['key']] = array_values($part['layout']);
            }
        }

        $ratios = [];
        foreach ($proposed as $name => $value) {
            if ($value > 0 && isset($plan['params'][$name])) {
                $ratios[] = $plan['params'][$name] / $value;
            }
        }
        $scale = $ratios === [] ? 1.0 : array_product($ratios) ** (1 / count($ratios));

        $placements = $plan['placements'];
        usort($placements, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        $seen = [];
        $items = [];
        foreach ($placements as $placement) {
            $index = $seen[$placement['part']] = ($seen[$placement['part']] ?? -1) + 1;
            $spot = $spots[$placement['part']][$index] ?? null;
            if (!is_array($spot)) {
                continue;
            }
            $item = ['number' => $placement['number']] + $spot;
            foreach (['x', 'y', 'z'] as $axis) {
                if (is_numeric($item[$axis] ?? null)) {
                    $item[$axis] = (float) $item[$axis] * $scale;
                }
            }
            $items[] = $item;
        }

        return self::layout($items, $placements);
    }

    /** The cards that can be built, in their order. */
    private static function buildable(array $cards): array
    {
        return array_values(array_filter($cards, static fn (array $card): bool => $card['result']['status'] === 'feasible'));
    }

    /**
     * One project as the model wrote it (lists, empty strings for "none") in the
     * definition shape `Projects::sanitize` checks.
     */
    private static function fromModel(mixed $raw, string $id): ?array
    {
        if (!is_array($raw) || !is_array($raw['params'] ?? null) || !is_array($raw['parts'] ?? null)) {
            return null;
        }

        $params = [];
        foreach ($raw['params'] as $param) {
            if (is_array($param) && is_string($param['key'] ?? null)) {
                $params[$param['key']] = [
                    'label' => $param['label'] ?? null,
                    'min' => $param['min'] ?? null,
                    'default' => $param['size'] ?? null,
                    'max' => $param['max'] ?? null,
                ];
            }
        }

        $parts = [];
        foreach ($raw['parts'] as $part) {
            if (!is_array($part)) {
                return null;
            }
            $parts[] = [
                'name' => $part['key'] ?? null,
                'label' => $part['label'] ?? null,
                'material' => $part['material'] ?? null,
                'length' => ['param' => $part['length_param'] ?? null, 'add' => $part['length_mm'] ?? null],
                'width' => ['param' => $part['width_param'] ?? null, 'add' => $part['width_mm'] ?? null],
                'thickness' => ['min' => $part['thickness_min_mm'] ?? null, 'max' => $part['thickness_max_mm'] ?? null],
                'quantity' => $part['quantity'] ?? null,
            ];
        }

        return [
            'id' => $id,
            'name' => $raw['name'] ?? null,
            'description' => $raw['description'] ?? null,
            'params' => $params,
            'parts' => $parts,
        ];
    }

    // ------------------------------------------------------------------ walking

    /**
     * Tries the current sizes, then each option left on the ladder, and stops as
     * soon as every part fits. Attempts already in `$head` keep their numbers.
     *
     * @param list<array<string, mixed>> $head
     *
     * @return array{status: string, reason: ?string, iterations: list<array<string, mixed>>, plan: ?array<string, mixed>, steps: ?list<string>}
     */
    private static function walk(string|array $project, array $pieces, array $stock, array $params, array $rungs, array $head, ?string $locale, Config $config, bool $withSteps): array
    {
        $pack = self::pack($project, $params, $stock);
        if ($pack['unsatisfied'] === []) {
            return self::planResult($project, $stock, $params, $pack['placements'], $head, $locale, $config, $withSteps);
        }

        $next = self::nextRung($params, $rungs);
        if ($next === null) {
            return self::done($project, $pieces, $params, $head, $locale, $config, $withSteps);
        }

        $total = self::total($project, $params);
        $head[] = Solver::iteration(
            count($head) + 1,
            'infeasible',
            $params,
            $total - self::missing($pack['unsatisfied']),
            $total,
            $pack['unsatisfied'],
            self::diff($params, $next),
        );

        return self::walk($project, $pieces, $stock, $next, $rungs, $head, $locale, $config, $withSteps);
    }

    /** A plan exists: the model writes the steps for the sizes that survived. */
    private static function planResult(string|array $project, array $stock, array $params, array $placements, array $head, ?string $locale, Config $config, bool $withSteps): array
    {
        $total = self::total($project, $params);
        $head[] = Solver::iteration(count($head) + 1, 'feasible', $params, $total, $total, [], []);

        return self::finish($head, $project, $stock, $params, $placements, $locale, $config, $withSteps);
    }

    /**
     * No option left: the engine finishes from here, with its own shrinks and reasons.
     *
     * @param list<array<string, mixed>> $head
     *
     * @return array{status: string, reason: ?string, iterations: list<array<string, mixed>>, plan: ?array<string, mixed>, steps: ?list<string>}
     */
    private static function done(string|array $project, array $pieces, array $params, array $head, ?string $locale, Config $config, bool $withSteps): array
    {
        $engine = Solver::solve($project, $pieces, $params);
        $offset = count($head);
        foreach ($engine['iterations'] as $position => $attempt) {
            $attempt['index'] = $offset + $position + 1;
            $head[] = $attempt;
        }

        if ($engine['status'] !== 'feasible' || $engine['plan'] === null) {
            return [
                'status' => 'infeasible',
                'reason' => $engine['reason'],
                'iterations' => $head,
                'plan' => null,
                'steps' => null,
            ];
        }

        return self::finish($head, $project, Solver::toStock($pieces), $engine['plan']['params'], $engine['plan']['placements'], $locale, $config, $withSteps);
    }

    /**
     * The answer of /api/plan: the log, the drawn plan and the steps the model wrote for it.
     *
     * @param list<array<string, mixed>> $head
     *
     * @return array{status: string, reason: ?string, iterations: list<array<string, mixed>>, plan: array<string, mixed>, steps: ?list<string>}
     */
    private static function finish(array $head, string|array $project, array $stock, array $params, array $placements, ?string $locale, Config $config, bool $withSteps): array
    {
        $pieceIds = [];
        foreach ($placements as $placement) {
            if (!in_array($placement['piece_id'], $pieceIds, true)) {
                $pieceIds[] = $placement['piece_id'];
            }
        }

        $steps = null;
        $assembly = null;
        if ($withSteps) {
            try {
                ['steps' => $steps, 'assembly' => $assembly] = self::steps($project, $stock, $params, $placements, $locale, $config);
            } catch (VisionError) {
                // The plan stands on its own: the screen falls back to its own wording.
                error_log('[recupbuild] steps failed: budget or refusal');
            }
        }

        return [
            'status' => 'feasible',
            'reason' => null,
            'iterations' => $head,
            'plan' => [
                'project' => Projects::definition($project)['id'],
                'params' => $params,
                'placements' => $placements,
                'piece_ids' => $pieceIds,
                'kerf_mm' => Solver::KERF_MM,
            ],
            'steps' => $steps,
            // Where each numbered part sits in the finished object, for the 3D view.
            'assembly' => $assembly,
        ];
    }

    // ------------------------------------------------------------------ model

    /**
     * The model's ladder of size options: up to four, largest first after we sort
     * them. Nothing asked for without feedback (the first round).
     *
     * @return list<array<string, int>>
     *
     * @throws VisionError
     */
    private static function optionsFor(string|array $project, array $stock, ?array $current, ?array $unsatisfied, Config $config): array
    {
        $prompt = self::context($project, $stock, $current ?? Projects::defaults($project));
        if ($unsatisfied === null) {
            $prompt .= 'Nothing was attempted yet. Offer up to 4 size options, from the largest to the smallest that '
                . 'could work: every part listed must be placeable on that stock, so bury the parameters that drive '
                . 'the parts which are too wide for it. Staying near the default size beats shrinking everything.';
        } else {
            $prompt .= 'This size did not work: these parts found no stock ' . self::json($unsatisfied)
                . '. Offer up to 4 smaller size options, from the largest to the smallest still closer to the '
                . 'smallest bound, that let those parts fit.';
        }

        $answer = (array) Vision::complete($prompt, self::candidatesSchema($project), 'Sizes', $config);

        return self::ladder($project, $answer['candidates'] ?? null);
    }

    /** The fabrication steps, in the language asked. */
    /**
     * The fabrication steps, in the language asked, and for a project the model
     * invented, where each cut part sits once assembled (the app has its own
     * layouts for the built-in projects). One call for both.
     *
     * @return array{steps: ?list<string>, assembly: ?list<array<string, mixed>>}
     */
    private static function steps(string|array $project, array $stock, array $params, array $placements, ?string $locale, Config $config): array
    {
        $withLayout = !Projects::isBuiltIn($project);
        $prompt = self::context($project, $stock, $params)
            . 'These sizes already work: every part fits the stock. Answer with steps: exactly '
            . Projects::definition($project)['steps'] . ' plain language sentences in ' . self::language($locale)
            . ' telling how to fabricate this project in order (prepare and cut the pieces, sand them, pre-drill, '
            . 'assemble, then protect the surfaces): one complete sentence per step, no numbering, no markdown, '
            . 'no measurements.';
        if ($withLayout) {
            $cuts = [];
            foreach ($placements as $placement) {
                $cuts[] = [
                    'number' => $placement['number'],
                    'part' => $placement['part'],
                    'length_mm' => $placement['length_mm'],
                    'width_mm' => $placement['width_mm'],
                    'thickness_mm' => $placement['thickness_mm'],
                ];
            }
            $prompt .= ' Also answer with assembly: where each cut part sits in the finished object, so it can be '
                . 'drawn in 3D. Cut parts: ' . self::json($cuts) . '. Axes in millimetres: x to the right, y up, '
                . 'z towards the viewer; the object stands on y = 0. Each part is a box: give the number, the '
                . 'corner of the box with the smallest x, y and z, the axis its length lies along and the axis '
                . 'its width lies along (its thickness takes the third axis). Parts must touch where they are '
                . 'screwed together and never overlap, like the real object built from these steps.';
        }
        $answer = (array) Vision::complete($prompt, self::stepsSchema($project, $withLayout), 'Steps', $config);

        return [
            'steps' => self::clean($answer['steps'] ?? null, $project),
            'assembly' => $withLayout ? self::layout($answer['assembly'] ?? null, $placements) : null,
        ];
    }

    /**
     * The model's layout when it places most of the cut parts sensibly, otherwise null.
     *
     * @return ?list<array{number: int, x: float, y: float, z: float, length_axis: string, width_axis: string}>
     */
    private static function layout(mixed $raw, array $placements): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $numbers = array_column($placements, 'number');
        $layout = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_numeric($item['number'] ?? null)) {
                continue;
            }
            $number = (int) $item['number'];
            $lengthAxis = $item['length_axis'] ?? null;
            $widthAxis = $item['width_axis'] ?? null;
            if (!in_array($number, $numbers, true) || isset($layout[$number]) || !in_array($lengthAxis, ['x', 'y', 'z'], true)
                || !in_array($widthAxis, ['x', 'y', 'z'], true) || $lengthAxis === $widthAxis) {
                continue;
            }
            $corner = [];
            foreach (['x', 'y', 'z'] as $axis) {
                if (!is_numeric($item[$axis] ?? null) || abs((float) $item[$axis]) > 5000) {
                    continue 2;
                }
                $corner[$axis] = round((float) $item[$axis], 1);
            }
            $layout[$number] = ['number' => $number] + $corner + ['length_axis' => $lengthAxis, 'width_axis' => $widthAxis];
        }
        ksort($layout);

        return count($layout) * 2 >= count($numbers) ? array_values($layout) : null;
    }

    // ------------------------------------------------------------------ prompts

    /** @param list<array<string, mixed>> $pieces */
    private static function projectsPrompt(array $pieces, string $locale): string
    {
        $stock = [];
        foreach ($pieces as $piece) {
            if ($piece['length_mm'] !== null && $piece['width_mm'] !== null && $piece['thickness_mm'] !== null) {
                $stock[] = [
                    'kind' => $piece['kind'],
                    'length_mm' => max($piece['length_mm'], $piece['width_mm']),
                    'width_mm' => min($piece['length_mm'], $piece['width_mm']),
                    'thickness_mm' => $piece['thickness_mm'],
                ];
            }
        }

        return 'You are a carpenter who designs useful objects from reclaimed material. Here is everything '
            . 'available, one entry per piece (sheet_metal is metal, every other kind is wood): ' . self::json($stock) . '. '
            . 'Propose exactly ' . self::IDEAS . ' different projects that are genuinely '
            . 'useful at home, in the garden, the kitchen, the workshop or for small farming, and that THIS stock can '
            . 'really make: choose them for the sizes, thicknesses and kinds of pieces you see (long planks suit '
            . 'shelves and benches, short pieces suit boxes and stools, plywood suits panels and tops, sheet metal '
            . 'suits roofs, trays and covers). Never propose a project needing metal when there is no sheet_metal. '
            . 'When there are only a few or small pieces, propose small objects (coaster, phone stand, small box, '
            . 'key holder, cutting board, birdhouse...): every project must be buildable from THIS stock. '
            . 'Set the thickness range of each part so that it includes the thickness of the pieces it will be cut from. '
            . 'Rules of the cutting engine: every part is a rectangle cut out of one single piece (pieces are '
            . 'never glued or joined), the saw takes 3 mm per cut, a part fits a piece only when the piece is at '
            . 'least as long and as wide and its thickness lies inside the part\'s [thickness_min_mm, '
            . 'thickness_max_mm]; wood parts are cut from wood, metal parts from metal. '
            . 'Each project has 1 to 3 sizing parameters (key in camelCase like width, height, depth, '
            . 'length, with a label). For each give min, max and size: size is the size you propose, the largest '
            . 'that still lets every part fit on this stock; min and max are what still makes sense for the object. '
            . 'All parameter values are millimetres and multiples of 10. '
            . 'Each part (2 to 8 kinds, 2 to 30 cuts in total) gives its key (camelCase), label, material, '
            . 'quantity and its two sides: a side driven by a parameter has length_param (or width_param) set to '
            . 'that parameter key and length_mm (or width_mm) the millimetres added to it (0, or negative to '
            . 'subtract); a fixed side has the param set to "" and the mm set to its size. '
            . 'Each part also gives its layout: one entry per copy (quantity entries), where that copy sits in the '
            . 'finished object at the size you propose, so it can be drawn in 3D. Axes in millimetres: x to the '
            . 'right, y up, z towards the viewer, the object standing on y = 0; each copy is a box, give the '
            . 'corner with the smallest x, y and z, the axis its length lies along and the axis its width lies '
            . 'along (its thickness takes the third axis). Parts touch where they are fixed together and never '
            . 'overlap, like the real object. '
            . 'Write name, description (one sentence: what it is for), labels in ' . self::language($locale) . '. '
            . 'Answer only with the JSON asked.';
    }

    /** What the model answers for the project cards: lists only, "" when a side has no parameter. */
    private static function inventionSchema(): array
    {
        $param = self::obj(['key', 'label', 'min', 'size', 'max'], [
            'key' => ['type' => 'string'],
            'label' => ['type' => 'string'],
            'min' => ['type' => 'integer'],
            'size' => ['type' => 'integer'],
            'max' => ['type' => 'integer'],
        ]);
        $axis = ['type' => 'string', 'enum' => ['x', 'y', 'z']];
        $spot = self::obj(['x', 'y', 'z', 'length_axis', 'width_axis'], [
            'x' => ['type' => 'number'],
            'y' => ['type' => 'number'],
            'z' => ['type' => 'number'],
            'length_axis' => $axis,
            'width_axis' => $axis,
        ]);
        $part = self::obj(
            ['key', 'label', 'material', 'quantity', 'length_param', 'length_mm', 'width_param', 'width_mm', 'thickness_min_mm', 'thickness_max_mm', 'layout'],
            [
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'material' => ['type' => 'string', 'enum' => ['wood', 'metal']],
                'quantity' => ['type' => 'integer'],
                'length_param' => ['type' => 'string'],
                'length_mm' => ['type' => 'integer'],
                'width_param' => ['type' => 'string'],
                'width_mm' => ['type' => 'integer'],
                'thickness_min_mm' => ['type' => 'number'],
                'thickness_max_mm' => ['type' => 'number'],
                'layout' => ['type' => 'array', 'items' => $spot],
            ],
        );
        $project = self::obj(['name', 'description', 'params', 'parts'], [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'params' => ['type' => 'array', 'items' => $param, 'minItems' => 1, 'maxItems' => 3],
            'parts' => ['type' => 'array', 'items' => $part, 'minItems' => 1, 'maxItems' => 8],
        ]);

        return self::obj(['projects'], [
            'projects' => ['type' => 'array', 'items' => $project, 'minItems' => 1, 'maxItems' => self::IDEAS],
        ]);
    }

    private static function context(string|array $project, array $stock, array $params): string
    {
        $description = Projects::describe($project, $params);

        return 'You size a DIY project cut from reclaimed material. Sizes are whole millimetres, every parameter a '
            . 'multiple of 10 inside its [min, max] bounds. Answer only with the JSON asked. '
            . 'Project: ' . self::json(['name' => $description['name'], 'description' => $description['description']]) . '. '
            . 'Stock (length, width, thickness): ' . self::json($stock) . '. '
            . 'Sizing parameters (min, default, max): ' . self::json($description['params'])
            . ' (sizes: ' . self::json($params) . '). '
            . 'Parts these parameters produce, with the parameter driving each dimension: ' . self::json($description['parts']) . '. ';
    }

    private static function language(?string $locale): string
    {
        return match ($locale) {
            'en' => 'English',
            'mg' => 'Malagasy',
            default => 'French',
        };
    }

    // ------------------------------------------------------------------ schemas

    /** @param list<string> $required @param array<string, array<string, mixed>> $properties */
    private static function obj(array $required, array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_values($required), 'properties' => $properties];
    }

    /** The parameter object of a project: one integer per parameter. */
    private static function paramObject(string|array $project): array
    {
        $properties = [];
        foreach (Projects::bounds($project) as $name => $range) {
            $properties[$name] = ['type' => 'integer'];
        }

        return self::obj(array_keys($properties), $properties);
    }

    private static function candidatesSchema(string|array $project): array
    {
        return self::obj(['candidates'], [
            'candidates' => ['type' => 'array', 'items' => self::paramObject($project), 'minItems' => 1, 'maxItems' => 4],
        ]);
    }

    private static function stepsSchema(string|array $project, bool $withLayout = false): array
    {
        $count = Projects::definition($project)['steps'];
        $properties = [
            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => $count, 'maxItems' => $count],
        ];
        if ($withLayout) {
            $axis = ['type' => 'string', 'enum' => ['x', 'y', 'z']];
            $properties['assembly'] = ['type' => 'array', 'items' => self::obj(
                ['number', 'x', 'y', 'z', 'length_axis', 'width_axis'],
                ['number' => ['type' => 'integer'], 'x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'z' => ['type' => 'number'], 'length_axis' => $axis, 'width_axis' => $axis],
            )];
        }

        return self::obj(array_keys($properties), $properties);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{placements: list<array<string, mixed>>, unsatisfied: list<array{part: string, missing: int}>} */
    private static function pack(string|array $project, array $params, array $stock): array
    {
        return Solver::pack(Projects::parts($project, $params), $stock);
    }

    private static function total(string|array $project, array $params): int
    {
        $total = 0;
        foreach (Projects::parts($project, $params) as $spec) {
            $total += $spec['quantity'];
        }

        return $total;
    }

    private static function missing(array $unsatisfied): int
    {
        $sum = 0;
        foreach ($unsatisfied as $item) {
            $sum += $item['missing'];
        }

        return $sum;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * The model's options as a ladder: clamped, deduplicated, largest first.
     *
     * @return list<array<string, int>>
     */
    private static function ladder(string|array $project, mixed $candidates): array
    {
        if (!is_array($candidates)) {
            return [];
        }

        $ladder = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $params = Projects::clamp($project, $candidate);
            $key = implode(',', $params);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ladder[] = ['params' => $params, 'score' => array_product($params)];
        }
        usort($ladder, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column($ladder, 'params');
    }

    /** The next option that actually changes the size. */
    private static function nextRung(array $current, array &$rungs): ?array
    {
        while ($rungs !== []) {
            $candidate = array_shift($rungs);
            if (self::diff($current, $candidate) !== []) {
                return $candidate;
            }
        }

        return null;
    }

    /** From the sizes to the next option: what the Calculation log displays. */
    private static function diff(array $from, array $to): array
    {
        $adjustments = [];
        foreach ($from as $name => $value) {
            if (($to[$name] ?? null) !== $value) {
                $adjustments[] = ['param' => $name, 'from' => (int) $value, 'to' => (int) ($to[$name] ?? $value)];
            }
        }

        return $adjustments;
    }

    /** The model's steps when they read like usable fabrication steps, otherwise null. */
    private static function clean(mixed $steps, string|array $project): ?array
    {
        if (!is_array($steps) || $steps === []) {
            return null;
        }

        $clean = [];
        foreach ($steps as $step) {
            if (!is_string($step)) {
                continue;
            }
            $text = trim($step);
            $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($text !== '' && $length <= 400) {
                $clean[] = $text;
            }
        }

        $expected = Projects::definition($project)['steps'];

        return count($clean) >= $expected ? array_slice($clean, 0, $expected) : null;
    }
}