<?php

/**
 * This file is part of milpa/live-web.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-web
 */

declare(strict_types=1);

namespace Milpa\Live\Components;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\ComponentPresentation;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Readable entries — the smallest declaration that lets a house paint narrative content
 * (greenhouse decisions/0464).
 *
 * It knows nothing of what the rows ARE. It knows ROLES: which field is read as the title, which as the
 * body, and optionally which as the lead and which as metadata. The same declaration paints an article,
 * a notice, a changelog entry or a help page, and a new kind of row needs no new code — that is the
 * test this primitive exists to pass. `rows` is the prop a screen binding fills, so a declared screen
 * can bind this to an entity's public rows exactly as it binds a table.
 *
 * The body is plain text: a blank line separates paragraphs and everything is escaped. A body that could
 * carry markup is a separate security question, not a smaller one.
 */
final class ContentComponent implements ComponentDefinitionInterface
{
    /** The roles a declaration may name; `title` and `body` are the two a readable entry cannot lack. */
    public const ROLES = ['title', 'lead', 'body', 'meta'];

    /** The contract: rows and the roles that read them; no actions — reading needs no round trip. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'content',
            contractVersion: '1',
            summary: 'Readable entries: each row painted as an article from the fields its roles name.',
            propsSchema: [
                'rows' => ['type' => 'array', 'default' => [], 'description' => 'The entries, one object per entry; a screen binding fills it'],
                'roles' => ['type' => 'object', 'required' => true, 'description' => 'Which field each role reads: {title, body, lead?, meta?: [fields]}'],
                'heading' => ['type' => 'string', 'required' => false, 'description' => 'One heading above every entry, when the page needs one'],
            ],
            stateSchema: [
                'entries' => ['type' => 'integer'],
            ],
            actions: [],
            presentation: new ComponentPresentation(
                styles: \dirname(__DIR__, 2) . '/resources/components/content.css',
                messages: \dirname(__DIR__, 2) . '/resources/messages/content.php',
            ),
        );
    }

    /** Mounts after checking the roles; a declaration that names no title or body is refused here. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $roles = self::roles($props['roles'] ?? null);
        $rows = self::rows($props['rows'] ?? [], $roles);

        return new StateSnapshot(
            $context->componentId,
            'content',
            '1',
            ['entries' => \count($rows)],
            [
                'roles' => $roles,
                'heading' => \is_string($props['heading'] ?? null) ? $props['heading'] : '',
            ],
        );
    }

    /** Nothing to do: an entry is read, not operated. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }

    /**
     * The roles, checked: `title` and `body` name a field, `lead` may, `meta` is a list of fields.
     *
     * @return array{title: string, body: string, lead: ?string, meta: list<string>}
     */
    public static function roles(mixed $roles): array
    {
        if (! \is_array($roles) || ($roles !== [] && array_is_list($roles))) {
            throw new InvalidComponentProps('props.roles', 'roles must be an object: {title, body, lead?, meta?}');
        }
        foreach (array_keys($roles) as $role) {
            if (! \in_array($role, self::ROLES, true)) {
                throw new InvalidComponentProps('props.roles.' . $role, "unknown role «{$role}»; the roles are: " . implode(', ', self::ROLES));
            }
        }
        foreach (['title', 'body'] as $required) {
            if (! \is_string($roles[$required] ?? null) || trim($roles[$required]) === '') {
                throw new InvalidComponentProps('props.roles.' . $required, "roles.{$required} must name the field an entry is read from");
            }
        }
        $lead = $roles['lead'] ?? null;
        if ($lead !== null && (! \is_string($lead) || trim($lead) === '')) {
            throw new InvalidComponentProps('props.roles.lead', 'roles.lead, when given, names one field');
        }
        $meta = $roles['meta'] ?? [];
        if (! \is_array($meta) || ! array_is_list($meta) || array_filter($meta, static fn ($field): bool => ! \is_string($field) || $field === '') !== []) {
            throw new InvalidComponentProps('props.roles.meta', 'roles.meta, when given, is a list of fields');
        }

        return ['title' => $roles['title'], 'body' => $roles['body'], 'lead' => $lead, 'meta' => $meta];
    }

    /**
     * The rows, checked against the roles: a role that names a field the rows do not carry is refused,
     * because an entry painted without its title reads as a broken page and not as a wrong declaration.
     *
     * @param array{title: string, body: string, lead: ?string, meta: list<string>} $roles
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $rows, array $roles): array
    {
        if (! \is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidComponentProps('props.rows', 'rows must be a list of entries');
        }
        $named = array_filter([$roles['title'], $roles['body'], $roles['lead'], ...$roles['meta']], static fn ($field): bool => $field !== null);
        foreach ($rows as $i => $row) {
            if (! \is_array($row)) {
                throw new InvalidComponentProps("props.rows.{$i}", 'each entry must be an object');
            }
            foreach ($named as $field) {
                if (! \array_key_exists($field, $row)) {
                    throw new InvalidComponentProps(
                        "props.rows.{$i}",
                        "the roles name «{$field}», which this entry does not carry; it carries: " . implode(', ', array_map('strval', array_keys($row))),
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * A body as paragraphs: a blank line separates them, and single line breaks are kept within one.
     *
     * @return list<list<string>> paragraphs, each a list of lines
     */
    public static function paragraphs(mixed $body): array
    {
        $text = \is_scalar($body) ? trim((string) $body) : '';
        if ($text === '') {
            return [];
        }

        return array_values(array_map(
            static fn (string $paragraph): array => array_map('trim', preg_split('/\R/', trim($paragraph)) ?: []),
            array_filter(preg_split('/\R\s*\R/', $text) ?: [], static fn (string $paragraph): bool => trim($paragraph) !== ''),
        ));
    }
}
