<?php

use App\Models\Product;
use App\Support\BaseUnit;
use App\Support\InventoryCosting;
use App\Support\InventoryType;

it('mengonversi berat ke gram', function () {
    expect(BaseUnit::toBaseQuantity('5', 'kg', BaseUnit::GRAM))->toBe('5000.0000')
        ->and(BaseUnit::toBaseQuantity('120.5', 'gram', BaseUnit::GRAM))->toBe('120.5000');
});

it('mengonversi volume ke mililiter', function () {
    expect(BaseUnit::toBaseQuantity('18', 'liter', BaseUnit::MILLILITER))->toBe('18000.0000')
        ->and(BaseUnit::toBaseQuantity('250', 'ml', BaseUnit::MILLILITER))->toBe('250.0000');
});

it('mengonversi biaya per satuan input ke biaya per satuan dasar', function () {
    expect(BaseUnit::toBaseUnitCost('20000', 'kg', BaseUnit::GRAM))->toBe('20.000000')
        ->and(BaseUnit::toBaseUnitCost('5000', 'liter', BaseUnit::MILLILITER))->toBe('5.000000');
});

it('menolak konversi lintas dimensi', function () {
    BaseUnit::toBaseQuantity('5', 'kg', BaseUnit::MILLILITER);
})->throws(InvalidArgumentException::class);

it('menghitung weighted average cost', function () {
    expect(InventoryCosting::weightedAverage('100', '10', '100', '20'))->toBe('15.000000')
        ->and(InventoryCosting::weightedAverage('0', '0', '25', '12.5'))->toBe('12.500000')
        ->and(InventoryCosting::weightedAverage('10', '7', '0', '99'))->toBe('7.000000');
});

it('menghitung HPP per satuan dan total HPP', function () {
    $unitCost = InventoryCosting::unitCostFromTotal('25000', '18000');

    expect($unitCost)->toBe('1.388889')
        ->and(InventoryCosting::extendedCost('200', $unitCost))->toBe('277.78')
        ->and(InventoryCosting::extendedCost('250', $unitCost))->toBe('347.22');
});

it('memberi default aman untuk produk lama', function () {
    $product = new Product;

    expect($product->inventoryType())->toBe(InventoryType::SELLABLE)
        ->and($product->baseUnit())->toBe(BaseUnit::PIECE)
        ->and($product->isSellable())->toBeTrue();
});

it('menentukan dimensi satuan dasar', function () {
    expect(BaseUnit::dimensionOf(BaseUnit::GRAM))->toBe(BaseUnit::DIMENSION_WEIGHT)
        ->and(BaseUnit::dimensionOf(BaseUnit::MILLILITER))->toBe(BaseUnit::DIMENSION_VOLUME)
        ->and(BaseUnit::dimensionOf(BaseUnit::PIECE))->toBe(BaseUnit::DIMENSION_UNIT);
});
