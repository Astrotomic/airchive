<?php

namespace Tests\Unit\ValueObjects;

use App\Collections\FluentCollection;
use App\ValueObjects\Fluent;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class FluentTest extends UnitTestCase
{
    public function test_it_builds_from_arrays_and_json_and_rejects_other_input(): void
    {
        Assert::assertSame('value', Fluent::from(['key' => 'value'])->nullString('key'));
        Assert::assertSame('value', Fluent::from('{"key":"value"}')->nullString('key'));
        Assert::assertNull(Fluent::tryFrom('{'));
        Assert::assertNull(Fluent::tryFrom(123));
    }

    public function test_it_reads_untrusted_values_without_coercing_their_types(): void
    {
        $data = Fluent::from([
            'string' => ' value ',
            'empty' => ' ',
            'number' => 123,
            'integer' => '42',
            'invalid_integer' => '4.2',
            'array' => ['key' => 'value'],
        ]);

        Assert::assertSame('value', $data->nullString('string'));
        Assert::assertNull($data->nullString('empty'));
        Assert::assertNull($data->nullString('number'));
        Assert::assertSame('123', $data->scalarString('number'));
        Assert::assertSame(42, $data->nullInteger('integer'));
        Assert::assertNull($data->nullInteger('invalid_integer'));
        Assert::assertSame(['key' => 'value'], $data->nullArray('array'));
        Assert::assertNull($data->nullArray('string'));
    }

    public function test_it_wraps_nested_records_and_can_preserve_source_keys(): void
    {
        $data = Fluent::from([
            'record' => ['name' => 'first'],
            'records' => [
                'first-id' => ['name' => 'first'],
                'invalid' => 'skip',
                'second-id' => ['name' => 'second'],
            ],
        ]);

        Assert::assertSame('first', $data->fluent('record')->nullString('name'));

        $records = $data->collectFluent('records');

        Assert::assertInstanceOf(FluentCollection::class, $records);
        Assert::assertSame(['first-id', 'second-id'], $records->keys()->all());
        Assert::assertSame(['first', 'second'], $records->map(
            fn (Fluent $record): ?string => $record->nullString('name'),
        )->values()->all());
    }
}
