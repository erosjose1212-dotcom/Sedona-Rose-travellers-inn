<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosItem extends Model
{
    protected $table = 'pos_items';

    protected $fillable = [
        'name', 'category', 'price',
        'stock_quantity', 'is_tracked', 'reorder_level',
        'kitchen_hours_only', 'is_available', 'sort_order', 'is_retired',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_tracked' => 'boolean',
        'reorder_level' => 'integer',
        'kitchen_hours_only' => 'boolean',
        'is_available' => 'boolean',
        'is_retired' => 'boolean',
    ];

    protected static ?PosItem $cachedEgg = null;

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Determine if this item is the Master Egg item (Egg (Fried/Boiled)).
     */
    public function isEggItem(): bool
    {
        $name = strtolower(trim($this->name ?? ''));

        return $name === 'egg (fried/boiled)'
            || $name === 'egg'
            || str_starts_with($name, 'egg (')
            || (str_contains($name, 'egg') && strtolower(trim($this->category ?? '')) === 'kitchen extras');
    }

    /**
     * Determine if this item is a breakfast meal (Silog or Breakfast category).
     * Each breakfast item now has its OWN independent stock (not pooled with egg).
     * However, ordering one still triggers a relational egg deduction via requiresEgg().
     */
    public function isBreakfastItem(): bool
    {
        if ($this->isEggItem()) {
            return false;
        }

        $category = strtolower(trim($this->category ?? ''));
        $name = strtolower(trim($this->name ?? ''));

        return $category === 'breakfast' || str_contains($name, 'silog');
    }

    /**
     * Determine if ordering this dish also deducts from the raw egg pantry stock.
     * Covers: all silog/breakfast meals, Calamares (egg batter), Sizzling Sisig w/ Egg.
     */
    public function requiresEgg(): bool
    {
        if ($this->isEggItem()) {
            return false;
        }

        $name = strtolower(trim($this->name ?? ''));

        if (str_contains($name, 'silog') || strtolower(trim($this->category ?? '')) === 'breakfast') {
            return true;
        }

        if (str_contains($name, 'w/ egg') || str_contains($name, 'w/egg') || str_contains($name, 'with egg')) {
            return true;
        }

        if (str_contains($name, 'calamares')) {
            return true;
        }

        return false;
    }

    /**
     * Number of raw eggs consumed per serving of this dish (default: 1).
     */
    public function getEggRequirementQty(): int
    {
        return 1;
    }

    /**
     * Retrieve the master egg item used for relational deductions.
     */
    public static function getMasterEggItem(): ?self
    {
        return static::where(function ($query) {
            $query->where('name', 'Egg (Fried/Boiled)')
                ->orWhere('name', 'like', '%Egg (Fried/Boiled)%')
                ->orWhere(function ($q) {
                    $q->where('name', 'like', '%Egg%')->where('category', 'Kitchen Extras');
                });
        })
            ->orderBy('id')
            ->first();
    }

    /**
     * Clear master egg cached instance.
     */
    public static function clearEggCache(): void
    {
        static::$cachedEgg = null;
    }

    /**
     * Dynamically resolve item availability.
     * For egg-dependent dishes: unavailable if the dish itself is out OR eggs are exhausted.
     * Each dish has its own is_available flag independent of other dishes.
     */
    public function getIsAvailableAttribute(mixed $value): bool
    {
        if ($this->is_retired || ! (bool) $value) {
            return false;
        }

        if ($this->requiresEgg()) {
            $egg = static::getMasterEggItem();
            if ($egg && $egg->id !== $this->id) {
                $eggStock = (int) $egg->getRawOriginal('stock_quantity', 0);
                $eggAvailable = (bool) $egg->getRawOriginal('is_available', true);
                if ($egg->is_retired || ! $eggAvailable || ($egg->is_tracked && $eggStock <= 0)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Kitchen dishes are orderable around the clock when stock and ingredients are available.
     */
    public function isOrderable(): bool
    {
        return $this->is_available && (! $this->is_tracked || $this->stock_quantity > 0);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->notRetired()->where('is_available', true)->orderBy('sort_order');
    }

    public function scopeNotRetired(Builder $query): Builder
    {
        return $query->where('is_retired', false);
    }

    public function scopeInStockListOrder(Builder $query): Builder
    {
        $orderedNames = [
            ['bangsilog'],
            ['chicksilog'],
            ['hotsilog'],
            ['longsilog'],
            ['porksilog'],
            ['tapsilog'],
            ['buffalo wings'],
            ['buttered chicken'],
            ['chicharong bulaklak', 'chicharon bulaklak'],
            ['calamares'],
            ['french fries', 'fries'],
            ['lechon kawali'],
            ['garlic chicken'],
            ["tokwa't baboy", 'tokwat baboy'],
            ['pancit canton'],
            ['lomi'],
            ['sizzling hotdog'],
            ['sizzling tofu'],
            ['sizzling sisig w/ egg', 'sizzling sisig w egg', 'sizzling sisig with egg'],
            ['egg (fried/boiled)', 'egg', 'eggs'],
        ];
        $cases = [];
        $bindings = [];
        foreach ($orderedNames as $position => $names) {
            foreach ($names as $name) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $name, $position);
            }
        }
        $bindings[] = count($orderedNames);

        return $query->orderByRaw('CASE LOWER(TRIM(name)) '.implode(' ', $cases).' ELSE ? END', $bindings)
            ->orderBy('category')->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->notRetired()->where('category', $category);
    }
}
