<?php

namespace MityDigital\CargoAustraliaPost\Fieldtypes;

use Closure;
use Statamic\Fields\Fieldtype;

class AustraliaPostServices extends Fieldtype
{
    protected static $title = 'Australia Post Services';

    protected $categories = ['special'];

    protected $indexComponent = 'text';

    public function icon(): string
    {
        return file_get_contents(__DIR__.'/../../resources/svg/australia-post.svg');
    }

    public function defaultValue(): array
    {
        return array_keys($this->services());
    }

    public function preload(): array
    {
        return ['groups' => collect(['domestic' => 'Domestic', 'international' => 'International'])
            ->map(fn (string $label, string $handle): array => [
                'handle' => $handle,
                'label' => $label,
                'options' => collect(config('cargo-australia-post.service_codes.'.$handle, []))
                    ->map(fn (string $label, string $code): array => ['value' => $code, 'label' => $label])
                    ->values()->all(),
            ])->values()->all()];
    }

    public function preProcess(mixed $data): array
    {
        return $data === null ? $this->defaultValue() : (is_array($data) ? array_values($data) : []);
    }

    public function process(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $selected = array_values(array_unique($data));
        $codes = $this->defaultValue();

        if ($selected !== [] && count($selected) === count($codes) && array_diff($selected, $codes) === []) {
            return null;
        }

        return [...array_values(array_intersect($codes, $selected)), ...array_values(array_diff($selected, $codes))];
    }

    public function rules(): array
    {
        return ['nullable', 'array', 'list'];
    }

    public function extraRules(): array
    {
        return [$this->field()->handle().'.*' => [
            'required',
            'string',
            'distinct',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! array_key_exists($value, $this->services())) {
                    $fail('Select only configured Australia Post services. Untick any services that are no longer configured.');
                }
            },
        ]];
    }

    public function preProcessIndex(mixed $data): string
    {
        if ($data === null) {
            return 'All configured services';
        }

        if ($data === []) {
            return 'No Australia Post services';
        }

        $services = $this->services();

        return implode(', ', array_map(fn (string $code): string => $services[$code] ?? $code.' (not configured)', $data));
    }

    /** @return array<string, string> */
    protected function services(): array
    {
        return array_merge(config('cargo-australia-post.service_codes.domestic', []), config('cargo-australia-post.service_codes.international', []));
    }
}
