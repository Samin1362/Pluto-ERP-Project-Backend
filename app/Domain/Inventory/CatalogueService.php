<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Models\ProductCategory;
use App\Models\StockGroup;
use App\Models\Unit;
use App\Support\Problem\ApiProblem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inventory masters that are simple named records (backlog P3.1): units of measure, and the two trees: product
 * categories (D5, at most two levels) and stock groups (D6, a reporting hierarchy of any depth, GAP-20).
 */
final class CatalogueService
{
    /** Deepest level per tree (null = no limit). D5: "two-level category tree". */
    private const MAX_DEPTH = [ProductCategory::class => 2, StockGroup::class => null];

    private const AUDITED = ['name', 'parent_id', 'code_prefix', 'default_unit_id', 'default_stock_group_id', 'sort_order'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** POST /units: D2 "Add unit" inline (FR-033). The name is unique per company (validated by UnitRequest). */
    public function createUnit(array $data): Unit
    {
        return DB::transaction(function () use ($data) {
            $unit = Unit::create(['name' => $data['name'], 'decimals' => $data['decimals'] ?? 0]);
            $this->audit->record('created', $unit, null, $unit->only(['name', 'decimals']));

            return $unit;
        });
    }

    /**
     * POST /product-categories, /stock-groups.
     *
     * @param  class-string<ProductCategory|StockGroup>  $class
     */
    public function createNode(string $class, array $data): Model
    {
        return DB::transaction(function () use ($class, $data) {
            $node = new $class(['sort_order' => 0]);
            $node->fill($data);
            $this->checkParent($node, $data['parent_id'] ?? null);
            $node->save();
            $this->audit->record('created', $node, null, $this->audited($node));

            return $node;
        });
    }

    /** PATCH /product-categories/{id}, /stock-groups/{id}: partial; a new parent must keep the tree valid. */
    public function updateNode(Model $node, array $data): Model
    {
        return DB::transaction(function () use ($node, $data) {
            $node = $node::whereKey($node->getKey())->lockForUpdate()->firstOrFail();
            $old = $this->audited($node);
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $node->parent_id) {
                $this->checkParent($node, $data['parent_id']);
            }
            $node->fill($data)->save();
            $new = $this->audited($node);
            if ($old !== $new) {
                $this->audit->record('updated', $node, array_diff_assoc($old, $new), array_diff_assoc($new, $old));
            }

            return $node;
        });
    }

    /** DELETE: only an unused node (BR-026): no products and no sub-nodes; otherwise 409 has_history. */
    public function deleteNode(Model $node): void
    {
        DB::transaction(function () use ($node) {
            $node = $node::whereKey($node->getKey())->lockForUpdate()->firstOrFail();
            [$noun, $nouns] = $this->nouns($node);
            if (($products = $node->products()->count()) > 0) {
                throw new ApiProblem('has_history', "This {$noun} has {$products} ".($products === 1 ? 'product' : 'products').'; move them to another one first.');
            }
            if ($node->children()->exists()) {
                throw new ApiProblem('has_history', "This {$noun} has sub-{$nouns}; move or delete them first.");
            }
            $old = $this->audited($node);
            $node->delete();
            $this->audit->record('deleted', $node, $old);
        });
    }

    /** A parent must not be the node itself or one of its descendants, and the tree may not get deeper than allowed. */
    private function checkParent(Model $node, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        [$noun, $nouns] = $this->nouns($node);
        $class = $node::class;
        $depth = 1; // levels above the node once it sits under $parentId
        for ($id = $parentId; $id !== null; $id = $class::whereKey($id)->value('parent_id')) {
            if ($node->exists && $id === $node->getKey()) {
                throw new ApiProblem('validation_failed', errors: ['parent_id' => [$parentId === $node->getKey()
                    ? "A {$noun} cannot be its own parent" : "A {$noun} cannot move under one of its own sub-{$nouns}"]]);
            }
            $depth++;
        }
        $max = self::MAX_DEPTH[$class];
        if ($max !== null && $depth - 1 + $this->height($node) > $max) {
            throw new ApiProblem('validation_failed', errors: ['parent_id' => [$this->height($node) > 1
                ? "This {$noun} has sub-{$nouns}, so it must stay at the top level"
                : "Categories have at most {$max} levels; choose a top-level parent"]]);
        }
    }

    /** Levels in the node's own subtree (1 for a leaf or a new node). */
    private function height(Model $node): int
    {
        if (! $node->exists) {
            return 1;
        }
        $ids = [$node->getKey()];
        $levels = 0;
        while ($ids) {
            $levels++;
            $ids = $node::whereIn('parent_id', $ids)->pluck('id')->all();
        }

        return $levels;
    }

    /** @return array{string, string} singular and plural noun for messages */
    private function nouns(Model $node): array
    {
        return $node instanceof ProductCategory ? ['category', 'categories'] : ['stock group', 'stock groups'];
    }

    private function audited(Model $node): array
    {
        return array_intersect_key($node->only(self::AUDITED), array_flip(array_keys($node->getAttributes())));
    }
}
