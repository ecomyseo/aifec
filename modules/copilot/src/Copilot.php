<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;

/**
 * El copiloto del panel (servicio «copilot»): entender una orden, enseñar qué cambiaría, aplicarla cuando la persona lo aprueba y
 * deshacerla mientras nada la haya tocado.
 *
 * El camino de una orden:
 *   propose()   entiende el texto (con IA si está encendida y, si falla o no elige nada, por reglas), lo guarda como propuesta y
 *               calcula su vista previa. NO cambia nada.
 *   complete() / choose()   la persona rellena un dato que faltaba, elige entre varias lecturas o corrige uno.
 *   apply()     recalcula la vista previa; si su huella no es la que vio la persona, NO aplica y enseña la nueva. Si coincide,
 *               aplica (un solo ganador si llegan dos peticiones), anota en la auditoría y guarda lo necesario para deshacer.
 *   undo()      solo mientras lo cambiado siga como lo dejó la propuesta.
 *
 * Cada acción exige además el permiso propio de lo que toca (Actions::allowed). Las propuestas son de quien las escribió.
 */
final class Copilot
{
    /** @var App */
    private $app;

    /** @var Actions */
    private $actions;

    /** @var Proposals */
    private $proposals;

    /** @var Planner */
    private $planner;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->actions = new Actions($app);
        $this->proposals = new Proposals($app);
        $this->planner = new Planner($app);
    }

    public function actions(): Actions
    {
        return $this->actions;
    }

    public function proposals(): Proposals
    {
        return $this->proposals;
    }

    // ------------------------------------------------------------------ proponer

    /**
     * Entiende una orden y la guarda como propuesta. Siempre deja una fila: aunque no se entienda, queda apuntada.
     *
     * @return array<string,mixed> la propuesta
     */
    public function propose(string $text): array
    {
        $text = trim(mb_substr(mb_scrub($text, 'UTF-8'), 0, Understand::TEXT_MAX, 'UTF-8'));
        $this->actions->reset();
        $reading = $this->read($text);
        if ($reading === null) {
            $id = $this->proposals->create([
                'text' => $text, 'status' => 'failed', 'source' => 'rules',
                'result' => t('I did not understand that. Try with the order number, the product or the setting; this is what I found.'),
            ]);

            return $this->mustFind($id);
        }
        $def = $this->actions->get($reading['action']);
        if ($def === null) {
            throw new \LogicException('The action is not registered: ' . $reading['action']);
        }
        if (!$this->actions->allowed($def, $reading['args'])) {
            $id = $this->proposals->create([
                'text' => $text, 'action' => $reading['action'], 'status' => 'failed', 'source' => $reading['source'],
                'result' => t('You do not have permission to do this.'),
                'preview' => ['summary' => (string) $def['label'], 'changes' => [], 'count' => 0, 'more' => 0, 'warnings' => [], 'errors' => [], 'choices' => [], 'links' => [], 'missing' => [], 'readonly' => (bool) $def['readonly']],
            ]);

            return $this->mustFind($id);
        }
        $plan = $this->planner->plan($def, $reading['args']);
        $plan['preview']['alternatives'] = $reading['alternatives'];
        $id = $this->proposals->create([
            'text' => $text, 'action' => $reading['action'], 'args' => $reading['args'], 'preview' => $plan['preview'],
            'status' => $plan['status'], 'fingerprint' => $plan['fingerprint'], 'source' => $reading['source'],
        ]);

        return $this->mustFind($id);
    }

    /**
     * Qué entiende el copiloto de un texto: con IA primero (si está encendida) y, si falla o no elige acción, por reglas.
     *
     * @return array{action:string,args:array<string,mixed>,source:string,alternatives:array<int,array<string,mixed>>}|null
     */
    private function read(string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        $ai = new AiUnderstand($this->app, $this->actions);
        if ($ai->available()) {
            try {
                $chosen = $ai->run($text);
            } catch (\Throwable $e) {
                $this->app->log()->warning('Copilot: the AI could not understand the order, the rules are used: ' . $e->getMessage());
                $chosen = null;
            }
            $def = $chosen !== null ? $this->actions->get($chosen['action']) : null;
            if ($chosen !== null && $def !== null) {
                list($args) = Params::read($def['params'], $chosen['args']);

                return ['action' => $chosen['action'], 'args' => $args, 'source' => 'ai', 'alternatives' => []];
            }
        }
        $candidates = (new Understand($this->app, $this->actions))->candidates($text);
        if ($candidates === []) {
            return null;
        }
        $alternatives = [];
        foreach (array_slice($candidates, 1) as $other) {
            $otherDef = $this->actions->get($other['action']);
            if ($otherDef !== null) {
                $alternatives[] = ['action' => $other['action'], 'args' => $other['args'], 'label' => (string) $otherDef['label']];
            }
        }

        return ['action' => $candidates[0]['action'], 'args' => $candidates[0]['args'], 'source' => 'rules', 'alternatives' => $alternatives];
    }

    // ------------------------------------------------------------------ completar, elegir

    /**
     * La persona corrige o rellena datos de la propuesta (un dato vacío se quita). Vuelve a calcular la vista previa.
     *
     * @param array<string,mixed> $input nombre del dato => valor escrito
     *
     * @return array<string,mixed>
     */
    public function complete(int $id, array $input): array
    {
        $row = $this->mine($id);
        $def = $this->actions->get((string) $row['action']);
        if ($def === null || !in_array((string) $row['status'], ['proposed', 'incomplete'], true)) {
            return $row;
        }
        $args = $row['args'];
        list($new) = Params::read($def['params'], $input);
        foreach ($def['params'] as $param) {
            $name = (string) $param['name'];
            if (array_key_exists($name, $input)) {
                unset($args[$name]);
                if (isset($new[$name])) {
                    $args[$name] = $new[$name];
                }
            }
        }

        return $this->replan($row, $def, $args, isset($row['preview']['alternatives']) && is_array($row['preview']['alternatives']) ? $row['preview']['alternatives'] : []);
    }

    /**
     * La persona elige una de las otras lecturas que se le ofrecieron.
     *
     * @return array<string,mixed>
     */
    public function choose(int $id, int $index): array
    {
        $row = $this->mine($id);
        $alternatives = isset($row['preview']['alternatives']) && is_array($row['preview']['alternatives']) ? array_values($row['preview']['alternatives']) : [];
        if (!in_array((string) $row['status'], ['proposed', 'incomplete'], true) || !isset($alternatives[$index])) {
            return $row;
        }
        $chosen = $alternatives[$index];
        $def = $this->actions->get((string) $chosen['action']);
        if ($def === null || !$this->actions->allowed($def, is_array($chosen['args']) ? $chosen['args'] : [])) {
            return $row;
        }
        $previous = $this->actions->get((string) $row['action']);
        $alternatives[$index] = ['action' => (string) $row['action'], 'args' => $row['args'], 'label' => $previous !== null ? (string) $previous['label'] : (string) $row['action']];

        return $this->replan($row, $def, is_array($chosen['args']) ? $chosen['args'] : [], $alternatives);
    }

    /**
     * @param array<string,mixed>            $row
     * @param array<string,mixed>            $def
     * @param array<string,mixed>            $args
     * @param array<int,array<string,mixed>> $alternatives
     *
     * @return array<string,mixed>
     */
    private function replan(array $row, array $def, array $args, array $alternatives): array
    {
        if (!$this->actions->allowed($def, $args)) {
            return $row;
        }
        $plan = $this->planner->plan($def, $args);
        $plan['preview']['alternatives'] = $alternatives;
        $this->proposals->update((int) $row['id'], [
            'action' => (string) $def['id'], 'args' => $args, 'preview' => $plan['preview'], 'status' => $plan['status'], 'fingerprint' => $plan['fingerprint'],
        ]);

        return $this->mustFind((int) $row['id']);
    }

    // ------------------------------------------------------------------ aplicar

    /**
     * Aplica una propuesta que la persona ha visto.
     *
     * @return array{ok:bool,stale:bool,message:string,proposal:array<string,mixed>}
     *
     * @throws HttpError 403 si no tiene el permiso de la acción; 404 si la propuesta no es suya
     */
    public function apply(int $id): array
    {
        $row = $this->mine($id);
        if ((string) $row['status'] !== 'proposed') {
            return $this->result($row, false, false, (string) $row['status'] === 'applied' ? t('This change was already applied.') : t('This proposal cannot be applied. Check it and try again.'));
        }
        $def = $this->actions->get((string) $row['action']);
        if ($def === null) {
            return $this->result($row, false, false, t('That action is not available any more.'));
        }
        if (!$this->actions->allowed($def, $row['args'])) {
            throw HttpError::forbidden('Missing permission: ' . $this->actions->permission($def, $row['args']));
        }
        if ($def['readonly']) {
            return $this->result($row, false, false, t('This one only informs: it cannot be applied.'));
        }

        // Se vuelve a calcular lo que se va a cambiar. Si la huella no es la que vio la persona, no se aplica: se enseña lo nuevo.
        $plan = $this->planner->plan($def, $row['args']);
        if ($plan['fingerprint'] === '' || $plan['fingerprint'] !== (string) $row['fingerprint']) {
            $plan['preview']['alternatives'] = isset($row['preview']['alternatives']) ? $row['preview']['alternatives'] : [];
            $this->proposals->update($id, ['preview' => $plan['preview'], 'status' => $plan['status'], 'fingerprint' => $plan['fingerprint']]);

            return $this->result($this->mustFind($id), false, true, t('The data changed since you looked at it. This is the new preview: check it and apply again.'));
        }
        if ($plan['status'] !== 'proposed' || $plan['preview']['errors'] !== []) {
            $first = $plan['preview']['errors'] !== [] ? (string) $plan['preview']['errors'][0] : t('This proposal cannot be applied. Check it and try again.');
            $this->proposals->update($id, ['preview' => $plan['preview'] + ['alternatives' => []], 'status' => $plan['status']]);

            return $this->result($this->mustFind($id), false, false, $first);
        }

        // Una sola petición gana: la que pasa la propuesta de «proposed» a «applied».
        $now = $this->app->clock()->sql();
        if (!$this->proposals->claim($id, 'proposed', 'applied', ['applied_at' => $now])) {
            return $this->result($this->mustFind($id), false, false, t('This change was already applied.'));
        }
        try {
            $done = $this->actions->call($def, 'apply', [$row['args']]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'copilot apply ' . $def['id']);
            $done = ['ok' => false, 'message' => t('The change could not be made.')];
        }
        $done = is_array($done) ? $done : ['ok' => false, 'message' => t('The change could not be made.')];
        $message = isset($done['message']) && is_string($done['message']) ? $done['message'] : '';
        if (empty($done['ok'])) {
            $this->proposals->update($id, ['status' => 'failed', 'result' => $message, 'applied_at' => null]);

            return $this->result($this->mustFind($id), false, false, $message !== '' ? $message : t('The change could not be made.'));
        }
        $undo = isset($done['undo']) && is_array($done['undo']) && $def['undo'] !== null ? $done['undo'] : null;
        $this->proposals->update($id, ['result' => $message, 'undo' => $undo]);
        Admin::audit($this->app, 'copilot.apply', 'copilot', (string) $id, (string) $plan['preview']['summary'], [
            'action' => [null, (string) $def['id']], 'records' => [null, (string) $plan['preview']['count']],
        ]);

        return $this->result($this->mustFind($id), true, false, $message);
    }

    // ------------------------------------------------------------------ deshacer, descartar

    /**
     * Deshace una propuesta aplicada, solo si lo cambiado sigue como lo dejó.
     *
     * @return array{ok:bool,stale:bool,message:string,proposal:array<string,mixed>}
     *
     * @throws HttpError
     */
    public function undo(int $id): array
    {
        $row = $this->mine($id);
        $def = $this->actions->get((string) $row['action']);
        if ((string) $row['status'] !== 'applied' || $row['undo'] === null || $def === null || $def['undo'] === null) {
            return $this->result($row, false, false, t('This change cannot be undone.'));
        }
        if (!$this->actions->allowed($def, $row['args'])) {
            throw HttpError::forbidden('Missing permission: ' . $this->actions->permission($def, $row['args']));
        }
        if (!$this->proposals->claim($id, 'applied', 'undone', ['undone_at' => $this->app->clock()->sql()])) {
            return $this->result($this->mustFind($id), false, false, t('This change cannot be undone.'));
        }
        try {
            $done = $this->actions->call($def, 'undo', [$row['undo']]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'copilot undo ' . $def['id']);
            $done = ['ok' => false, 'message' => t('The change could not be undone.')];
        }
        $done = is_array($done) ? $done : ['ok' => false, 'message' => t('The change could not be undone.')];
        $message = isset($done['message']) && is_string($done['message']) ? $done['message'] : '';
        if (empty($done['ok'])) {
            // No se pudo: sigue aplicada (y se podrá volver a intentar si el dato vuelve a ser el que era).
            $this->proposals->update($id, ['status' => 'applied', 'undone_at' => null]);

            return $this->result($this->mustFind($id), false, false, $message !== '' ? $message : t('The change could not be undone.'));
        }
        $this->proposals->update($id, ['result' => $message, 'undo' => null]);
        Admin::audit($this->app, 'copilot.undo', 'copilot', (string) $id, (string) (isset($row['preview']['summary']) ? $row['preview']['summary'] : $row['action']), ['action' => [(string) $def['id'], null]]);

        return $this->result($this->mustFind($id), true, false, $message);
    }

    /**
     * La persona descarta la propuesta (no se hace nada).
     *
     * @return array<string,mixed>
     */
    public function discard(int $id): array
    {
        $row = $this->mine($id);
        if (in_array((string) $row['status'], ['proposed', 'incomplete'], true)) {
            $this->proposals->claim($id, (string) $row['status'], 'discarded');
        }

        return $this->mustFind($id);
    }

    // ------------------------------------------------------------------ para la pantalla

    /**
     * Una propuesta de quien ha entrado; si no es suya (o no existe), 404.
     *
     * @return array<string,mixed>
     *
     * @throws HttpError
     */
    public function mine(int $id): array
    {
        $row = $this->proposals->find($id);
        $user = $this->app->auth()->adminId();
        if ($row === null || $user === null || (int) $row['user_id'] !== $user) {
            throw HttpError::notFound();
        }

        return $row;
    }

    /**
     * Todo lo que necesita la pantalla para pintar una propuesta.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        $def = (string) $row['action'] !== '' ? $this->actions->get((string) $row['action']) : null;
        $preview = $row['preview'];
        $status = (string) $row['status'];
        $kinds = ['proposed' => 'info', 'incomplete' => 'warn', 'applied' => 'ok', 'undone' => '', 'discarded' => '', 'failed' => 'bad'];
        $labels = [
            'proposed' => t('Ready to apply'), 'incomplete' => t('Needs more details'), 'applied' => t('Applied'),
            'undone' => t('Undone'), 'discarded' => t('Discarded'), 'failed' => t('Not done'),
        ];
        $errors = isset($preview['errors']) && is_array($preview['errors']) ? $preview['errors'] : [];
        $missing = isset($preview['missing']) && is_array($preview['missing']) ? $preview['missing'] : [];
        $allowed = $def !== null && $this->actions->allowed($def, $row['args']);
        $fields = [];
        if ($def !== null) {
            foreach ($def['params'] as $param) {
                $name = (string) $param['name'];
                $options = [];
                if ($param['type'] === 'enum') {
                    foreach ($param['enum'] as $code) {
                        $options[(string) $code] = Params::display($this->app, $param, (string) $code);
                    }
                }
                $fields[] = [
                    'name' => $name, 'type' => (string) $param['type'], 'required' => (bool) $param['required'],
                    'label' => (string) $param['description'] !== '' ? t((string) $param['description']) : $name,
                    'value' => Params::toForm((string) $param['type'], array_key_exists($name, $row['args']) ? $row['args'][$name] : null),
                    'options' => $options, 'missing' => in_array($name, $missing, true),
                ];
            }
        }
        $alternatives = [];
        foreach (isset($preview['alternatives']) && is_array($preview['alternatives']) ? array_values($preview['alternatives']) : [] as $i => $alt) {
            $alternatives[] = ['index' => $i, 'label' => t((string) $alt['label'])];
        }

        return [
            'id' => (int) $row['id'], 'text' => (string) $row['text'], 'status' => $status,
            'status_label' => isset($labels[$status]) ? $labels[$status] : $status, 'status_kind' => isset($kinds[$status]) ? $kinds[$status] : '',
            'action' => (string) $row['action'], 'action_label' => $def !== null ? t((string) $def['label']) : '',
            'summary' => isset($preview['summary']) ? (string) $preview['summary'] : '',
            'changes' => isset($preview['changes']) && is_array($preview['changes']) ? $preview['changes'] : [],
            'count' => isset($preview['count']) ? (int) $preview['count'] : 0, 'more' => isset($preview['more']) ? (int) $preview['more'] : 0,
            'warnings' => isset($preview['warnings']) && is_array($preview['warnings']) ? $preview['warnings'] : [],
            'errors' => $errors,
            'choices' => isset($preview['choices']) && is_array($preview['choices']) ? $preview['choices'] : [],
            'links' => isset($preview['links']) && is_array($preview['links']) ? $preview['links'] : [],
            'missing' => $missing, 'fields' => $fields, 'alternatives' => $alternatives,
            'readonly' => $def !== null && (bool) $def['readonly'],
            'can_apply' => $status === 'proposed' && $def !== null && !$def['readonly'] && $errors === [] && $allowed,
            'can_undo' => $status === 'applied' && $row['undo'] !== null && $def !== null && $def['undo'] !== null && $allowed,
            'allowed' => $allowed, 'result' => (string) $row['result'], 'source' => (string) $row['source'],
            'created_ts' => (int) $row['created_ts'],
        ];
    }

    /**
     * Las frases de ejemplo de las acciones que hay, ya traducidas (las de un módulo apagado no salen).
     *
     * @return string[]
     */
    public function examples(): array
    {
        $out = [];
        foreach ($this->actions->all() as $def) {
            if ($def['readonly'] || !$this->app->auth()->can($this->actions->permission($def, []))) {
                continue;
            }
            foreach (array_slice($def['examples'], 0, 1) as $example) {
                $out[] = t($example);
            }
        }

        return array_slice($out, 0, 14);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{ok:bool,stale:bool,message:string,proposal:array<string,mixed>}
     */
    private function result(array $row, bool $ok, bool $stale, string $message): array
    {
        return ['ok' => $ok, 'stale' => $stale, 'message' => $message, 'proposal' => $row];
    }

    /**
     * @return array<string,mixed>
     */
    private function mustFind(int $id): array
    {
        $row = $this->proposals->find($id);
        if ($row === null) {
            throw new \LogicException('The proposal was just written and cannot be read: ' . $id);
        }

        return $row;
    }
}
