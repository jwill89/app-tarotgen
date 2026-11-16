<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Problem;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\NameTakenException;
use Tarot\Repository\SpreadCategoryRepository;
use Tarot\Structure\SpreadCategory;
use OpenApi\Spec as OAS;

/**
 * The spread library's categories: add, rename, reorder, remove.
 *
 * Removing one that still holds spreads is refused rather than moving them
 * somewhere: a silent bulk move can't be undone, and which category they
 * belong in instead is the admin's call, spread by spread. The last category
 * can't go either, since a spread with none named goes into the first.
 */
class AdminSpreadCategoryController extends AbstractController
{
    private const int NAME_MAX_LENGTH = 60;

    private const string NAME_REQUIRED = 'A category needs a name.';

    private const string NAME_TAKEN = 'A spread category with that name already exists.';

    public function __construct(
        private readonly SpreadCategoryRepository $categories,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/spread-categories',
        summary: 'List the spread library categories (uncached)',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Every category, in order',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadCategory'))
            ),
        ]
    )]
    public function list(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->categories->all());
    }

    #[OAS\Operation\Post(
        path: '/admin/spread-categories',
        summary: 'Add a category at the end of the list',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(description: 'Trimmed.', type: 'string', maxLength: self::NAME_MAX_LENGTH)),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The new category',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/SpreadCategory')
            ),
            new OAS\Response(response: 409, description: 'Another category has that name (case-insensitively)'),
            new OAS\Response(response: 422, description: 'A category needs a name, of at most 60 characters'),
        ]
    )]
    public function create(Request $request, Response $response): Response|ResponseInterface
    {
        $name = self::name($this->parsedBody($request));
        $refusal = self::refuseName($name);
        if ($refusal !== null) {
            return Problem::invalid($response, 'body.name', $refusal);
        }

        try {
            $category = $this->categories->create($name);
        } catch (NameTakenException) {
            return Problem::respond($response, 409, self::NAME_TAKEN);
        }

        if ($category === null) {
            return Problem::respond($response, 500, 'Failed to add the category.');
        }

        return $response->withJson($category, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/spread-categories/{category_id}',
        summary: 'Rename a category',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(description: 'Trimmed.', type: 'string', maxLength: self::NAME_MAX_LENGTH)),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The renamed category',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/SpreadCategory')
            ),
            new OAS\Response(response: 404, description: 'No such category'),
            new OAS\Response(response: 409, description: 'Another category has that name (case-insensitively)'),
            new OAS\Response(response: 422, description: 'A category needs a name, of at most 60 characters'),
        ]
    )]
    public function rename(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $name = self::name($this->parsedBody($request));
        $refusal = self::refuseName($name);
        if ($refusal !== null) {
            return Problem::invalid($response, 'body.name', $refusal);
        }

        try {
            $category = $this->categories->rename((int) ($args['category_id'] ?? 0), $name);
        } catch (NameTakenException) {
            return Problem::respond($response, 409, self::NAME_TAKEN);
        }

        if ($category === null) {
            return Problem::respond($response, 404, 'Category not found.');
        }

        return $response->withJson($category);
    }

    #[OAS\Operation\Put(
        path: '/admin/spread-categories/order',
        summary: 'Put the categories in a new order',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['category_ids'],
                properties: [
                    new OAS\Property(property: 'category_ids', schema: new OAS\Schema(description: 'Every category id, once, in the new order. The first becomes the default for new spreads.', type: 'array', items: new OAS\Schema\Items(type: 'integer'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Every category, in the new order',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadCategory'))
            ),
            new OAS\Response(response: 422, description: 'Not every category id exactly once'),
        ]
    )]
    public function reorder(Request $request, Response $response): Response|ResponseInterface
    {
        $ids = $this->parsedBody($request)['category_ids'] ?? null;
        $current = array_map(static fn(SpreadCategory $c): int => $c->category_id, $this->categories->all());

        $order = is_array($ids) && array_is_list($ids) ? self::ids($ids) : null;
        $sorted = $order ?? [];
        sort($sorted);
        sort($current);

        if ($order === null || $sorted !== $current) {
            return Problem::invalid($response, 'body.category_ids', 'Send every category id once, in the new order.');
        }

        $this->categories->reorder($order);

        return $response->withJson($this->categories->all());
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/spread-categories/{category_id}',
        summary: 'Remove a category that holds no spreads',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 404, description: 'No such category'),
            new OAS\Response(response: 409, description: 'It still holds spreads (move them first), or it is the last category'),
        ]
    )]
    public function delete(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $category = $this->categories->find((int) ($args['category_id'] ?? 0));

        if ($category === null) {
            return Problem::respond($response, 404, 'Category not found.');
        }

        if ($category->spread_count > 0) {
            [$spreads, $them] = $category->spread_count === 1
                ? ['1 spread', 'it']
                : ["{$category->spread_count} spreads", 'them'];
            return Problem::respond(
                $response,
                409,
                "\u{201C}{$category->name}\u{201D} still holds {$spreads}. Move {$them} to another category first."
            );
        }

        if (count($this->categories->all()) === 1) {
            return Problem::respond(
                $response,
                409,
                'Keep at least one category: a spread with none chosen goes into the first.'
            );
        }

        $this->categories->delete($category->category_id);

        return $response->withStatus(204);
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function name(array $params): string
    {
        $name = $params['name'] ?? null;

        return is_scalar($name) ? trim((string) $name) : '';
    }

    /** Why $name can't be a category's name; null when it can. */
    private static function refuseName(string $name): ?string
    {
        return match (true) {
            $name === ''                               => self::NAME_REQUIRED,
            mb_strlen($name) > self::NAME_MAX_LENGTH   => 'A category name can be at most ' . self::NAME_MAX_LENGTH . ' characters.',
            default                                    => null,
        };
    }

    /**
     * Positive integer ids, or null when anything else is in the list.
     *
     * @param list<mixed> $values
     * @return list<int>|null
     */
    private static function ids(array $values): ?array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                return null;
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }
}
