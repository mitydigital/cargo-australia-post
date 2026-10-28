<?php

use MityDigital\CargoAustraliaPost\Fieldtypes\AustraliaPostServices;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

beforeEach(function (): void {
    config()->set('cargo-australia-post.service_codes.domestic', ['REGULAR' => 'Parcel Post', 'EXPRESS' => 'Express Post']);
    config()->set('cargo-australia-post.service_codes.international', ['INT_STANDARD' => 'Standard']);
});

it('registers the fieldtype and provides grouped options from config', function (): void {
    $field = new Field('australia_post_services', ['type' => 'australia_post_services']);

    expect($field->fieldtype())->toBeInstanceOf(AustraliaPostServices::class)
        ->and($field->fieldtype()->preload())->toBe(['groups' => [
            ['handle' => 'domestic', 'label' => 'Domestic', 'options' => [
                ['value' => 'REGULAR', 'label' => 'Parcel Post'],
                ['value' => 'EXPRESS', 'label' => 'Express Post'],
            ]],
            ['handle' => 'international', 'label' => 'International', 'options' => [
                ['value' => 'INT_STANDARD', 'label' => 'Standard'],
            ]],
        ]]);
});

it('defaults missing and null values to all currently configured services', function (): void {
    $fields = new Fields([['handle' => 'australia_post_services', 'field' => ['type' => 'australia_post_services']]]);

    expect($fields->addValues([])->preProcess()->values()->get('australia_post_services'))->toBe(['REGULAR', 'EXPRESS', 'INT_STANDARD'])
        ->and($fields->addValues(['australia_post_services' => null])->preProcess()->values()->get('australia_post_services'))->toBe(['REGULAR', 'EXPRESS', 'INT_STANDARD']);

    config()->set('cargo-australia-post.service_codes.domestic.NEW', 'New service');

    expect($fields->addValues([])->preProcess()->values()->get('australia_post_services'))->toBe(['REGULAR', 'EXPRESS', 'NEW', 'INT_STANDARD']);
});

it('saves and reloads product selections without confusing none with the default', function (mixed $submitted, mixed $stored, array $displayed): void {
    $fields = (new Fields([['handle' => 'australia_post_services', 'field' => ['type' => 'australia_post_services']]]))
        ->addValues(['australia_post_services' => $submitted]);
    $fields->validate();
    $value = $fields->process()->values()->get('australia_post_services');
    $cart = $this->cartWithProducts([['data' => ['australia_post_services' => $value]]]);
    $product = $cart->lineItems()->first()->product();
    $reloaded = $fields->addValues(['australia_post_services' => $product->get('australia_post_services')])->preProcess();

    expect($product->get('australia_post_services'))->toBe($stored)
        ->and($reloaded->values()->get('australia_post_services'))->toBe($displayed)
        ->and($reloaded->process()->values()->get('australia_post_services'))->toBe($stored);
})->with([
    'inherit all' => [null, null, ['REGULAR', 'EXPRESS', 'INT_STANDARD']],
    'restore all in any order' => [['INT_STANDARD', 'EXPRESS', 'REGULAR'], null, ['REGULAR', 'EXPRESS', 'INT_STANDARD']],
    'restricted' => [['INT_STANDARD', 'REGULAR'], ['REGULAR', 'INT_STANDARD'], ['REGULAR', 'INT_STANDARD']],
    'none' => [[], [], []],
]);

it('does not add newly configured services to an explicit product selection', function (): void {
    $field = (new Field('australia_post_services', ['type' => 'australia_post_services']))->setValue(['REGULAR']);
    config()->set('cargo-australia-post.service_codes.domestic.NEW', 'New service');
    config()->set('cargo-australia-post.service_codes.domestic.REGULAR', 'Renamed');

    expect($field->preProcess()->value())->toBe(['REGULAR'])
        ->and($field->process()->value())->toBe(['REGULAR'])
        ->and($field->preProcessIndex()->value())->toBe('Renamed');
});

it('preserves removed codes for review rather than silently restoring unrestricted shipping', function (): void {
    $field = (new Field('australia_post_services', ['type' => 'australia_post_services']))->setValue(['REMOVED']);

    expect($field->preProcess()->value())->toBe(['REMOVED'])
        ->and($field->process()->value())->toBe(['REMOVED'])
        ->and($field->preProcessIndex()->value())->toBe('REMOVED (not configured)');

    $validator = (new Fields([['handle' => 'australia_post_services', 'field' => ['type' => 'australia_post_services']]]))
        ->addValues(['australia_post_services' => ['REMOVED']])->validator()->validator();

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('australia_post_services.0'))->toBe('Select only configured Australia Post services. Untick any services that are no longer configured.');
});

it('rejects malformed or unconfigured selections through publish field validation', function (mixed $value, string $errorKey): void {
    $validator = (new Fields([['handle' => 'australia_post_services', 'field' => ['type' => 'australia_post_services']]]))
        ->addValues(['australia_post_services' => $value])->validator()->validator();

    expect($validator->fails())->toBeTrue()->and($validator->errors()->has($errorKey))->toBeTrue();
})->with([
    'scalar' => ['REGULAR', 'australia_post_services'],
    'boolean' => [true, 'australia_post_services'],
    'associative' => [['code' => 'REGULAR'], 'australia_post_services'],
    'unknown code' => [['UNKNOWN'], 'australia_post_services.0'],
    'null code' => [[null], 'australia_post_services.0'],
    'non-string code' => [[123], 'australia_post_services.0'],
    'duplicate code' => [['REGULAR', 'REGULAR'], 'australia_post_services.0'],
]);

it('keeps an empty selection explicit when no services are configured', function (): void {
    config()->set('cargo-australia-post.service_codes.domestic', []);
    config()->set('cargo-australia-post.service_codes.international', []);
    $field = (new Field('australia_post_services', ['type' => 'australia_post_services']))->setValue([]);

    expect($field->process()->value())->toBe([])
        ->and($field->preProcessIndex()->value())->toBe('No Australia Post services')
        ->and($field->fieldtype()->preload()['groups'][0]['options'])->toBe([])
        ->and($field->fieldtype()->preload()['groups'][1]['options'])->toBe([]);
});
